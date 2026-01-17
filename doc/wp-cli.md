# WP-CLI Commands Reference

## Blueprint

Export/import WordPress sites as portable archives.

### Export

```bash
wp vector blueprint export [<output>] [--include-uploads] [--max-upload-size=<size>] [--include-plugins=<plugins>] [--include-themes=<themes>] [--exclude-tables=<tables>]
```

```bash
# Basic export
wp vector blueprint export

# With uploads and plugins
wp vector blueprint export --include-uploads --include-plugins=woocommerce,acf
```

### Import

```bash
wp vector blueprint import <archive> --target-url=<url> [--skip-database] [--skip-content] [--skip-search-replace] [--dry-run] [--force]
```

```bash
wp vector blueprint import backup.tar.gz --target-url=https://newsite.com
wp vector blueprint import backup.tar.gz --target-url=https://newsite.com --dry-run
```

### Validate

```bash
wp vector blueprint validate <archive>
```

---

## Change Domain

```bash
wp change-domain <old-domain> <new-domain> [--dry-run]
```

```bash
wp change-domain example.com newsite.com --dry-run
wp change-domain example.com newsite.com
```

---

## Cleanup

Database maintenance commands.

### Revisions

```bash
wp vector cleanup revisions [--keep=<count>] [--dry-run]
```

### Transients

```bash
wp vector cleanup transients [--all] [--dry-run]
```

### Trash

```bash
wp vector cleanup trash [--dry-run]
```

### Orphans

```bash
wp vector cleanup orphans [--dry-run]
```

### All

```bash
wp vector cleanup all [--dry-run]
```

```
=== Cleaning Revisions ===
Success: No posts have more than 5 revision(s).

=== Cleaning Expired Transients ===
Success: No expired transients to delete.

=== Emptying Trash ===
Success: No trash to empty.

=== Cleaning Orphaned Data ===
Success: No orphaned data to delete.
```

---

## Cron

### List

```bash
wp vector cron list [--overdue] [--hook=<hook>] [--limit=<limit>] [--format=<format>]
```

```
hook                                  next_run             schedule    interval  overdue
recovery_mode_clean_expired_keys      2026-01-17 19:15:49  daily       1d        Yes
wp_privacy_delete_old_export_files    2026-01-17 19:15:49  hourly      1h        Yes
wp_version_check                      2026-01-17 20:15:44  twicedaily  12h       No
wp_update_plugins                     2026-01-17 20:45:44  twicedaily  12h       No
wp_update_themes                      2026-01-17 21:15:44  twicedaily  12h       No
```

### Status

```bash
wp vector cron status
```

```
=== Cron Status ===
Total Events:      6
Unique Hooks:      6
Overdue Events:    2
Oldest Overdue:    2026-01-17 19:15:49 (18m ago)

=== Cron Configuration ===
DISABLE_WP_CRON:   No
ALTERNATE_WP_CRON: No

=== Schedule Breakdown ===
daily:             1 events
hourly:            1 events
twicedaily:        3 events
weekly:            1 events

=== Top 10 Hooks ===
recovery_mode_clean_expired_keys         1
wp_privacy_delete_old_export_files       1
wp_version_check                         1
wp_update_plugins                        1
wp_update_themes                         1
wp_site_health_scheduled_check           1

Warning: 2 overdue cron event(s). Run `wp cron event run --due-now` to process them.
```

---

## Database

### Summary

```bash
wp vector db summary [--format=<format>]
```

```
=== Database Overview ===
Database:          wpp
Table Prefix:      wp_
Total Size:        720 KB
Total Rows:        152
Tables:            12 total (12 WP, 0 other)

=== Cleanup Candidates ===
Revisions:         0
Auto-drafts:       0
Trashed Posts:     0
Transients:        2 (0 expired)
```

### Tables

```bash
wp vector db tables [--limit=<limit>] [--format=<format>]
```

