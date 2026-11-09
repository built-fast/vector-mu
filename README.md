# Vector MU

Must-use plugin for the Vector hosting platform.

## Requirements

- PHP 8.2+
- WordPress 6.0+
- Composer

## Installation

Place this repository at `wp-content/mu-plugins/vector-mu/`, install the autoloader,
and copy the loader into `mu-plugins/` (WordPress only loads top-level files there):

```bash
composer install --no-dev --optimize-autoloader
cp mu-loader.php ../vector-mu.php
```

## Bundled plugins

Plugins under `plugins/` are loaded as must-use plugins by the `VendorPlugins` module
(`src/Modules/VendorPlugins.php`).

### vector-wordpress-plugin

Vector's fork of the [Ymir WordPress plugin](https://github.com/ymirapp/wordpress-plugin)
(GPL-3.0). It provides cloud storage, page caching, email, image processing and the
`wp vector …` WP-CLI commands. See
[plugins/vector-wordpress-plugin/README.md](plugins/vector-wordpress-plugin/README.md)
for the upstream base version and the list of changes.

### Adding plugins from wordpress.org

```bash
bin/update-plugin <slug> [version]
```

Then add `<slug>/<slug>.php` to `VendorPlugins::PLUGINS` and commit the plugin files.

## Development

```bash
composer install
composer test       # Pest
composer lint       # PHPCS
composer analyze    # PHPStan

# vector-wordpress-plugin unit tests (needs WordPress core, default /tmp/wordpress)
composer install --working-dir=plugins/vector-wordpress-plugin
plugins/vector-wordpress-plugin/bin/install-wp.sh
composer test:fork
```
