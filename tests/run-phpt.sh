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

if [ $# -gt 0 ]; then
    # Glob(s) passed as arguments.
    # Strategy: expand the pattern as-is first (handles shell-expanded paths
    # and absolute globs). If nothing matches, retry relative to $DRIVER_TESTS
    # (handles quoted globs like 'bson/bson-*.phpt').
    for pattern in "$@"; do
        matched=0
        for f in $pattern; do
            [ -f "$f" ] || continue
            matched=1
            abs_f="$(cd "$(dirname "$f")" && pwd)/$(basename "$f")"
            if ! printf '%s\n' "$SKIP_PATHS" | grep -qxF "$abs_f"; then
                echo "$abs_f"
            fi
        done
        if [ $matched -eq 0 ]; then
            for f in $DRIVER_TESTS/$pattern; do
                [ -f "$f" ] || continue
                if ! printf '%s\n' "$SKIP_PATHS" | grep -qxF "$f"; then
                    echo "$f"
                fi
            done
        fi
    done | sort -u > "$TMPFILE"
else
    find "$DRIVER_TESTS" -name '*.phpt' -type f | sort | while IFS= read -r f; do
        if ! printf '%s\n' "$SKIP_PATHS" | grep -qxF "$f"; then
            echo "$f"
        fi
    done > "$TMPFILE"
fi

TOTAL=$(wc -l < "$TMPFILE" | tr -d ' ')
SKIPPED=$(printf '%s\n' "$SKIP_PATHS" | grep -c . || true)
echo "Running $TOTAL phpt tests ($SKIPPED skipped)"

TEST_PHP_EXECUTABLE=$(which php) \
php "$RUN_TESTS" \
    -P \
    -q \
    -d "auto_prepend_file=$PREPEND_FILE" \
    -r "$TMPFILE"