```
table              rows  size_formatted  engine
wp_options         126   112 KB          InnoDB
wp_comments        1     96 KB           InnoDB
wp_posts           3     96 KB           InnoDB
wp_users           1     64 KB           InnoDB
wp_commentmeta     0     48 KB           InnoDB
wp_postmeta        2     48 KB           InnoDB
wp_term_taxonomy   1     48 KB           InnoDB
wp_termmeta        0     48 KB           InnoDB
wp_terms           1     48 KB           InnoDB
wp_usermeta        16    48 KB           InnoDB
```

### Revisions

```bash
wp vector db revisions [--format=<format>]
```

### Transients

```bash
wp vector db transients [--format=<format>]
```

```
=== Transients ===
Total:             2
Expired:           0
Size:              21.77 KB

=== Site Transients ===
Total:             3
Expired:           0
Size:              30.66 KB
```

### Orphans

```bash
wp vector db orphans [--format=<format>]
```

```
=== Orphaned Data ===
Orphaned postmeta:           0
Orphaned commentmeta:        0
Orphaned termmeta:           0
Orphaned term relationships: 0

Total orphaned entries:      0
```

---

## Environment

```bash
wp vector env [--format=<format>]
```

```
=== PHP ===
Version:           8.1.34 (cli)
Memory Limit:      -1
Max Execution:     Unknowns
Upload Max Size:   2M
Post Max Size:     2M
Max Input Vars:    1000
OPcache:           Disabled
Extensions:        66 loaded

=== WordPress ===
Version:           6.9
Site URL:          http://localhost:9001
Home URL:          http://localhost:9001
Multisite:         No
SSL:               No
Language:          en_US
Timezone:          +00:00
Permalinks:        Plain
Active Theme:      Twenty Twenty-Five
Active Plugins:    0

=== Database ===
Server Version:    9.5.0
Client Version:    mysqlnd 8.1.34
Database:          wpp
Table Prefix:      wp_
Charset:           utf8mb4
Collation:         utf8mb4_unicode_520_ci

=== Server ===
Software:
Hostname:          kamino.priddle.network
OS:                Darwin (Darwin)
Architecture:      arm64

=== Active Constants ===
WP_DEBUG:                    false
WP_DEBUG_LOG:                false
WP_DEBUG_DISPLAY:            true
SCRIPT_DEBUG:                false
FORCE_SSL_ADMIN:             false
WP_CACHE:                    false
WP_CRON_LOCK_TIMEOUT:        60
WP_MEMORY_LIMIT:             40M
WP_MAX_MEMORY_LIMIT:         -1
EMPTY_TRASH_DAYS:            30
WP_POST_REVISIONS:           true
AUTOSAVE_INTERVAL:           60
```

---

## Health

```bash
wp vector health [--format=<format>]
```

```
=== Environment ===
WordPress:       6.9
PHP:             8.1.34
MySQL:           9.5.0
Memory Limit:    -1
Max Execution:   Unknowns
WP_DEBUG:        Disabled
Multisite:       No

=== Plugins & Themes ===
Plugins:         0 active, 2 inactive (2 total)
Active Theme:    Twenty Twenty-Five
Themes:          3 installed

=== Storage ===
Database:        720 KB
Autoload Data:   26.25 KB
Uploads:         0 B
DB Prefix:       wp_

=== Cron ===
Scheduled:       6 events
Overdue:         2 events

=== Issues Detected ===
⚠ 2 overdue cron job(s)
```

---

## Impersonate

Generate one-time login URLs.

```bash
wp user impersonate <user>
```

```bash
wp user impersonate 1
# http://localhost:9001/wp-login.php?user_id=1&vector_token=h5ncTFslrb349tpjYyguPnHDtJP7Tx8O
```

---

## Media

### Summary

```bash
wp vector media summary [--format=<format>]
```

```
=== Media Library Summary ===
Total Attachments: 0
Unattached:        0
Uploads Size:      0 B
```

### Orphaned

