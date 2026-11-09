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

namespace Ymir\Plugin\Console;

/**
 * Helper class for interacting with WP-CLI.
 */
class WpCli
{
    /**
     * Write error message.
     */
    public function error(string $message): void
    {
        \WP_CLI::error($message);
    }

    /**
     * Write an information message.
     */
    public function info(string $message): void
    {
        \WP_CLI::log($message);
    }

    /**
     * Check if the given WP-CLI command is registered.
     */
    public function isCommandRegistered(string $command): bool
    {
        return is_array(\WP_CLI::get_runner()->find_command_to_run(explode(' ', $command)));
    }

    /**
     * Register the given command with WP-CLI.
     */
    public function registerCommand(CommandInterface $command): void
    {
        if (!$this->isWpCliActive()) {
            return;
        }

        $args = [
            'shortdesc' => $command::getDescription(),
            'synopsis' => $command::getSynopsis(),
        ];

        \WP_CLI::add_command($command::getName(), $command, $args);

        // Vector: keep the upstream "ymir" namespace registered so anything invoking "wp ymir <command>" still works.
        $legacyName = $this->getLegacyCommandName($command::getName());

        if (null !== $legacyName) {
            \WP_CLI::add_command($legacyName, $command, $args);
        }
    }

    /**
     * Write success message.
     */
    public function success(string $message): void
    {
        \WP_CLI::success($message);
    }

    /**
     * Get the upstream "ymir" command name for a "vector" command name.
     */
    private function getLegacyCommandName(string $name): ?string
    {
        return str_starts_with($name, 'vector ') ? 'ymir '.substr($name, 7) : null;
    }

    /**
     * Checks if WP-CLI is active.
     */
    private function isWpCliActive(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }
}
