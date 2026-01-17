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

### ChangeDomain

WP-CLI command for changing the site domain. Handles http/https and www/non-www variants using regex search-replace.

```bash
wp change-domain old.example.com new.example.com
wp change-domain old.example.com new.example.com --dry-run
```

### Blueprint

WP-CLI commands for exporting and importing WordPress sites as portable archives. Useful for migrating configured sites between Vector instances.

**Export a site:**

```bash
wp vector blueprint export site-backup.tar.gz
wp vector blueprint export site-backup.tar.gz --include-uploads --max-upload-size=100M
wp vector blueprint export site-backup.tar.gz --include-plugins=my-plugin,another-plugin
wp vector blueprint export site-backup.tar.gz --include-themes=my-theme
wp vector blueprint export site-backup.tar.gz --exclude-tables=wp_sessions,wp_logs
```

**Import a site:**

```bash
wp vector blueprint import site-backup.tar.gz --target-url=https://newsite.com
wp vector blueprint import site-backup.tar.gz --target-url=https://newsite.com --dry-run
wp vector blueprint import site-backup.tar.gz --target-url=https://newsite.com --skip-database
wp vector blueprint import site-backup.tar.gz --target-url=https://newsite.com --force
```

**Validate an archive:**

```bash
wp vector blueprint validate site-backup.tar.gz
```

### UploadsSize

WP-CLI command for checking the size of the wp-content/uploads directory.

```bash
wp vector uploads-size
wp vector uploads-size --format=json
```

## Vector Partner Workflow

Example workflow for migrating a configured WordPress site to a new Vector instance:

**1. Export from your source site:**

```bash
# SSH to your source WordPress server
ssh user@source-server.com

# Export the site (adjust options as needed)
cd /path/to/wordpress
wp vector blueprint export /tmp/my-site.tar.gz --include-plugins=my-custom-plugin --include-themes=my-theme

# Check uploads size before including
wp vector uploads-size
# If acceptable, include uploads:
wp vector blueprint export /tmp/my-site.tar.gz --include-uploads --max-upload-size=500M
```

**2. Upload the archive to a publicly accessible URL:**

```bash
# Upload to S3, your own server, or any accessible location
aws s3 cp /tmp/my-site.tar.gz s3://my-bucket/my-site.tar.gz --acl public-read
```

**3. Create a new Vector site with the archive:**

```bash
curl -X POST "https://api.builtfast.com/api/v1/vector/sites?archive_url=https://my-bucket.s3.amazonaws.com/my-site.tar.gz" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json"
```

**4. SSH to your Vector dev container and import:**

```bash
# SSH to your new Vector container
ssh vector@your-container.builtfast.com

# Import the blueprint
cd /var/www/html
wp vector blueprint import /tmp/my-site.tar.gz --target-url=https://your-new-site.com

# Verify the import
wp option get siteurl
wp option get home
```

## Development

**Note:** Development requires PHP 8.1 to ensure `composer.lock` stays compatible with all supported versions.

```bash
composer test       # Run tests
composer lint       # Check code style
composer lint:fix   # Fix code style
composer analyze    # Run static analysis
```

<details>
<summary>Setting up PHP 8.1 with Homebrew or Herd + direnv</summary>

Add one of these helper functions to your `~/.config/direnv/direnvrc`:

**Homebrew:**

```bash
# Sets PATH for Homebrew core and shivammathur/php taps
#
# Usage in .envrc:
#
#   use homebrew_php
#   use homebrew_php 8.2
use_homebrew_php() {
  local version="$1"
  local optdir="${HOMEBREW_PREFIX-/opt/homebrew}/opt/php${version:+"@$version"}"

  if [[ -d "$optdir" ]]; then
    PATH_add "$optdir/bin"
    PATH_add "$optdir/sbin"
    MANPATH_add "$optdir/share/man"
  else
    if [[ -z "$version" ]]; then
      log_error "Homebrew PHP not found"
      log_error "Try \`brew install php\` or \`brew install shivammathur/php/php@version\`"
    else
      log_error "Homebrew PHP version '$version' not found"
      log_error "Try \`brew install shivammathur/php/php@$version\`"
    fi
    return 1
  fi
}
```

**Herd:**

```bash
# Sets PATH for Herd PHP versions.
#
# Usage in .envrc:
#
#   use herd_php [system]
#   use herd_php 8.3
use_herd_php() {
  local herd_dir="$HOME/Library/Application Support/Herd"

  if [[ ! -d "$herd_dir" ]]; then
    log_error "Herd not found"
    return 1
  fi

  local version="${1-}"
  local shim_dir="$HOME/.local/share/herd"

  if [[ -z "$version" ]] && [[ -f .php-version ]]; then
    version=$(< .php-version)
    log_status "Using PHP version from .php-version: $version"
  fi

  local number

  if [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    version="${version%.*}" # only major.minor
    number="${version/./}"
  elif [[ "$version" =~ ^[0-9]+\.[0-9]+$ ]]; then
    number="${version/./}"
  elif [[ "$version" =~ ^(system|default)$ ]] || [[ -z "$version" ]]; then
    version="default"
    number=""
  else
    log_error "Invalid PHP version '$version'"
    log_error "Use 'system', 'default' or a version like '8.3' or '8.3.1'"

    return 1
  fi

  local target_bin="$herd_dir/bin/php${number}"

  if [[ ! -x "$target_bin" ]]; then
    log_error "Herd PHP $version binary '$target_bin' not found"
    log_error "Try one of these .envrc configurations:"
    log_error
    log_error "- use_herd_php system (Herd default configured version)"

    local suggested

    for f in "$herd_dir"/bin/*; do
      if [[ "${f##*/}" =~ ^php[0-9]+$ ]]; then
        suggested="${f##*/php}"
        suggested="${suggested:0:1}.${suggested:1}"

        log_error "- use_herd_php $suggested"
      fi
    done

    return 1
  fi

  local target_shim="$shim_dir/php$number/bin/php"

  if [[ ! -L "$target_shim" ]]; then
    log_status "Creating Herd PHP $version shim at ${target_shim/$HOME/\~}"

    mkdir -p "${target_shim%/*}"

    ln -s "$target_bin" "$target_shim"
  fi

  PATH_add "$shim_dir/php$number/bin"

  if [[ -d vendor/bin ]]; then
    PATH_add vendor/bin
  fi

  log_status "using Herd PHP $version shim at ${target_shim/$HOME/\~}"
}
```

Then create a `.envrc` in the project root:

```bash
# Homebrew
use homebrew_php 8.1

# Or Herd
use herd_php 8.1
```

Run `direnv allow` to activate.

</details>

## Adding Modules

1. Create a class in `src/Modules/` implementing `ModuleInterface`
2. Add the class to the `MODULES` array in `src/Plugin.php`