```bash
wp vector media orphaned [--limit=<limit>] [--format=<format>]
```

### Missing

```bash
wp vector media missing [--limit=<limit>] [--format=<format>]
```

### Duplicates

```bash
wp vector media duplicates [--limit=<limit>] [--format=<format>]
```

---

## Options

### Autoload

```bash
wp vector options autoload [--limit=<limit>] [--format=<format>]
```

```
Total autoload size: 26.25 KB (100 options)

option_name                             size_formatted  percentage
_transient_wp_core_block_css_files      21.74 KB        82.8
wp_user_roles                           3.06 KB         11.7
cron                                    1.02 KB         3.9
_transient_doing_cron                   33 B            0.1
ping_sites                              27 B            0.1
siteurl                                 21 B            0.1
home                                    21 B            0.1
admin_email                             21 B            0.1
blogname                                20 B            0.1
mailserver_login                        17 B            0.1
```

### Search

```bash
wp vector options search <pattern> [--format=<format>]
```

```bash
wp vector options search site
```

```
Found 11 option(s) matching "site":

option_name                                                                   autoload  size_formatted
_site_transient_theme_roots                                                   off       121 B
_site_transient_timeout_theme_roots                                           off       10 B
_site_transient_timeout_wp_theme_files_patterns-2581ee9e6de1690f070ce590ea8d0069  off   10 B
_site_transient_update_plugins                                                off       1.61 KB
_site_transient_wp_theme_files_patterns-2581ee9e6de1690f070ce590ea8d0069      off       28.94 KB
_transient_wp_core_block_css_files                                            on        21.74 KB
cron                                                                          on        1.02 KB
fresh_site                                                                    off       1 B
ping_sites                                                                    on        27 B
site_icon                                                                     on        1 B
siteurl                                                                       on        21 B
```

### Size

```bash
wp vector options size [--format=<format>]
```

```
=== Options Table Summary ===
Total Options:     128
Total Size:        58.42 KB

Autoloaded:        100 options (26.25 KB, 44.9%)
Not Autoloaded:    28 options (32.17 KB, 55.1%)
```

---

## Security

```bash
wp vector security [--format=<format>]
```

```
=== Security Check Results ===

✗ Debug settings
  WP_DEBUG_DISPLAY is enabled
⚠ File editing
  DISALLOW_FILE_EDIT not set. Consider adding to wp-config.php.
⚠ SSL for admin
  FORCE_SSL_ADMIN not set. Consider enforcing SSL for admin.
✓ No default "admin" username
⚠ Table prefix
  Using default "wp_" table prefix.
✓ No exposed sensitive files
⚠ Directory indexing
  No index file in: wp-content/uploads
✗ File permissions
  wp-config.php permissions too open (644)
✓ Security keys configured
⚠ XML-RPC
  XML-RPC is enabled. Consider disabling if not needed.

Summary: 3 passed, 2 failed, 5 warnings

Warning: Security issues detected. Review and address the failed checks.
```

---

## Uploads Size

```bash
wp vector uploads-size [--format=<format>]
```

```
Uploads directory size: 0 B
Bytes: 0
```

---

## Users

### Admins

```bash
wp vector users admins [--format=<format>]
```

```
Found 1 user(s) with admin capabilities:

ID  user_login  user_email             roles          registered
1   priddle     priddle@priddle.local  administrator  2026-01-17 19:15:44
```

### Inactive

```bash
wp vector users inactive [--days=<days>] [--format=<format>]
```

```bash
wp vector users inactive --days=30
# Success: No users inactive for more than 30 days.
```

### Capabilities

```bash
wp vector users capabilities <user> [--format=<format>]
```

```bash
wp vector users capabilities 1
```

```
User: priddle (ID: 1)
Roles: administrator

capability           status
activate_plugins     granted
administrator        granted
create_users         granted
delete_others_pages  granted
delete_others_posts  granted
...

Total: 62 granted, 0 denied
```
