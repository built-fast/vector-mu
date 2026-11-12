#!/usr/bin/env bash
#
# Installs the release artifact into a real WordPress and checks that it loads.
#
#   tests/integration/wordpress.sh [vector-mu-X.Y.Z.tar.gz]
#
# Without an artifact it builds one from HEAD with bin/build-release. It
# installs a pinned WordPress with a pinned, checksum-verified WP-CLI, puts the
# artifact in wp-content/mu-plugins/ with mu-loader.php as vector-mu.php, and
# runs WordPress with no YMIR_* or AWS_* variables:
#
# - VECTOR_RUNTIME=dev: vector-mu boots as dev, the Ymir fork isn't loaded, the
#   homepage returns 200 with no PHP warnings or fatals, and an imported upload
#   has a local URL and is served from disk.
# - VECTOR_RUNTIME unset: vector-mu boots as lambda, the fork loads (inert
#   without YMIR_ENVIRONMENT), and the homepage returns 200 with no warnings or
#   fatals.
# - VECTOR_RUNTIME set to an unknown value: logged, and treated as dev.
#
# Needs php (with mysqli), curl, a MySQL server, and what bin/build-release
# needs when no artifact is given. Settings (environment):
#
#   VECTOR_MU_TEST_DB_HOST      default 127.0.0.1
#   VECTOR_MU_TEST_DB_PORT      default 3306
#   VECTOR_MU_TEST_DB_USER      default root
#   VECTOR_MU_TEST_DB_PASSWORD  default root
#   VECTOR_MU_TEST_DB_NAME      default vector_mu_test (dropped and recreated)
#   VECTOR_MU_TEST_PORT         default 8089 (php -S)
#   VECTOR_MU_TEST_KEEP=1       keep the work directory
# shellcheck disable=SC2016 # single-quoted PHP code
set -euo pipefail

WP_VERSION="7.1.4"
WP_CLI_VERSION="2.12.0"
WP_CLI_SHA256="ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c"

DB_HOST="${VECTOR_MU_TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${VECTOR_MU_TEST_DB_PORT:-3306}"
DB_USER="${VECTOR_MU_TEST_DB_USER:-root}"
DB_PASSWORD="${VECTOR_MU_TEST_DB_PASSWORD:-root}"
DB_NAME="${VECTOR_MU_TEST_DB_NAME:-vector_mu_test}"
PORT="${VECTOR_MU_TEST_PORT:-8089}"
SITE_URL="http://127.0.0.1:${PORT}"

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

WORK_DIR="$(cd "$(mktemp -d)" && pwd -P)"
WP_DIR="$WORK_DIR/wordpress"
WP_CLI="$WORK_DIR/wp-cli.phar"
SERVER_PID=""

cleanup() {
	stop_server
	if [[ "${VECTOR_MU_TEST_KEEP:-}" == "1" ]]; then
		echo "Kept $WORK_DIR"
	else
		rm -rf "$WORK_DIR"
	fi
}
trap cleanup EXIT

FAILURES=0

pass() { echo "ok - $*"; }
fail() {
	echo "not ok - $*" >&2
	FAILURES=$((FAILURES + 1))
}
die() {
	echo "wordpress.sh: $*" >&2
	exit 1
}

sha256() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	else
		shasum -a 256 "$1" | awk '{ print $1 }'
	fi
}

# Runs a command with a minimal environment: no YMIR_* or AWS_* variables,
# and VECTOR_RUNTIME only when $RUNTIME is set.
clean_env() {
	local -a vars=("PATH=$PATH" "HOME=$WORK_DIR" "WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1")
	if [[ -n "${RUNTIME+x}" ]]; then
		vars+=("VECTOR_RUNTIME=$RUNTIME")
	fi
	env -i "${vars[@]}" "$@"
}

# PHP notices go to stderr so they can't mix into captured output, and WP-CLI's
# own deprecations on newer PHP are hidden.
wp() {
	clean_env php -d display_errors=stderr -d error_reporting='E_ALL & ~E_DEPRECATED' "$WP_CLI" --path="$WP_DIR" "$@"
}

