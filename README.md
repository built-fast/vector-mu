# Vector MU

Must-use plugin for the Vector hosting platform.

## Requirements

- PHP 8.2+
- WordPress 6.0+
- Composer

## Installation

Install a release artifact: extract it into `wp-content/mu-plugins/`, which gives
`mu-plugins/vector-mu/`, and copy the loader up a level (WordPress only loads
top-level files there):

```bash
tar -xzf vector-mu-X.Y.Z.tar.gz -C wp-content/mu-plugins
cp wp-content/mu-plugins/vector-mu/mu-loader.php wp-content/mu-plugins/vector-mu.php
```

From a checkout instead, place this repository at `wp-content/mu-plugins/vector-mu/`,
run `composer install --no-dev --optimize-autoloader`, and copy the loader the same way.

## Runtime

`VECTOR_RUNTIME` selects what vector-mu turns on. It's read from the environment once
per request and exposed as the `VECTOR_MU_RUNTIME` constant.

| Value | Runtime | Bundled plugins |
| --- | --- | --- |
| `lambda`, unset, or empty | `lambda` (production) | all, including `vector-wordpress-plugin` |
| `dev` | `dev` (Vector dev hosts) | all except `vector-wordpress-plugin`, so uploads are served from disk |
| anything else | logged, then `dev` | as `dev` |

Unset means `lambda` because WP-CLI on a dev host doesn't see the site's FPM pool
environment; without `YMIR_ENVIRONMENT` the fork stays inert there anyway. The Ymir
conflict guard runs in both runtimes.

## Release artifact

Each release `vX.Y.Z` on GitHub has two assets:

- `vector-mu-X.Y.Z.tar.gz`: a single `vector-mu/` directory with `vector-mu.php`,
  `mu-loader.php`, `LICENSE`, `README.md`, `src/`, `vendor/` (the Composer autoloader,
  no dev dependencies), and `plugins/` without the bundled plugins' dev files. It holds
  only regular files (0644) and directories (0755), owned by root.
- `vector-mu-X.Y.Z.tar.gz.sha256`, in `sha256sum` format.

`bin/build-release <version> <out-dir>` builds both from the committed `HEAD` (paths
marked `export-ignore` in `.gitattributes` are left out) and checks the tree. It needs
GNU tar (`gtar` on macOS: `brew install gnu-tar`). The build is reproducible for a
given commit and Composer version; CI pins Composer, so a local build with another
version may differ.

`tests/integration/wordpress.sh [artifact]` installs the artifact (or a fresh build)
into a pinned WordPress against a MySQL server and checks that it loads with no
`YMIR_*` or `AWS_*` variables, as `dev` (no fork, uploads served from disk) and with
`VECTOR_RUNTIME` unset (`lambda`). See the script for its settings; CI runs it against
MySQL 8.4.

### Cutting a release

1. Bump `Plugin::VERSION` in `src/Plugin.php` and the `Version:` header in
   `vector-mu.php` to `X.Y.Z`, and merge to `main`.
2. Tag the merge commit and push the tag:

   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```

   `.github/workflows/release.yml` runs CI, fails unless the tag matches both
   versions, builds and tests the artifact, and publishes the GitHub release.
3. Update the version and sha256 pinned by the hosting platform; sites get the
   new vector-mu with its next release.

## Bundled plugins

Plugins under `plugins/` are loaded as must-use plugins by the `VendorPlugins` module
(`src/Modules/VendorPlugins.php`), each only in the runtimes it lists.

### vector-wordpress-plugin

Vector's fork of the [Ymir WordPress plugin](https://github.com/ymirapp/wordpress-plugin)
(GPL-3.0). It loads only in the `lambda` runtime. It provides cloud storage, page caching, email, image processing and the
`wp vector …` WP-CLI commands. See
[plugins/vector-wordpress-plugin/README.md](plugins/vector-wordpress-plugin/README.md)
for the upstream base version and the list of changes.

### Adding plugins from wordpress.org

```bash
bin/update-plugin <slug> [version]
```

Then add `'<slug>/<slug>.php' => [ Runtime::LAMBDA, Runtime::DEV ]` to
`VendorPlugins::PLUGINS` (the runtimes it loads in) and commit the plugin files. If it
has dev files, mark them `export-ignore` in `.gitattributes`.

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
