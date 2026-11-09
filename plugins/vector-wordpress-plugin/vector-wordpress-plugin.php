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

/**
 * Plugin Name: Vector
 * Description: Integrates WordPress with the Vector platform. Fork of the Ymir WordPress plugin (https://github.com/ymirapp/wordpress-plugin).
 * Version: 1.29.0-vector.1
 * Requires at least: 5.9
 * Author: Vector
 * License: GPL3
 */

require_once __DIR__.'/bootstrap.php';

global $ymir;

// Load "is_plugin_active" function used by the plugin
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
}

// Load Ymir plugin
$ymir->load();

// Load Ymir pluggable functions if the plugin is active
if ($ymir->isActive()) {
    require_once __DIR__.'/functions.php';
    require_once __DIR__.'/pluggable.php';
}