start_server() {
	: >"$WORK_DIR/php-errors.log"
	clean_env php \
		-d display_errors=0 \
		-d log_errors=1 \
		-d error_log="$WORK_DIR/php-errors.log" \
		-S "127.0.0.1:${PORT}" -t "$WP_DIR" >"$WORK_DIR/server.log" 2>&1 &
	SERVER_PID=$!

	local _
	for _ in $(seq 1 50); do
		if curl -s -o /dev/null "$SITE_URL/wp-includes/images/blank.gif"; then
			return 0
		fi
		sleep 0.1
	done
	cat "$WORK_DIR/server.log" >&2
	die "php -S did not start on $SITE_URL"
}

stop_server() {
	if [[ -n "$SERVER_PID" ]]; then
		kill "$SERVER_PID" 2>/dev/null || true
		wait "$SERVER_PID" 2>/dev/null || true
		SERVER_PID=""
	fi
}

# Requests the homepage and checks the status and the PHP error log.
check_homepage() {
	local label="$1" status
	status="$(curl -s -o "$WORK_DIR/home.html" -w '%{http_code}' "$SITE_URL/")"
	if [[ "$status" == "200" ]]; then
		pass "$label: homepage returns 200"
	else
		fail "$label: homepage returned $status"
	fi

	if grep -Eiq 'PHP (Warning|Fatal error|Parse error)|critical error' "$WORK_DIR/php-errors.log" "$WORK_DIR/home.html"; then
		fail "$label: PHP warnings or fatals"
		cat "$WORK_DIR/php-errors.log" >&2
	else
		pass "$label: no PHP warnings or fatals"
	fi
}

check_eq() {
	local label="$1" got="$2" want="$3"
	if [[ "$got" == "$want" ]]; then
		pass "$label"
	else
		fail "$label: got '$got', want '$want'"
	fi
}

# What a request sees: vector-mu's runtime, and whether the fork loaded.
BOOT_PROBE='
echo defined( "VECTOR_MU_RUNTIME" ) ? VECTOR_MU_RUNTIME : "not-booted", " ";
$fork = false;
foreach ( get_included_files() as $file ) {
	if ( str_ends_with( $file, "/vector-wordpress-plugin/vector-wordpress-plugin.php" ) ) {
		$fork = true;
	}
}
echo ( $fork || class_exists( "Ymir\\Plugin\\Plugin", false ) ) ? "fork-loaded" : "fork-absent";
'

# --- Artifact ----------------------------------------------------------------

if [[ $# -ge 1 ]]; then
	ARTIFACT="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
	[[ -f "$ARTIFACT" ]] || die "no artifact at $1"
else
	version="$(sed -n "s/^[[:space:]]*public const VERSION = '\([^']*\)';.*/\1/p" "$PROJECT_DIR/src/Plugin.php")"
	"$PROJECT_DIR/bin/build-release" "$version" "$WORK_DIR/dist"
	ARTIFACT="$WORK_DIR/dist/vector-mu-${version}.tar.gz"
fi
echo "Testing $ARTIFACT"

# --- WordPress ---------------------------------------------------------------

curl -sSfL -o "$WP_CLI" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
[[ "$(sha256 "$WP_CLI")" == "$WP_CLI_SHA256" ]] || die "WP-CLI ${WP_CLI_VERSION} checksum mismatch"

php -r '
	$db = new mysqli( $argv[1], $argv[2], $argv[3], "", (int) $argv[4] );
	$name = $db->real_escape_string( $argv[5] );
	$db->query( "DROP DATABASE IF EXISTS `$name`" );
	$db->query( "CREATE DATABASE `$name`" );
' "$DB_HOST" "$DB_USER" "$DB_PASSWORD" "$DB_PORT" "$DB_NAME"

mkdir -p "$WP_DIR"
wp core download --version="$WP_VERSION" --locale=en_US --skip-content --quiet
wp core verify-checksums --quiet
wp config create \
	--dbname="$DB_NAME" \
	--dbuser="$DB_USER" \
	--dbpass="$DB_PASSWORD" \
	--dbhost="${DB_HOST}:${DB_PORT}" \
	--skip-check \
	--quiet
