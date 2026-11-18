# Vector WordPress plugin

WordPress integration plugin for the Vector platform, bundled with and loaded by
[vector-mu](../../README.md).

This is a fork of the [Ymir WordPress plugin](https://github.com/ymirapp/wordpress-plugin)
by Carl Alexander, licensed under GPL-3.0 (see [LICENSE](LICENSE)). Many thanks to the
Ymir team.

## Upstream

| | |
|---|---|
| Repository | https://github.com/ymirapp/wordpress-plugin |
| Base version | 1.29.0 |
| Base commit | `7e8db24a20a123d4b5978e4205b3541a239bf367` |

Imported as a plain snapshot (no shared git history). To pull in upstream changes,
diff the upstream range since the base commit and apply it manually, then update the
table above:

```console
$ git clone https://github.com/ymirapp/wordpress-plugin /tmp/ymir-upstream
$ git -C /tmp/ymir-upstream diff 7e8db24a20a123d4b5978e4205b3541a239bf367 <new-tag> > ymir.patch
$ git apply --directory=plugins/vector-wordpress-plugin --3way ymir.patch
```

## Changes from upstream

Internals are intentionally left as-is to keep upstream merges small: the
`Ymir\Plugin` namespace, `YMIR_*` environment variables and constants, `ymir_*`
hooks, the `$ymir` global, the `ymir/v1` REST namespace and the `ymir-public://` /
`ymir-private://` stream wrappers.

- Main file renamed `ymir.php` → `vector-wordpress-plugin.php`; plugin header rebranded.
- `Plugin::isActive()` detects being loaded from any depth under `mu-plugins/`
  (it's loaded from `mu-plugins/vector-mu/plugins/vector-wordpress-plugin/`).
- WP-CLI commands are registered as `wp vector <command>`; the upstream
  `wp ymir <command>` names remain registered as aliases.
- User-visible strings rebranded: admin notice prefix, "plugin isn't active"
  exceptions, health check log message, PHP version check message.
- Security: `LambdaClient` shell-quotes every value interpolated into the WP-CLI
  command sent to the console function and rejects non-JSON image editor history.
  Upstream wrapped request data (`history`, crop `context`) in single quotes without
  escaping, so a `'` could break out of the argument.
- SES configuration set: `Email::toString()` calls the Vector-added
  `Email\SesConfigurationSet` after `phpmailer_init`, when every WordPress filter and
  action has run. It removes any `X-SES-CONFIGURATION-SET` header the site supplied
  (case-insensitively) and adds one with the value of `VECTOR_SES_CONFIGURATION_SET`,
  then checks that the raw message has exactly one. When the variable is missing or
  isn't a valid name (`^[A-Za-z0-9_-]{1,64}$`), the send is refused before SES is
  called: `wp_mail()` returns false and fires `wp_mail_failed`. The value is never
  included in the error. The existing `EmailTest` was updated for this, and
  `SesConfigurationSetTest` and `PluggableWpMailTest` were added.
- Version set to `1.29.0-vector.3`.
- `.php-cs-fixer.dist.php` no longer enforces the upstream file header, so modified
  files can carry a "Modified by Vector" notice.
- Removed upstream release tooling (`.github/`, release-please config).

## Development

```console
$ composer install
$ composer tests:unit
```
