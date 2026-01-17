# Vector MU

WordPress MU plugin framework for Vector hosting.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Composer

## Installation

```bash
composer install
ln -s /opt/vector-mu/public/vector-mu.php /path/to/wp-content/mu-plugins/vector-mu.php
```

## Modules

### PluginBlocker

Prevents incompatible plugins from running. Blocked plugins are filtered from the active plugins list and an admin notice is displayed.

### UserImpersonate

WP-CLI command for generating one-time login URLs.

```bash
wp user impersonate <user>
wp user impersonate admin --ttl=60
```

## Development

```bash
composer test       # Run tests
composer lint       # Check code style
composer lint:fix   # Fix code style
composer analyze    # Run static analysis
```

## Adding Modules

1. Create a class in `src/Modules/` implementing `ModuleInterface`
2. Add the class to the `MODULES` array in `src/Plugin.php`
