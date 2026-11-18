<?php

declare(strict_types=1);

/*
 * This file is part of Vector WordPress plugin, a fork of Ymir WordPress plugin.
 *
 * Added by Vector (2026). See README.md for a summary of changes.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\Tests\Unit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Ymir\Plugin\CloudProvider\Aws\SesClient;
use Ymir\Plugin\DependencyInjection\Container;
use Ymir\Plugin\Email\Email;
use Ymir\Plugin\Email\SesConfigurationSet;
use Ymir\Plugin\EventManagement\EventManager;
use Ymir\Plugin\Plugin;
use Ymir\Plugin\Tests\Mock\AttachmentFileManagerMockTrait;
use Ymir\Plugin\Tests\Mock\HttpClientMockTrait;

/**
 * Sends through the "wp_mail" in pluggable.php with a real Email and SesClient,
 * mocking only the HTTP client that would call SES.
 *
 * Each test runs in its own process so pluggable.php, which needs the "$ymir"
 * global, never joins the included files other separate-process tests reload.
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
class PluggableWpMailTest extends TestCase
{
    use AttachmentFileManagerMockTrait;
    use HttpClientMockTrait;

    /**
     * The WP_Error objects passed to "wp_mail_failed".
     *
     * @var array
     */
    private $failures;

    /**
     * The mocked HTTP client.
     *
     * @var MockObject
     */
    private $http;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->failures = [];
        $this->http = $this->getHttpClientMock();

        $email = function () {
            return new Email(new EventManager(), 'wordpress@example.com', $this->getAttachmentFileManagerMock(), new PHPMailer(true));
        };

        $plugin = $this->getMockBuilder(Plugin::class)
                       ->disableOriginalConstructor()
                       ->getMock();
        $plugin->method('isEmailSendingEnabled')->willReturn(true);
        $plugin->method('isUsingVanityDomain')->willReturn(false);
        $plugin->method('getContainer')->willReturn(new Container([
            'email_client' => new SesClient($this->http, 'aws-key', 'us-east-1', 'aws-secret'),
            'email' => $email,
        ]));

        $GLOBALS['ymir'] = $plugin;
        $GLOBALS['pagenow'] = 'index.php';

        require_once dirname(__DIR__, 2).'/pluggable.php';

        $this->assertTrue(function_exists('wp_mail'));

        add_action('wp_mail_failed', function ($error) {
            $this->failures[] = $error;
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        putenv(SesConfigurationSet::ENV);
        remove_all_actions('wp_mail_failed');
        remove_all_actions('phpmailer_init');
        remove_all_filters('wp_mail');
        unset($GLOBALS['ymir'], $GLOBALS['pagenow']);
    }

    /**
     * Values that must be refused.
     */
    public static function invalidValues(): array
    {
        return [
            'empty' => [''],
            'space' => ['vector site'],
            'too long' => [str_repeat('a', 65)],
            'header injection' => ["vector-site\r\nBcc: someone@example.com"],
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testRefusesInvalidValue(string $value): void
    {
        putenv(SesConfigurationSet::ENV.'='.$value);

        $this->assertRefused($value);
    }

    public function testRefusesInvalidValueWithSiteHeader(): void
    {
        putenv(SesConfigurationSet::ENV.'=not valid');

        $this->assertRefused('not valid', ['X-SES-CONFIGURATION-SET: spoofed']);
    }

    public function testRefusesWhenMissing(): void
    {
        putenv(SesConfigurationSet::ENV);

        $this->assertRefused();
    }

    public function testReplacesSiteHeaders(): void
    {
        putenv(SesConfigurationSet::ENV.'=vector-site');

        add_filter('wp_mail', function (array $attributes) {
            $attributes['headers'][] = 'X-Ses-Configuration-Set: from-filter';

            return $attributes;
        });
        add_action('phpmailer_init', function (PHPMailer $mailer) {
            $mailer->addCustomHeader('x-ses-configuration-set', 'from-phpmailer-init');
        });

        $message = $this->sendExpectingSesCall(['X-SES-CONFIGURATION-SET: from-headers', 'X-Foo: bar']);

        $this->assertSame(['X-SES-CONFIGURATION-SET: vector-site'], $this->configurationSetHeaders($message));
        $this->assertStringContainsString("\r\nx-foo: bar\r\n", $message);
        $this->assertSame([], $this->failures);
    }

    public function testSendsWithOneHeader(): void
    {
        putenv(SesConfigurationSet::ENV.'=vector-site');

        $message = $this->sendExpectingSesCall();

        $this->assertSame(['X-SES-CONFIGURATION-SET: vector-site'], $this->configurationSetHeaders($message));
        $this->assertSame([], $this->failures);
    }

    /**
     * Assert that "wp_mail" refuses the send without calling SES.
     */
    private function assertRefused(?string $value = null, array $headers = []): void
    {
        $this->http->expects($this->never())->method('request');

        $this->assertFalse(wp_mail('to@example.com', 'Subject', 'Body', $headers));

        $this->assertCount(1, $this->failures);
        $this->assertInstanceOf(\WP_Error::class, $this->failures[0]);
        $this->assertSame('wp_mail_failed', $this->failures[0]->get_error_code());
        $this->assertSame('Email not sent: the VECTOR_SES_CONFIGURATION_SET environment variable is missing or isn\'t a valid SES configuration set name', $this->failures[0]->get_error_message());

        if (null !== $value && '' !== $value) {
            $this->assertStringNotContainsString($value, $this->failures[0]->get_error_message());
        }
    }

    /**
     * Get the X-SES-CONFIGURATION-SET lines in the message's header block.
     */
    private function configurationSetHeaders(string $message): array
    {
        $headerBlock = explode("\r\n\r\n", $message, 2)[0];

        return array_values(preg_grep('/^x-ses-configuration-set\s*:/i', explode("\r\n", $headerBlock)));
    }

    /**
     * Send through "wp_mail" and return the raw message sent to SES.
     */
    private function sendExpectingSesCall(array $headers = []): string
    {
        $body = '';

        $this->http->expects($this->once())
                   ->method('request')
                   ->willReturnCallback(function (string $url, array $options) use (&$body) {
                       $body = $options['body'];

                       return ['response' => ['code' => 200]];
                   });

        $this->assertTrue(wp_mail('to@example.com', 'Subject', 'Body', $headers));

        parse_str($body, $query);

        $this->assertSame('SendRawEmail', $query['Action']);

        return base64_decode($query['RawMessage_Data'], true);
    }
}
