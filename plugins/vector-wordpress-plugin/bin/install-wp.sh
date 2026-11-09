#!/usr/bin/env bash
#
# Download WordPress core for the unit test suite (see tests/bootstrap.php).
# Adapted from the upstream Ymir CI script.
#
# Usage: bin/install-wp.sh [version]   (default: latest; WP_CORE_DIR defaults to /tmp/wordpress)

set -euo pipefail

WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
WP_VERSION=${1:-latest}

if [[ -d "$WP_CORE_DIR/wp-includes" ]]; then
	exit 0
fi

if [[ "$WP_VERSION" == 'latest' ]]; then
	ARCHIVE_NAME='latest'
else
	ARCHIVE_NAME="wordpress-$WP_VERSION"
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$WP_CORE_DIR"
curl -sSfL -o "$TMP_DIR/wordpress.tar.gz" "https://wordpress.org/${ARCHIVE_NAME}.tar.gz"
tar --strip-components=1 -zxmf "$TMP_DIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
