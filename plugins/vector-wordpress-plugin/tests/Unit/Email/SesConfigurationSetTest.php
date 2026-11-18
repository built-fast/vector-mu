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

namespace Ymir\Plugin\Tests\Unit\Email;

use PHPMailer\PHPMailer\PHPMailer;
use Ymir\Plugin\Email\SesConfigurationSet;
use Ymir\Plugin\Tests\Unit\TestCase;

class SesConfigurationSetTest extends TestCase
{
    public const REFUSED = 'Email not sent: the VECTOR_SES_CONFIGURATION_SET environment variable is missing or isn\'t a valid SES configuration set name';

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        putenv(SesConfigurationSet::ENV);
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
            'trailing newline' => ["vector-site\n"],
            'header injection' => ["vector-site\r\nBcc: someone@example.com"],
            'dot' => ['vector.site'],
        ];
    }

    public function testApplyAcceptsSixtyFourCharacters(): void
    {
        putenv(SesConfigurationSet::ENV.'='.str_repeat('a', 64));

        $mailer = new PHPMailer(true);

        SesConfigurationSet::apply($mailer);

        $this->assertSame([['X-SES-CONFIGURATION-SET', str_repeat('a', 64)]], $mailer->getCustomHeaders());
    }

    public function testApplyAddsHeader(): void
    {
        putenv(SesConfigurationSet::ENV.'=vector_Site-01');

        $mailer = new PHPMailer(true);
        $mailer->addCustomHeader('X-Foo', 'bar');

        SesConfigurationSet::apply($mailer);

        $this->assertSame([['X-Foo', 'bar'], ['X-SES-CONFIGURATION-SET', 'vector_Site-01']], $mailer->getCustomHeaders());
    }

    /**
     * @dataProvider invalidValues
     */
    public function testApplyRefusesInvalidValue(string $value): void
    {
        putenv(SesConfigurationSet::ENV.'='.$value);

        $mailer = new PHPMailer(true);
        $mailer->addCustomHeader('X-Foo', 'bar');
        $mailer->addCustomHeader('X-SES-CONFIGURATION-SET', 'spoofed');

        try {
            SesConfigurationSet::apply($mailer);
            $this->fail('Expected the send to be refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame(self::REFUSED, $exception->getMessage());
        }

        $this->assertSame([['X-Foo', 'bar']], $mailer->getCustomHeaders());
    }

    public function testApplyRefusesWhenMissing(): void
    {
        putenv(SesConfigurationSet::ENV);

        $mailer = new PHPMailer(true);
        $mailer->addCustomHeader('X-SES-CONFIGURATION-SET', 'spoofed');

        try {
            SesConfigurationSet::apply($mailer);
            $this->fail('Expected the send to be refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame(self::REFUSED, $exception->getMessage());
        }

        $this->assertSame([], $mailer->getCustomHeaders());
    }

    public function testApplyReplacesSiteHeadersCaseInsensitively(): void
    {
        putenv(SesConfigurationSet::ENV.'=vector-site');

        $mailer = new PHPMailer(true);
        $mailer->addCustomHeader('x-ses-configuration-set', 'spoofed');
        $mailer->addCustomHeader('X-Foo', 'bar');
        $mailer->addCustomHeader('X-Ses-Configuration-Set', 'other');

        SesConfigurationSet::apply($mailer);

        $this->assertSame([['X-Foo', 'bar'], ['X-SES-CONFIGURATION-SET', 'vector-site']], $mailer->getCustomHeaders());
    }

    public function testVerifyAcceptsOneHeader(): void
    {
        SesConfigurationSet::verify("To: a@example.com\r\nX-SES-CONFIGURATION-SET: vector-site\r\n\r\nX-SES-CONFIGURATION-SET: in the body");

        $this->addToAssertionCount(1);
    }

    public function testVerifyRefusesMissingHeader(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email not sent: the message must have exactly one X-SES-CONFIGURATION-SET header');

        SesConfigurationSet::verify("To: a@example.com\r\n\r\nX-SES-CONFIGURATION-SET: in the body");
    }

    public function testVerifyRefusesSmuggledHeader(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email not sent: the message must have exactly one X-SES-CONFIGURATION-SET header');

        SesConfigurationSet::verify("X-Mailer: x\r\nx-ses-configuration-set : spoofed\r\nX-SES-CONFIGURATION-SET: vector-site\r\n\r\nbody");
    }
}