wp core install \
	--url="$SITE_URL" \
	--title="vector-mu test" \
	--admin_user=admin \
	--admin_password="$(php -r 'echo bin2hex( random_bytes( 16 ) );')" \
	--admin_email=admin@example.com \
	--skip-email \
	--quiet
# wp_install() guesses siteurl from the request path; pin both URLs.
wp option update siteurl "$SITE_URL" --quiet
wp option update home "$SITE_URL" --quiet

# A theme to render the homepage with (--skip-content leaves none).
mkdir -p "$WP_DIR/wp-content/themes/vector-mu-test"
cat >"$WP_DIR/wp-content/themes/vector-mu-test/style.css" <<'EOF'
/*
Theme Name: vector-mu test
*/
EOF
echo '<?php echo "vector-mu test theme";' >"$WP_DIR/wp-content/themes/vector-mu-test/index.php"
wp theme activate vector-mu-test --quiet

# --- Install the artifact ----------------------------------------------------

MU_DIR="$WP_DIR/wp-content/mu-plugins"
mkdir -p "$MU_DIR"
tar -xzf "$ARTIFACT" -C "$MU_DIR"
cp "$MU_DIR/vector-mu/mu-loader.php" "$MU_DIR/vector-mu.php"

# --- VECTOR_RUNTIME=dev ------------------------------------------------------

RUNTIME=dev
check_eq "dev: vector-mu boots as dev without the fork" "$(wp eval "$BOOT_PROBE")" "dev fork-absent"

start_server
check_homepage "dev"

printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' |
	php -r 'echo base64_decode( stream_get_contents( STDIN ) );' >"$WORK_DIR/pixel.png"
attachment_id="$(wp media import "$WORK_DIR/pixel.png" --porcelain)"
upload_url="$(wp eval "echo wp_get_attachment_url( $attachment_id );")"
upload_file="$(wp eval "echo get_attached_file( $attachment_id );")"

case "$upload_url" in
"$SITE_URL/wp-content/uploads/"*) pass "dev: upload URL is local ($upload_url)" ;;
*) fail "dev: upload URL is not local: $upload_url" ;;
esac

case "$upload_file" in
"$WP_DIR/wp-content/uploads/"*)
	if [[ -f "$upload_file" ]]; then
		pass "dev: upload is on disk"
	else
		fail "dev: upload missing at $upload_file"
	fi
	;;
*) fail "dev: upload is not in the local uploads dir: $upload_file" ;;
esac

status="$(curl -s -o "$WORK_DIR/served.png" -w '%{http_code}' "$upload_url")"
if [[ "$status" == "200" ]] && cmp -s "$WORK_DIR/served.png" "$WORK_DIR/pixel.png"; then
	pass "dev: upload is served from disk"
else
	fail "dev: upload request returned $status or different bytes"
fi
check_homepage "dev (after upload)"
stop_server

# --- VECTOR_RUNTIME unset (lambda) -------------------------------------------

unset RUNTIME
check_eq "unset: vector-mu boots as lambda with the fork" "$(wp eval "$BOOT_PROBE")" "lambda fork-loaded"

start_server
check_homepage "unset"
stop_server

# --- Unknown VECTOR_RUNTIME --------------------------------------------------

RUNTIME=bogus
probe="$(wp eval "$BOOT_PROBE" 2>"$WORK_DIR/bogus.err")"
check_eq "unknown: vector-mu treats it as dev" "$probe" "dev fork-absent"
if grep -q 'unknown VECTOR_RUNTIME value "bogus"' "$WORK_DIR/bogus.err"; then
	pass "unknown: the value is logged"
else
	fail "unknown: no log line"
	cat "$WORK_DIR/bogus.err" >&2
fi

if [[ "$FAILURES" -gt 0 ]]; then
	die "$FAILURES check(s) failed"
fi
echo "All checks passed"
