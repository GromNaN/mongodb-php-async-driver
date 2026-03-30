#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DRIVER_TESTS="$REPO_ROOT/tests/references/mongo-php-driver/tests"
PREPEND_FILE="$REPO_ROOT/tests/Phpt/prepend.php"
RUN_TESTS="$REPO_ROOT/tests/references/mongo-php-driver/run-tests.php"

# Build absolute paths of tests to skip
SKIP_PATHS=$(php -r "
\$skip = require '$REPO_ROOT/tests/Phpt/skip_list.php';
foreach (array_keys(\$skip) as \$rel) {
    \$abs = realpath('$DRIVER_TESTS/' . \$rel);
    if (\$abs) echo \$abs . PHP_EOL;
}
")

# Write filtered test list to a temp file
TMPFILE=$(mktemp)
trap 'rm -f "$TMPFILE"' EXIT

find "$DRIVER_TESTS" -name '*.phpt' -type f | sort | while IFS= read -r f; do
    if ! printf '%s\n' "$SKIP_PATHS" | grep -qxF "$f"; then
        echo "$f"
    fi
done > "$TMPFILE"

TOTAL=$(wc -l < "$TMPFILE" | tr -d ' ')
SKIPPED=$(printf '%s\n' "$SKIP_PATHS" | grep -c . || true)
echo "Running $TOTAL phpt tests ($SKIPPED skipped)"

TEST_PHP_EXECUTABLE=$(which php) \
php "$RUN_TESTS" \
    -P \
    -q \
    -d "auto_prepend_file=$PREPEND_FILE" \
    -r "$TMPFILE"