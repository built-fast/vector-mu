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

### Standalone WP-CLI Usage

Commands can also be used without installing as an MU plugin via `--require`:

```bash
wp --require=/path/to/vector-mu/cli.php vector env
```

Or add to `wp-cli.yml` in your WordPress root:

```yaml
require:
  - /path/to/vector-mu/cli.php
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

### Cleanup

WP-CLI commands for cleaning up database bloat.

```bash
wp vector cleanup revisions              # Delete all revisions
wp vector cleanup revisions --keep=5     # Keep 5 revisions per post
wp vector cleanup transients             # Delete expired transients
wp vector cleanup trash                  # Empty trash
wp vector cleanup orphans                # Delete orphaned postmeta
wp vector cleanup all                    # Run all cleanup tasks
wp vector cleanup all --dry-run          # Preview what would be deleted
```

### Cron

WP-CLI commands for inspecting scheduled cron events.

```bash
wp vector cron                           # List scheduled events
wp vector cron --overdue                 # Show only overdue events
wp vector cron --hook=woocommerce        # Filter by hook name
wp vector cron status                    # Show cron system status
```

### Database

WP-CLI commands for database diagnostics.

```bash
wp vector db                             # Show database summary
wp vector db tables                      # List tables with sizes
wp vector db revisions                   # Count revisions per post
wp vector db transients                  # Show transient stats
wp vector db orphans                     # Find orphaned data
```

### Environment

WP-CLI command for displaying environment information.

```bash
wp vector env                            # Show PHP, WordPress, database, and server info
wp vector env --format=json
```

### Health

WP-CLI command for a quick site health summary.

```bash
wp vector health                         # Show site health overview
wp vector health --format=json
```

### Media

WP-CLI commands for media library diagnostics.

```bash
wp vector media                          # Show media library summary
wp vector media orphaned                 # Find files not in media library
wp vector media missing                  # Find attachments with missing files
wp vector media duplicates               # Find duplicate files
```

### Options

WP-CLI commands for inspecting wp_options.

```bash
wp vector options autoload               # List autoloaded options by size
wp vector options autoload --limit=50    # Show more options
wp vector options search <pattern>       # Search option names/values
wp vector options size                   # Show total options table size
```

### Security

WP-CLI command for security audits.

```bash
wp vector security                       # Run security checks
wp vector security --format=json
```

### Users

WP-CLI commands for user management diagnostics.

```bash
wp vector users admins                   # List all admin users
wp vector users inactive                 # Find inactive users
wp vector users capabilities             # Audit user capabilities
```

### VectorApi

REST API endpoints for Vector platform communication. See the [API](#api) section below for details.

## API

Vector MU provides REST API endpoints for authenticated communication between WordPress and the Vector platform. All API requests use HMAC signature verification.

### Authentication

API requests are authenticated using an `X-Signature` header with the format:

```
X-Signature: t={timestamp},v1={signature}
```

Where:
- `timestamp` is the Unix timestamp when the request was signed
- `signature` is `HMAC-SHA256("{timestamp}.{request_body}", hmac_secret)`

The HMAC secret is stored in the `vector_hmac_secret` WordPress option, which is provisioned automatically when the site is created.

### Endpoints

#### GET /wp-json/vector/v1/ping

Health check endpoint. Returns `"pong"` with status 200.

```bash
curl -X GET "https://example.com/wp-json/vector/v1/ping" \
  -H "X-Signature: t=1234567890,v1=abc123..."
```

Testing with `wp server`:

```bash
# Set HMAC secret (if not already configured)
wp option update vector_hmac_secret "your-secret-key"

# Test the endpoint (use ?rest_route= since wp server doesn't support rewrites)
S="your-secret-key"; T=$(date +%s); curl -i "http://localhost:9001/?rest_route=/vector/v1/ping" -H "X-Signature: t=$T,v1=$(echo -n "$T." | openssl dgst -sha256 -hmac "$S" | awk '{print $2}')"
```

### Making Outbound Requests

WordPress modules can make authenticated requests to the Vector API using `VectorApiClient`:

```php
use VectorMU\Api\VectorApiClient;

$client = new VectorApiClient();

// Check if HMAC secret is configured
if ( ! $client->isConfigured() ) {
    return;
}

// Make requests
$response = $client->get( 'sites/123/status' );
$response = $client->post( 'sites/123/sync', [ 'action' => 'full' ] );
$response = $client->put( 'sites/123', [ 'name' => 'updated' ] );
$response = $client->delete( 'sites/123/cache' );

// Handle response
if ( $response['success'] ) {
    $data = $response['data'];
    $statusCode = $response['status_code'];
} else {
    $error = $response['error'];
}
```

The client automatically:
- Retrieves the HMAC secret from the `vector_hmac_secret` option
- Signs requests with the `X-Signature` header
- Targets `https://api.builtfast.com/api/v1/vector/wordpress/*`

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
