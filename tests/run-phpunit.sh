#!/usr/bin/env bash
# Run phpunit with PHP_INI_SCAN_DIR="" (to disable ext-mongodb).
#
# Usage:
#   tests/run-phpunit.sh [phpunit-args...]
#   tests/run-phpunit.sh mongodb://... [phpunit-args...]
#
# If the first argument starts with "mongodb://", it is set as MONGODB_URI.
# All remaining (or all) arguments are forwarded to ./vendor/bin/phpunit.
#
# Examples:
#   tests/run-phpunit.sh --testsuite unit
#   tests/run-phpunit.sh mongodb://127.0.0.1:27017/ --testsuite integration
#   tests/run-phpunit.sh mongodb://127.0.0.1:PORT/?replicaSet=rs \
#       -c tests/references/mongo-php-library/phpunit.xml.dist \
#       tests/references/mongo-php-library/tests/Operation/

set -euo pipefail

PHPUNIT="./vendor/bin/phpunit"

if [[ "${1:-}" == mongodb://* ]]; then
    MONGODB_URI="$1"
    shift
    exec env PHP_INI_SCAN_DIR="" MONGODB_URI="$MONGODB_URI" "$PHPUNIT" "$@"
else
    exec env PHP_INI_SCAN_DIR="" "$PHPUNIT" "$@"
fi
