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

## Production environment

In production, Vector's deploy sets these on the site's Ymir environment. Ymir
itself sets `YMIR_*` and `AWS_*`. The database, salts, `WP_HOME`, and `WP_SITEURL`
part of the contract belongs to Vector's
[ADR 0095](https://github.com/built-fast/vector/blob/main/docs/adrs/0095-wordpress-production-build-tree.md);
this part belongs to
[ADR 0097](https://github.com/built-fast/vector/blob/main/docs/adrs/0097-environment-contract-between-deploy-and-vector-mu.md).

| Variable | Set by Vector's deploy | When missing or invalid |
| --- | --- | --- |
| `VECTOR_RUNTIME` | always, to `lambda` | unset or empty still resolves to `lambda` (see [Runtime](#runtime)) |
| `VECTOR_SES_CONFIGURATION_SET` | always, to the site's SES configuration set | `wp_mail()` refuses every send |

`VECTOR_SES_CONFIGURATION_SET` is read with `getenv` and is valid when it matches
`^[A-Za-z0-9_-]{1,64}$` (SES's naming rules). It applies only where the bundled
fork's `wp_mail()` sends through SES (`SendRawEmail`), that is, the `lambda`
runtime; dev mail capture ignores it. After every WordPress filter and
`phpmailer_init` have run, the fork removes any `X-SES-CONFIGURATION-SET` header the
site supplied (through the headers argument, the `wp_mail` filter, or
`phpmailer_init`; matched case-insensitively) and adds exactly one
`X-SES-CONFIGURATION-SET: <name>`, so SES counts the site's sends, bounces, and
complaints and the site can't drop or spoof it. When the variable is missing, empty,
or invalid, the fork doesn't call SES: `wp_mail()` fires `wp_mail_failed` with a
`WP_Error` whose message names the variable (never its value) and returns false.

Send limits, the escape hatch to the site's own mailer, and SES readiness checks
are deferred.

### Build ID

Vector's deploy also writes `vector-build.php` next to `wp-config.php` (WordPress's
`ABSPATH`) in the production image, containing `<?php return ['build_id' => '<ULID>'];`.
The bundled fork's private `/ymir/health` endpoint adds that value to its response,
`{"status": "up", "build_id": "<ULID>"}`, so Vector's post-deploy check can tell the new
build is the one answering. `build_id` is `null` when the file is missing (as on dev
hosts) or isn't valid. The response is sent with `Cache-Control: no-store`.

## Loopback header

Dev sites sit behind an access gate. So the site's requests to itself (Site Health,
cron spawning, async and REST self-calls) get through, the `LoopbackHeader` module
sends `X-Vector-Loopback: <secret>` on WP HTTP API requests whose host is the site's
own (the host of `home_url()` or `site_url()`, any scheme, case-insensitive), and on
no others. The header is removed before a redirect to another host, and a redirect
chain that has left the site's host never gets it back.

The secret is read, at most once per request, from
`/etc/vector/sites/<user>/loopback`, where `<user>` is the effective POSIX user. It
must be 64 lowercase hex characters (a trailing newline is fine). The module keys off
this file rather than `VECTOR_RUNTIME`, since WP-CLI and cron on a dev host don't see
the FPM pool's environment; where the file is missing, unreadable, or malformed, or
the `posix` extension is unavailable, it does nothing. The secret is never logged.

## Dev mail capture

On dev hosts, mail is captured instead of sent. PHP's `sendmail_path` there points
at the host's capture binary, `/opt/vector/bin/vector-sendmail`, which catches
`mail()` and core's default transport, but a mailer plugin (SMTP or an API) would
still send. So the `DevMailCapture` module hooks `pre_wp_mail` at the earliest
priority and stops `wp_mail()` before any mailer plugin's transport:

- It builds the message as core's `wp_mail()` would, with WordPress's PHPMailer: the
  same handling of recipients, headers, From, Content-Type and charset, attachments,
  and embeds, the same `wp_mail_*` filters, and `phpmailer_init`. It uses a fresh
  PHPMailer, not the global one a mailer plugin may have replaced, and sets the
  transport back to sendmail after `phpmailer_init`, so SMTP or API settings made
  there are never used. The headers include Bcc, as `sendmail -t` reads them.
- It pipes the raw message to the capture binary through `proc_open` with an argv
  array (no shell). `wp_mail()` returns true when the binary exits 0, after
  `wp_mail_succeeded`; otherwise it fires `wp_mail_failed` with a `WP_Error` and
  returns false.

It's on in the `dev` runtime and wherever the capture binary exists, since WP-CLI
and cron on a dev host don't see the FPM pool's environment and resolve to `lambda`.
In production neither holds and it does nothing. A filter that runs earlier and
returns a value is passed through.

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
`VECTOR_RUNTIME` unset (`lambda`), that `dev` captures `wp_mail()` through a
stand-in capture binary even with SMTP configured, and that `lambda` refuses to send
without `VECTOR_SES_CONFIGURATION_SET`. See the script for its settings;
CI runs it against MySQL 8.4.

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
