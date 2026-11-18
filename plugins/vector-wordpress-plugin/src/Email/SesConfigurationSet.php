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

namespace Ymir\Plugin\Email;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sets the site's SES configuration set on an outgoing email.
 *
 * Vector's deploy sets VECTOR_SES_CONFIGURATION_SET so SES counts sends,
 * bounces and complaints per site. Any X-SES-CONFIGURATION-SET header the site
 * supplied is removed, so a site can't drop or spoof it, and a send without a
 * valid configuration set is refused.
 */
class SesConfigurationSet
{
    /**
     * The environment variable holding the configuration set name.
     */
    public const ENV = 'VECTOR_SES_CONFIGURATION_SET';

    /**
     * The header SES reads the configuration set from.
     */
    public const HEADER = 'X-SES-CONFIGURATION-SET';

    /**
     * SES configuration set names: letters, digits, "_" and "-", at most 64 characters.
     */
    private const PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * Replace any configuration set header on the mailer with the one from the environment.
     *
     * Throws without adding a header when the environment variable is missing
     * or invalid. The exception message never includes the value.
     */
    public static function apply(PHPMailer $mailer): void
    {
        $customHeaders = $mailer->getCustomHeaders();
        $mailer->clearCustomHeaders();

        foreach ($customHeaders as $customHeader) {
            if (0 !== strcasecmp(trim((string) $customHeader[0]), self::HEADER)) {
                $mailer->addCustomHeader($customHeader[0], $customHeader[1]);
            }
        }

        $name = getenv(self::ENV);

        if (!is_string($name) || 1 !== preg_match(self::PATTERN, $name)) {
            throw new \RuntimeException(sprintf('Email not sent: the %s environment variable is missing or isn\'t a valid SES configuration set name', self::ENV));
        }

        $mailer->addCustomHeader(self::HEADER, $name);
    }

    /**
     * Check that the raw MIME message's header block has exactly one configuration set header.
     *
     * This catches a header smuggled in some other way than a custom header,
     * such as through a PHPMailer property changed in "phpmailer_init".
     */
    public static function verify(string $message): void
    {
        $headerBlock = preg_split('/\r?\n\r?\n/', $message, 2)[0] ?? '';

        if (1 !== preg_match_all('/^'.preg_quote(self::HEADER, '/').'[ \t]*:/im', $headerBlock)) {
            throw new \RuntimeException(sprintf('Email not sent: the message must have exactly one %s header', self::HEADER));
        }
    }
}
