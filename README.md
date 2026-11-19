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
itself sets `YMIR_*` and `AWS_*`. The database, salts, `WP_HOME`, and `WP_SITEURL` are
set as WordPress expects; the variables below are vector-mu's part.

| Variable | Set by Vector's deploy | When missing or invalid |
| --- | --- | --- |
| `VECTOR_RUNTIME` | always, to `lambda` | unset or empty still resolves to `lambda` (see [Runtime](#runtime)) |
| `VECTOR_SES_CONFIGURATION_SET` | always, to the site's SES configuration set | `wp_mail()` refuses every send |
| `VECTOR_PREVIEW_HOST` | always (a Ymir secret), to the preview hostname | the [preview gate](#preview-gate) refuses every request |
| `VECTOR_GATE_DOCUMENT_URL` | always (a Ymir secret), to `https://<control domain>/api/v1/gate/previews/<site ID>` | as above |
| `VECTOR_GATE_DOCUMENT_TOKEN` | always (a Ymir secret), 32 random bytes, unpadded base64url | as above |
| `VECTOR_GATE_COOKIE_SECRET` | always (a Ymir secret), 32 random bytes, standard base64 | as above |
| `VECTOR_LOOPBACK_SECRET` | always (a Ymir secret), 32 random bytes, lowercase hex | as above, and no loopback header |
| `VECTOR_PUBLIC_HOSTS` | at go-live, to the site's live custom domains, comma-separated | missing or empty: every host is gated; invalid: logged, and every host is gated |

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

### Build endpoint

Vector's deploy writes `vector-build.php` at the WordPress root (`ABSPATH`, next to
`wp-config.php`) of the production image, containing
`<?php return ['build_id' => '<ULID>'];`, and its post-deploy check requests
`GET /__vector/build?vector_check=<random>` to prove the new build is the one
answering. The `BuildEndpoint` module answers it:

| Request | Response |
| --- | --- |
| `GET` with a valid build file | `200` `{"build_id":"<ULID>"}` |
| `GET` without one | `404` `{"build_id":null}` |
| `HEAD` | the same status and headers, no body |
| any other method | `405` `{"build_id":null}`, with `Allow: GET, HEAD` |

Every response has `Cache-Control: no-store, no-cache, must-revalidate, max-age=0,
private` and `Content-Type: application/json`. Only the path is matched, exactly: the
query string is ignored, and `/__vector/build/` or `/blog/__vector/build` are left to
WordPress. The build ID is valid when the file returns an array whose `build_id`
matches `^[0-9A-HJKMNP-TV-Z]{26}$`. The file is included in an isolated scope with its
output discarded; a file that's unreadable, fails to load, or doesn't hold a valid ID
gives a `404` and one error log line, never the file's contents. A missing file (as on
dev hosts) isn't logged.

It's on only in the `lambda` runtime. It answers while vector-mu loads, right after
the preview gate (which exempts it) and before any WordPress hook, so regular plugins, the theme, redirects, login
walls, and maintenance plugins never run for it. What runs before vector-mu still
applies: Ymir's runtime (including its maintenance mode), WordPress's bootstrap up to
must-use plugins (`wp-config.php`, the database connection and the installed check,
`advanced-cache.php`/`object-cache.php` drop-ins), and any must-use plugin whose file
sorts before `vector-mu.php`. In `dev` the path is left to WordPress; on dev hosts nginx
sends `/__vector/` to the access gate anyway.

### Preview gate

A production site is private, like a dev site, everywhere but its live custom
domains, and on Lambda nothing but CloudFront sits in front of PHP, so the
`PreviewGate` module gates it itself,
with the dev gate's own `vg1` tokens and `v1` cookie.
It's on only in the `lambda` runtime, as vector-mu's first module.

- Hosts in `VECTOR_PUBLIC_HOSTS`, a comma-separated list of lowercase DNS names
  (no spaces) that Vector sets at go-live, are never gated and get none of the gate's
  headers. Every other host is gated: the preview hostname (`<slug>.<sites
  domain>`), Ymir's vanity domain, the CloudFront and API Gateway domains, and any
  host a forged `X-Forwarded-Host` names (Ymir's runtime takes `HTTP_HOST` from
  it). The request's host is its `Host`, lowercased with any port removed.
- `VECTOR_PREVIEW_HOST` is always gated, even if the list names it. A missing or
  empty list gates every host. A list with any invalid entry is logged (never its
  value) and gates every host.
- Tokens and cookies are bound to `VECTOR_PREVIEW_HOST`, so only there does the gate
  sign in or accept a cookie. Any other gated host gets the private page, with no
  redirect.
- If any of the five gate variables is missing or invalid, it refuses every
  non-exempt request on every gated host and logs which variables (never their
  values). Public hosts stay public.
- On the preview hostname, `GET /__vector/auth?t=<token>&r=<path>` verifies the token against the gate
  document's keys and for the preview hostname, sets
  `__Host-vector_gate=<value>; Path=/; Max-Age=<n>; HttpOnly; Secure; SameSite=Lax`
  (at most 12 hours, never past the token's `exp`; the MAC key is derived from
  `VECTOR_GATE_COOKIE_SECRET` and the document's `session_epoch`), and redirects
  `303` to `r` when it's a safe relative path, `/` otherwise. A refused token, or a
  share token whose link is revoked, gets the private page. Other methods get `405`.
- Every other request there needs a valid cookie whose share link, if any, isn't in the
  document's `revoked_share_links`. Otherwise it gets the private page: `401`, no
  site content, with a link to the site in Vector Control
  (`<control_url>/sites/<site ID>`). `/__vector/private` is that page.
- Exempt, on every gated host: `GET`/`HEAD /__vector/build`, anything that isn't an HTTP request (Ymir's
  console and cron run PHP's CLI), and a request whose `X-Vector-Loopback` equals
  `VECTOR_LOOPBACK_SECRET` (compared in constant time).
- Every response on a gated host, exempt or not, sends
  `X-Robots-Tag: noindex, nofollow` and `Cache-Control: private, no-store`; for
  pages WordPress serves they're sent again just before the response goes out, so a
  plugin's own `Cache-Control` can't make CloudFront cache a preview page.

The keys, the session epoch, and revoked share links come from the site's gate
document, `GET <VECTOR_GATE_DOCUMENT_URL>` with
`Authorization: Bearer <VECTOR_GATE_DOCUMENT_TOKEN>`, fetched with PHP's curl
extension (not WordPress's HTTP API, whose filters could weaken it): HTTPS only,
TLS verified, no redirects or proxy, a 3-second deadline, and a 64 KiB body cap.
The document must have `version` 1, the URL's site ID as `site_id`, and
`VECTOR_PREVIEW_HOST` as `host`, plus a valid `control_url`, `session_epoch`, 1 to 8
`keys`, `revoked_share_links`, and an integer `ttl`; fields it doesn't know are
ignored. Anything else is a failed fetch.

- The document is cached in `<temp dir>/vector-mu-gate-<site id>.json` (0600,
  written atomically), so a warm container's requests share it, and is fresh for
  its `ttl` clamped to 30–300 seconds.
- After that the next gated request refetches. If that fails, the last good document
  is used until 1 hour after it was fetched; after that, or in a cold container with
  no document, the gate refuses everyone. A failed fetch isn't retried for 10
  seconds.
- A request with no gate cookie never fetches.

As for the build endpoint, what runs before vector-mu isn't gated: an
`advanced-cache.php` drop-in that serves cached pages, or a must-use plugin whose file
sorts before `vector-mu.php`, runs for every request first.

Tokens, cookies, and secrets are never logged. The token and cookie code is tested
against fixed test vectors
(`tests/fixtures/gate-vectors.json`).

## Loopback header

Dev sites and preview hostnames sit behind an access gate. So the site's requests to itself (Site Health,
cron spawning, async and REST self-calls) get through, the `LoopbackHeader` module
sends `X-Vector-Loopback: <secret>` on WP HTTP API requests whose host is the site's
own (the host of `home_url()` or `site_url()`, any scheme, case-insensitive), and on
no others. The header is removed before a redirect to another host, and a redirect
chain that has left the site's host never gets it back.

The secret is read at most once per request. In the `lambda` runtime it's
`VECTOR_LOOPBACK_SECRET` whenever that's set; it must be 64 lowercase hex
characters, or no header is sent. Otherwise it's read from
`/etc/vector/sites/<user>/loopback`, where `<user>` is the effective POSIX user. It
must be 64 lowercase hex characters (a trailing newline is fine). For the file the
module keys off the file rather than `VECTOR_RUNTIME`, since WP-CLI and cron on a dev
host don't see the FPM pool's environment and resolve to `lambda`; where the file is
missing, unreadable, or malformed, or the `posix` extension is unavailable, it does
nothing. The secret is never logged.

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
stand-in capture binary even with SMTP configured, that `lambda` refuses to send
without `VECTOR_SES_CONFIGURATION_SET`, and that the preview gate fails closed
without its variables and in a cold container that can't reach Vector Control. See the script for its settings;
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
