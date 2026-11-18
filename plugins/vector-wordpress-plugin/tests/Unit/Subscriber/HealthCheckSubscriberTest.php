<?php

declare(strict_types=1);

/*
 * This file is part of Ymir WordPress plugin.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 * Modified by Vector (2026). See README.md for a summary of changes.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\Tests\Unit\Subscriber;

use Ymir\Plugin\EventManagement\WordPressAwareInterface;
use Ymir\Plugin\Subscriber\HealthCheckSubscriber;
use Ymir\Plugin\Tests\Mock\EventManagerMockTrait;
use Ymir\Plugin\Tests\Mock\FunctionMockTrait;
use Ymir\Plugin\Tests\Unit\TestCase;

class HealthCheckSubscriberTest extends TestCase
{
    use EventManagerMockTrait;
    use FunctionMockTrait;

    /**
     * A valid build ID.
     */
    private const BUILD_ID = '01JD3V7Q8M2X4Y6Z9ABCDEFGHK';

    /**
     * The temporary directory standing in for the WordPress root.
     *
     * @var string
     */
    private $buildDirectory;

    /**
     * The path of the build file in the temporary directory.
     *
     * @var string
     */
    private $buildFilePath;

    /**
     * The server parameters before the test.
     *
     * @var array
     */
    private $server;

    /**
     * The subscriber used by the test.
     *
     * @var HealthCheckSubscriber
     */
    private $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDirectory = sys_get_temp_dir().'/vector-health-'.bin2hex(random_bytes(8));
        mkdir($this->buildDirectory, 0700);
        $this->buildFilePath = $this->buildDirectory.'/vector-build.php';

        $this->server = $_SERVER;
        $this->subscriber = new HealthCheckSubscriber($this->buildFilePath);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/ymir/health';
        $_SERVER['YMIR_HEALTH_CHECK'] = '1';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        if (is_file($this->buildFilePath)) {
            unlink($this->buildFilePath);
        }

        rmdir($this->buildDirectory);

        parent::tearDown();
    }

    public function provideInvalidBuildFiles(): array
    {
        return [
            'not an array' => ["<?php return '".self::BUILD_ID."';"],
            'no build_id' => ["<?php return ['id' => '".self::BUILD_ID."'];"],
            'build_id not a string' => ['<?php return [\'build_id\' => 12345678901234567890123456];'],
            'lowercase build_id' => ["<?php return ['build_id' => '".strtolower(self::BUILD_ID)."'];"],
            'build_id with excluded letter' => ["<?php return ['build_id' => '01JD3V7Q8M2X4Y6Z9ABCDEFGHU'];"],
            'build_id too short' => ["<?php return ['build_id' => '".substr(self::BUILD_ID, 1)."'];"],
            'build_id with trailing newline' => ["<?php return ['build_id' => \"".self::BUILD_ID.'\\n"];'],
            'no return' => ['<?php'],
            'empty file' => [''],
        ];
    }

    public function provideUnauthorizedServerParameters(): array
    {
        return [
            'missing marker' => [[], ['YMIR_HEALTH_CHECK']],
            'marker set to "0"' => [['YMIR_HEALTH_CHECK' => '0'], []],
            'marker set to "true"' => [['YMIR_HEALTH_CHECK' => 'true'], []],
            'marker set to boolean true' => [['YMIR_HEALTH_CHECK' => true], []],
            'marker set to integer 1' => [['YMIR_HEALTH_CHECK' => 1], []],
            'marker set with whitespace' => [['YMIR_HEALTH_CHECK' => '1 '], []],
            'marker only sent as an HTTP header' => [['HTTP_YMIR_HEALTH_CHECK' => '1'], ['YMIR_HEALTH_CHECK']],
            'POST request' => [['REQUEST_METHOD' => 'POST'], []],
            'HEAD request' => [['REQUEST_METHOD' => 'HEAD'], []],
            'missing request method' => [[], ['REQUEST_METHOD']],
            'missing request uri' => [[], ['REQUEST_URI']],
        ];
    }

    public function testGetSubscribedEvents(): void
    {
        $callbacks = HealthCheckSubscriber::getSubscribedEvents();

        foreach ($callbacks as $callback) {
            $this->assertTrue(method_exists(HealthCheckSubscriber::class, is_array($callback) ? $callback[0] : $callback));
        }

        $subscribedEvents = [
            'redirect_canonical' => ['preventCanonicalPathRedirectForHealthCheck', PHP_INT_MIN, 2],
            'template_redirect' => ['respondToHealthCheck', PHP_INT_MAX],
        ];

        $this->assertSame($subscribedEvents, $callbacks);
    }

    public function testImplementsWordPressAwareInterface(): void
    {
        $this->assertInstanceOf(WordPressAwareInterface::class, $this->subscriber);
    }

    public function testPreventCanonicalPathRedirectForHealthCheckKeepsRedirectForOtherRequests(): void
    {
        $_SERVER['YMIR_HEALTH_CHECK'] = '0';

        $this->assertSame('https://example.com/health-tips/', $this->subscriber->preventCanonicalPathRedirectForHealthCheck('https://example.com/health-tips/', 'https://example.com/ymir/health'));
    }

    public function testPreventCanonicalPathRedirectForHealthCheckKeepsRedirectToOtherHost(): void
    {
        $this->mockHealthCheckRequest();

        $this->assertSame('https://example.com/ymir/health', $this->subscriber->preventCanonicalPathRedirectForHealthCheck('https://example.com/ymir/health', 'https://www.example.com/ymir/health'));
    }

    public function testPreventCanonicalPathRedirectForHealthCheckKeepsRedirectToOtherScheme(): void
    {
        $this->mockHealthCheckRequest();

        $this->assertSame('https://example.com/ymir/health', $this->subscriber->preventCanonicalPathRedirectForHealthCheck('https://example.com/ymir/health', 'http://example.com/ymir/health'));
    }

    public function testPreventCanonicalPathRedirectForHealthCheckPreventsPathRedirect(): void
    {
        $this->mockHealthCheckRequest();

        $this->assertFalse($this->subscriber->preventCanonicalPathRedirectForHealthCheck('https://example.com/health-tips/', 'https://example.com/ymir/health'));
    }

    public function testRespondToHealthCheckDiscardsBuildFileOutput(): void
    {
        file_put_contents($this->buildFilePath, "stray output<?php echo 'more'; return ['build_id' => '".self::BUILD_ID."'];");

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => self::BUILD_ID]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->expectOutputString('');

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckDoesNothingBeforeWordPressIsSupplied(): void
    {
        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'is_404')
             ->expects($this->once())
             ->willReturn(false);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckDoesNothingForOtherPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/ymir/healthy';

        $this->mockHealthCheckRequest();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckDoesNothingWhenSiteOwnsPath(): void
    {
        $this->mockHealthCheckRequest(false);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckDoesNothingWhenSiteRoutesPlainPermalinkRequest(): void
    {
        $this->mockHealthCheckRequest(false, false, ['s' => 'health']);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    /**
     * @dataProvider provideUnauthorizedServerParameters
     */
    public function testRespondToHealthCheckDoesNothingWithoutAuthorizedGetRequest(array $parameters, array $removedParameters): void
    {
        $_SERVER = array_merge($_SERVER, $parameters);

        foreach ($removedParameters as $parameter) {
            unset($_SERVER[$parameter]);
        }

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsBuildIdFromBuildFile(): void
    {
        file_put_contents($this->buildFilePath, "<?php return ['build_id' => '".self::BUILD_ID."'];");

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->never());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => self::BUILD_ID]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->with($this->identicalTo('ymir_diagnosing_health'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsBuildIdWhenDiagnosticsFail(): void
    {
        file_put_contents($this->buildFilePath, "<?php return ['build_id' => '".self::BUILD_ID."'];");

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->once())
             ->with($this->stringContains('Vector health check failed: RuntimeException: database'));

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'down', 'build_id' => self::BUILD_ID]), $this->identicalTo(500));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->willThrowException(new \RuntimeException('database'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsDownResponseWhenDiagnosticsFail(): void
    {
        $this->mockHealthCheckRequest();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->once())
             ->with($this->stringContains('Vector health check failed: RuntimeException: database password'));

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'down', 'build_id' => null]), $this->identicalTo(500));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->with($this->identicalTo('ymir_diagnosing_health'))
                     ->willThrowException(new \RuntimeException('database password'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsDownResponseWhenReportingFails(): void
    {
        $this->mockHealthCheckRequest();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->once())
             ->willThrowException(new \RuntimeException('reporting failed'));

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'down', 'build_id' => null]), $this->identicalTo(500));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->willThrowException(new \Error('diagnostic error'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    /**
     * @dataProvider provideInvalidBuildFiles
     */
    public function testRespondToHealthCheckSendsNullBuildIdForInvalidBuildFile(string $contents): void
    {
        file_put_contents($this->buildFilePath, $contents);

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->once())
             ->with($this->identicalTo('Vector health check ignored vector-build.php: it did not return a valid build ID'));

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsNullBuildIdWhenBuildFileFailsToLoad(): void
    {
        file_put_contents($this->buildFilePath, "<?php return ['build_id' => 'secret-content'");

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->once())
             ->with($this->identicalTo('Vector health check could not load vector-build.php'));

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsNullBuildIdWhenBuildFileIsADirectory(): void
    {
        mkdir($this->buildFilePath);

        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        try {
            $this->subscriber->respondToHealthCheck();
        } finally {
            rmdir($this->buildFilePath);
        }
    }

    public function testRespondToHealthCheckSendsNullBuildIdWhenBuildFileIsMissing(): void
    {
        $this->mockHealthCheckRequest();
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'error_log')
             ->expects($this->never());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsUpResponse(): void
    {
        $this->mockHealthCheckRequest();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->with($this->identicalTo('ymir_diagnosing_health'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();

        $this->assertTrue(DONOTCACHEPAGE);
    }

    public function testRespondToHealthCheckSendsUpResponseInSubdirectoryWithPlainPermalinks(): void
    {
        $_SERVER['REQUEST_URI'] = '/blog/ymir/health/';

        $this->mockHealthCheckRequest(false, false, [], 'https://example.com/blog');

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute')
                     ->with($this->identicalTo('ymir_diagnosing_health'));

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSendsUpResponseWithCacheBustingQuery(): void
    {
        $_SERVER['REQUEST_URI'] = '/ymir/health?vector_check=3f9a2c';
        $_GET['vector_check'] = '3f9a2c';

        try {
            $this->mockHealthCheckRequest();
            $this->mockCacheControlHeader();

            $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
                 ->expects($this->once());

            $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
                 ->expects($this->once())
                 ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

            $eventManager = $this->getEventManagerMock();
            $eventManager->expects($this->once())
                         ->method('execute');

            $this->subscriber->setEventManager($eventManager);

            $this->subscriber->respondToHealthCheck();
        } finally {
            unset($_GET['vector_check']);
        }
    }

    public function testRespondToHealthCheckSendsUpResponseWithCacheBustingQueryAndPlainPermalinks(): void
    {
        $_SERVER['REQUEST_URI'] = '/ymir/health/?vector_check=3f9a2c';

        $this->mockHealthCheckRequest(false, false);
        $this->mockCacheControlHeader();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once())
             ->with($this->identicalTo(['status' => 'up', 'build_id' => null]), $this->identicalTo(200));

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckSkipsCacheControlHeaderWhenHeadersAreSent(): void
    {
        $this->mockHealthCheckRequest();

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'nocache_headers')
             ->expects($this->once());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'headers_sent')
             ->expects($this->once())
             ->willReturn(true);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'header')
             ->expects($this->never());

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->once());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->once())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    public function testRespondToHealthCheckUsesUpdatedWordPressRequest(): void
    {
        $wp = $this->mockHealthCheckRequest(false, false);
        $wp->query_vars['s'] = 'health';

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'wp_send_json')
             ->expects($this->never());

        $eventManager = $this->getEventManagerMock();
        $eventManager->expects($this->never())
                     ->method('execute');

        $this->subscriber->setEventManager($eventManager);

        $this->subscriber->respondToHealthCheck();
    }

    /**
     * Expect the health check response to send a Cache-Control header that forbids storing it.
     */
    private function mockCacheControlHeader(): void
    {
        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'headers_sent')
             ->expects($this->once())
             ->willReturn(false);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'header')
             ->expects($this->once())
             ->with($this->identicalTo('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private'));
    }

    /**
     * Supply the WordPress request environment and mock the WordPress request helpers.
     */
    private function mockHealthCheckRequest(bool $is404 = true, bool $didPermalink = true, array $queryVariables = [], string $homeUrl = 'https://example.com'): \WP
    {
        $wp = new \WP();
        $wp->did_permalink = $didPermalink;
        $wp->query_vars = $queryVariables;

        $this->subscriber->setWordPress($wp);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'is_404')
             ->expects($this->any())
             ->willReturn($is404);

        $this->getFunctionMock($this->getNamespace(HealthCheckSubscriber::class), 'home_url')
             ->expects($this->any())
             ->willReturn($homeUrl);

        return $wp;
    }
}
