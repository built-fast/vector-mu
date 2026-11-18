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

namespace Ymir\Plugin\Subscriber;

use Ymir\Plugin\EventManagement\AbstractEventManagerAwareSubscriber;
use Ymir\Plugin\EventManagement\WordPressAwareInterface;

/**
 * Subscriber that responds to Ymir deployment health checks sent to the "/ymir/health" endpoint.
 *
 * Vector: the response also carries the build ID from the "vector-build.php" file that Vector's deploy writes, so
 * a post-deploy check can tell which build answered.
 */
class HealthCheckSubscriber extends AbstractEventManagerAwareSubscriber implements WordPressAwareInterface
{
    /**
     * The pattern a build ID must match: a ULID in Crockford base32.
     */
    private const BUILD_ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/D';

    /**
     * The Cache-Control header sent with every health check response, whatever the "nocache_headers" filter returns.
     */
    private const CACHE_CONTROL = 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private';

    /**
     * The path of the build file that Vector's deploy writes at the WordPress root.
     *
     * @var string
     */
    private $buildFilePath;

    /**
     * The WordPress request environment.
     *
     * @var \WP|null
     */
    private $wp;

    /**
     * Constructor.
     */
    public function __construct(string $buildFilePath)
    {
        $this->buildFilePath = $buildFilePath;
    }

    /**
     * {@inheritdoc}
     *
     * Handle core canonical redirects before site filters, and respond after site redirects, authentication and maintenance.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'redirect_canonical' => ['preventCanonicalPathRedirectForHealthCheck', PHP_INT_MIN, 2],
            'template_redirect' => ['respondToHealthCheck', PHP_INT_MAX],
        ];
    }

    /**
     * Prevent WordPress from canonicalizing the path of a health check, such as by guessing a post with a similar slug.
     * Redirects to another scheme, host or port still apply.
     */
    public function preventCanonicalPathRedirectForHealthCheck($redirectUrl, $requestedUrl)
    {
        if (!is_string($redirectUrl) || !$this->isHealthCheckRequest()) {
            return $redirectUrl;
        }

        $redirect = (array) parse_url($redirectUrl);
        $requested = (array) parse_url((string) $requestedUrl);

        foreach (['scheme', 'host', 'port'] as $component) {
            if (($redirect[$component] ?? null) !== ($requested[$component] ?? null)) {
                return $redirectUrl;
            }
        }

        return false;
    }

    /**
     * Run the health diagnostics and send a generic JSON response if the request is a Ymir health check.
     */
    public function respondToHealthCheck(): void
    {
        if (!$this->isHealthCheckRequest()) {
            return;
        }

        $statusCode = 200;

        try {
            $this->eventManager->execute('ymir_diagnosing_health');
        } catch (\Throwable $exception) {
            $this->report($exception);

            $statusCode = 500;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        if (!headers_sent()) {
            header(self::CACHE_CONTROL);
        }

        wp_send_json(['status' => 200 === $statusCode ? 'up' : 'down', 'build_id' => $this->getBuildId()], $statusCode);
    }

    /**
     * {@inheritdoc}
     */
    public function setWordPress(\WP $wp): void
    {
        $this->wp = $wp;
    }

    /**
     * Get the build ID from the build file, or null when the file is missing or doesn't hold a valid build ID.
     */
    private function getBuildId(): ?string
    {
        if (!is_file($this->buildFilePath)) {
            return null;
        }

        if (!is_readable($this->buildFilePath)) {
            $this->log('Vector health check could not read vector-build.php');

            return null;
        }

        $level = ob_get_level();
        ob_start();

        try {
            $build = (static function (string $path) {
                return include $path;
            })($this->buildFilePath);
        } catch (\Throwable $exception) {
            // The exception's message can quote the file, so it isn't logged.
            $this->log('Vector health check could not load vector-build.php');

            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if (!is_array($build) || !isset($build['build_id']) || !is_string($build['build_id']) || 1 !== preg_match(self::BUILD_ID_PATTERN, $build['build_id'])) {
            $this->log('Vector health check ignored vector-build.php: it did not return a valid build ID');

            return null;
        }

        return $build['build_id'];
    }

    /**
     * Checks if the current request is a Ymir health check for the "/ymir/health" endpoint that the site doesn't own.
     */
    private function isHealthCheckRequest(): bool
    {
        if (!isset($_SERVER['YMIR_HEALTH_CHECK'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']) || '1' !== $_SERVER['YMIR_HEALTH_CHECK'] || 'GET' !== $_SERVER['REQUEST_METHOD']) {
            return false;
        }

        if (!is_404() && (!$this->wp instanceof \WP || $this->wp->did_permalink || !empty($this->wp->query_vars))) {
            return false;
        }

        return untrailingslashit((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)) === untrailingslashit((string) parse_url(home_url(), PHP_URL_PATH)).'/ymir/health';
    }

    /**
     * Write a message to the error log without letting a logging failure replace the generic response.
     */
    private function log(string $message): void
    {
        try {
            error_log($message);
        } catch (\Throwable $reportingException) {
        }
    }

    /**
     * Report the diagnostic exception without letting a reporting failure replace the generic response.
     */
    private function report(\Throwable $exception): void
    {
        $this->log(sprintf('Vector health check failed: %s', $exception));
    }
}
