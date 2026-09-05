#!/usr/bin/env bash
#
# install-wp-tests.sh — fetch WordPress and the WordPress test suite for PHPUnit.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# wp-version accepts a version number ("6.5"), "latest", or "trunk".
# Used by CI. Locally, `npx wp-env` is the easier route; this script exists so
# CI does not need Docker-in-Docker.

set -euo pipefail

DB_NAME=${1-}
DB_USER=${2-}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
    exit 1
fi

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() { curl -sSL -o "$2" "$1"; }

# Resolve the version to a concrete tag, so the test suite and core match.
if [ "$WP_VERSION" = 'latest' ]; then
    download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    WP_VERSION=$(grep -o '"version":"[^"]*"' /tmp/wp-latest.json | head -1 | cut -d'"' -f4)
    echo "Resolved 'latest' to WordPress $WP_VERSION"
fi

if [ "$WP_VERSION" = 'trunk' ]; then
    WP_TESTS_TAG='trunk'
    ARCHIVE_URL='https://wordpress.org/nightly-builds/wordpress-latest.zip'
else
    # A two-part version such as "6.5" is served as "wordpress-6.5.tar.gz".
    WP_TESTS_TAG="branches/${WP_VERSION%.*}"
    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        WP_TESTS_TAG="tags/$WP_VERSION"
    elif [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
        WP_TESTS_TAG="tags/$WP_VERSION"
    fi
    ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        echo "WordPress already present at $WP_CORE_DIR"
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    echo "Downloading WordPress from $ARCHIVE_URL"
    if [[ "$ARCHIVE_URL" == *.zip ]]; then
        download "$ARCHIVE_URL" /tmp/wordpress.zip
        unzip -q /tmp/wordpress.zip -d /tmp/wp-unpack
        mv /tmp/wp-unpack/wordpress/* "$WP_CORE_DIR"
    else
        download "$ARCHIVE_URL" /tmp/wordpress.tar.gz
        tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    fi
    download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
    mkdir -p "$WP_TESTS_DIR"
    if [ ! -d "$WP_TESTS_DIR/includes" ]; then
        echo "Fetching the test suite ($WP_TESTS_TAG)"
        svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
        svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/"     "$WP_TESTS_DIR/data"
    fi

    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
        cfg="$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$cfg"
        sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$cfg"
        sed -i "s/yourusernamehere/$DB_USER/"        "$cfg"
        sed -i "s/yourpasswordhere/$DB_PASS/"        "$cfg"
        sed -i "s|localhost|${DB_HOST}|"             "$cfg"
    fi
}

create_db() {
    # Idempotent: a re-run on an existing database is not an error.
    local host="${DB_HOST%%:*}"
    local port="${DB_HOST##*:}"
    local portarg=""
    [ "$port" != "$DB_HOST" ] && portarg="--port=$port --protocol=tcp"
    # shellcheck disable=SC2086
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$host" $portarg 2>/dev/null \
        || echo "Database $DB_NAME already exists, continuing."
}

install_wp
install_test_suite
create_db

echo "WordPress $WP_VERSION and its test suite are ready."
echo "  core:  $WP_CORE_DIR"
echo "  tests: $WP_TESTS_DIR"
