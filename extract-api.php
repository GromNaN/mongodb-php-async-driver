<?php

declare(strict_types=1);

/**
 * Extract public API from PHP source files.
 *
 * Usage:
 *   php extract-api.php <directory> [<directory> ...]
 *
 * Output: sorted list of fully-qualified class/interface/trait/enum names,
 *         their public constants and public methods, plus top-level functions.
 *         Items tagged @internal are excluded, as is the Internal namespace.
 */

if ($argc < 2) {
    fprintf(STDERR, "Usage: %s <directory> [<directory> ...]\n", $argv[0]);
    exit(1);
}

$entries = [];

foreach (array_slice($argv, 1) as $dir) {
    $dir = rtrim($dir, '/');
    if (!is_dir($dir)) {
        fprintf(STDERR, "Not a directory: %s\n", $dir);
        exit(1);
    }
    extractFromDir($dir, $entries);
}

sort($entries);
echo implode("\n", array_unique($entries)) . "\n";

// ---------------------------------------------------------------------------

function extractFromDir(string $dir, array &$entries): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $filename = $file->getFilename();
        if (!str_ends_with($filename, '.php') && !str_ends_with($filename, '.stub.php')) {
            continue;
        }
        extractFromFile($file->getPathname(), $entries);
    }
}

function extractFromFile(string $path, array &$entries): void
{
    $src = file_get_contents($path);
    $tokens = token_get_all($src);
    $n = count($tokens);

    $namespace = '';
    // Stack entries: [fqcn, isInternal, openBraceDepth|null]
    // openBraceDepth is the $braceDepth value at the time the class body '{' is seen.
    $classStack = [];
    $braceDepth = 0;

    $i = 0;
    while ($i < $n) {
        $tok = $tokens[$i];
        $tokType = is_array($tok) ? $tok[0] : null;
        $tokVal  = is_array($tok) ? $tok[1] : $tok;

        // ── skip whitespace / comments quickly ───────────────────────────
        if ($tokType === T_WHITESPACE || $tokType === T_COMMENT) {
            $i++;
            continue;
        }

        // ── track braces ─────────────────────────────────────────────────
        if ($tokVal === '{' || $tokType === T_CURLY_OPEN || $tokType === T_DOLLAR_OPEN_CURLY_BRACES) {
            $braceDepth++;
            // If top of classStack has not yet been assigned a depth, assign now
            if (!empty($classStack) && $classStack[count($classStack) - 1][2] === null) {
                $classStack[count($classStack) - 1][2] = $braceDepth;
            }
            $i++;
            continue;
        }

        if ($tokVal === '}') {
            // Pop class if we're closing its body
            if (!empty($classStack) && $classStack[count($classStack) - 1][2] === $braceDepth) {
                array_pop($classStack);
            }
            $braceDepth--;
            $i++;
            continue;
        }

        // ── namespace ────────────────────────────────────────────────────
        if ($tokType === T_NAMESPACE) {
            $i++;
            $namespace = '';
            while ($i < $n) {
                $t = $tokens[$i];
                $tv = is_array($t) ? $t[1] : $t;
                $tt = is_array($t) ? $t[0] : null;
                if ($tt === T_NAME_QUALIFIED || $tt === T_STRING) {
                    $namespace .= $tv;
                    $i++;
                } elseif ($tt === T_NS_SEPARATOR) {
                    $namespace .= '\\';
                    $i++;
                } elseif ($tv === ';' || $tv === '{') {
                    // Handle namespace blocks: `namespace Foo { ... }` resets on '}'
                    // For simplicity treat namespace blocks as non-bracketed
                    if ($tv === '{') {
                        // Don't count this as a brace for our brace depth
                        // Actually we need to — but namespace blocks are rare here; just break
                        $braceDepth++;
                    }
                    break;
                } else {
                    $i++;
                }
            }
            continue;
        }

        // ── use function / use const — skip ──────────────────────────────
        if ($tokType === T_USE) {
            // Peek ahead (skip whitespace) to check for T_FUNCTION or T_CONST
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n) {
                $nextType = is_array($tokens[$j]) ? $tokens[$j][0] : null;
                if ($nextType === T_FUNCTION || $nextType === T_CONST) {
                    // skip to ';' or end of use block
                    while ($i < $n && (is_string($tokens[$i]) ? $tokens[$i] : '') !== ';') {
                        $i++;
                    }
                    $i++; // past ';'
                    continue;
                }
            }
            $i++;
            continue;
        }

        // ── class / interface / trait / enum ─────────────────────────────
        if (in_array($tokType, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // Ensure it's not `::class` (class keyword used as constant)
            // Check preceding non-whitespace token is not '::'
            $prev = precedingNonWhitespace($tokens, $i);
            if ($prev !== null && is_string($prev) && $prev === '::') {
                // ::class constant — skip
                $i++;
                continue;
            }

            $docblock = collectPrecedingDocblock($tokens, $i);
            $internal = isInternal($docblock);

            // anonymous class: `new class` — skip
            if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                $i++;
                continue;
            }

            $keyword = $tokVal; // 'class', 'interface', 'trait', 'enum'

            // find the class name (T_STRING immediately following keyword + optional extends/implements)
            $j = $i + 1;
            $name = '';
            while ($j < $n) {
                $t = $tokens[$j];
                $tv = is_array($t) ? $t[1] : $t;
                $tt = is_array($t) ? $t[0] : null;
                if ($tt === T_STRING) {
                    $name = $tv;
                    break;
                }
                if ($tv === '{' || $tv === ';') {
                    break;
                }
                $j++;
            }

            if ($name === '') {
                $i++;
                continue;
            }

            $fqcn = ($namespace !== '' ? $namespace . '\\' : '') . $name;

            // Skip Internal namespace
            if (str_contains($fqcn, '\\Internal\\') || str_starts_with($fqcn, 'Internal\\')) {
                // still need to track braces to not confuse depth
                $classStack[] = [$fqcn, true, null];
                $i = $j + 1;
                continue;
            }

            if (!$internal) {
                $entries[] = $keyword . ' ' . $fqcn;
            }

            $classStack[] = [$fqcn, $internal, null];
            $i = $j + 1;
            continue;
        }

        // ── const ─────────────────────────────────────────────────────────
        if ($tokType === T_CONST) {
            $docblock = collectPrecedingDocblock($tokens, $i);
            $internal = isInternal($docblock);

            // Also skip if we're in an internal class
            $classInternal = !empty($classStack) && $classStack[count($classStack) - 1][1];

            // Inside a class, skip private/protected constants
            $inClass = !empty($classStack);
            $publicConst = !$inClass || precedingModifierIsPublic($tokens, $i) || !precedingModifierIsNonPublic($tokens, $i);

            if (!$internal && !$classInternal && $publicConst) {
                // Skip optional type tokens after `const` to find the actual name
                // e.g. `const string FOO = ` or `const int BAR = `
                $j = $i + 1;
                $constName = '';
                $candidate = '';
                while ($j < $n) {
                    $t = $tokens[$j];
                    $tv = is_array($t) ? $t[1] : $t;
                    $tt = is_array($t) ? $t[0] : null;
                    if ($tt === T_WHITESPACE) {
                        $j++;
                        continue;
                    }
                    if ($tv === ';' || $tv === '{') {
                        break;
                    }
                    // '=' or ',' means candidate is the const name
                    if ($tv === '=' || $tv === ',') {
                        $constName = $candidate;
                        break;
                    }
                    if ($tt === T_STRING) {
                        $candidate = $tv;
                    } elseif ($tt === T_NS_SEPARATOR || $tt === T_NAME_QUALIFIED) {
                        $candidate = ''; // part of a type, reset
                    }
                    $j++;
                }
                if ($constName !== '') {
                    $context = currentContext($classStack, $namespace);
                    $entries[] = 'const ' . $context . $constName;
                }
            }
            $i++;
            continue;
        }

        // ── property ──────────────────────────────────────────────────────
        if ($tokType === T_VARIABLE && !empty($classStack)) {
            $classInternal = $classStack[count($classStack) - 1][1];
            if (!$classInternal && precedingModifierIsPublic($tokens, $i)) {
                $docblock = collectPrecedingDocblock($tokens, $i);
                if (!isInternal($docblock)) {
                    $context = currentContext($classStack, $namespace);
                    $entries[] = 'property ' . $context . $tokVal;
                }
            }
            $i++;
            continue;
        }

        // ── function ──────────────────────────────────────────────────────
        if ($tokType === T_FUNCTION) {
            $inClass = !empty($classStack);
            $classInternal = $inClass && $classStack[count($classStack) - 1][1];

            if ($inClass) {
                // Must be public and neither the class nor the method is @internal
                $docblock = collectPrecedingDocblock($tokens, $i);
                $methodInternal = isInternal($docblock);
                $public = precedingModifierIsPublic($tokens, $i);
                if (!$public || $methodInternal || $classInternal) {
                    $i++;
                    continue;
                }
            } else {
                $docblock = collectPrecedingDocblock($tokens, $i);
                if (isInternal($docblock)) {
                    $i++;
                    continue;
                }
            }

            // Get function name: skip optional & then T_STRING
            $j = $i + 1;
            $fnName = '';
            while ($j < $n) {
                $t = $tokens[$j];
                $tv = is_array($t) ? $t[1] : $t;
                $tt = is_array($t) ? $t[0] : null;
                if ($tt === T_WHITESPACE) {
                    $j++;
                    continue;
                }
                if ($tt === T_STRING) {
                    $fnName = $tv;
                    break;
                }
                // `&` for return-by-reference
                if ($tv === '&') {
                    $j++;
                    continue;
                }
                // `(` means anonymous function/closure
                break;
            }

            if ($fnName === '') {
                // anonymous function / closure — skip
                $i++;
                continue;
            }

            $context = currentContext($classStack, $namespace);
            if ($inClass) {
                $entries[] = 'method ' . $context . $fnName . '()';
            } else {
                $entries[] = 'function ' . $context . $fnName . '()';
            }

            $i = $j + 1;
            continue;
        }

        $i++;
    }
}

/**
 * Walk backwards from $pos to find the nearest doc-comment, skipping
 * whitespace, modifiers, and attributes.
 */
function collectPrecedingDocblock(array $tokens, int $pos): string
{
    $modifiers = [
        T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT,
        T_FINAL, T_READONLY, T_WHITESPACE, T_COMMENT,
    ];

    $i = $pos - 1;
    while ($i >= 0) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], $modifiers, true)) {
            $i--;
            continue;
        }
        // skip attribute #[...]  / ]
        if (is_string($t) && $t === ']') {
            $depth = 1;
            $i--;
            while ($i >= 0 && $depth > 0) {
                $t2 = $tokens[$i];
                $tv2 = is_array($t2) ? $t2[1] : $t2;
                if ($tv2 === ']') {
                    $depth++;
                } elseif ($tv2 === '[') {
                    $depth--;
                }
                $i--;
            }
            // skip T_ATTRIBUTE (#[)
            if ($i >= 0 && is_array($tokens[$i]) && $tokens[$i][0] === T_ATTRIBUTE) {
                $i--;
            }
            continue;
        }
        if (is_array($t) && $t[0] === T_DOC_COMMENT) {
            return $t[1];
        }
        break;
    }
    return '';
}

function isInternal(string $docblock): bool
{
    return str_contains($docblock, '@internal');
}

/**
 * Walk backwards skipping whitespace, type tokens (for typed properties/consts),
 * and modifier keywords. Returns [foundPublic, foundNonPublic].
 */
function scanPrecedingModifiers(array $tokens, int $pos): array
{
    $modifiers = [
        T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT,
        T_FINAL, T_READONLY,
    ];
    // Tokens that can appear as part of a type between modifiers and the name/variable
    $typeTokens = [
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR,
        T_ARRAY, T_CALLABLE, T_STATIC,
    ];

    $foundPublic    = false;
    $foundNonPublic = false;
    $i = $pos - 1;
    while ($i >= 0) {
        $t  = $tokens[$i];
        $tt = is_array($t) ? $t[0] : null;
        $tv = is_array($t) ? $t[1] : $t;

        if ($tt === T_WHITESPACE) {
            $i--;
            continue;
        }
        // Skip type tokens (typed property/const: `public string $x`, `public const int FOO`)
        if ($tt !== null && in_array($tt, $typeTokens, true)) {
            $i--;
            continue;
        }
        // `?` for nullable types, `|` and `&` for union/intersection types
        if ($tv === '?' || $tv === '|' || $tv === '&') {
            $i--;
            continue;
        }
        if ($tt !== null && in_array($tt, $modifiers, true)) {
            if ($tt === T_PUBLIC) {
                $foundPublic = true;
            } elseif ($tt === T_PROTECTED || $tt === T_PRIVATE) {
                $foundNonPublic = true;
            }
            $i--;
            continue;
        }
        break;
    }
    return [$foundPublic, $foundNonPublic];
}

/**
 * Returns true if T_PRIVATE or T_PROTECTED precedes the token at $pos.
 */
function precedingModifierIsNonPublic(array $tokens, int $pos): bool
{
    [, $nonPublic] = scanPrecedingModifiers($tokens, $pos);
    return $nonPublic;
}

/**
 * Walk backwards from $pos to find the preceding visibility modifiers.
 * Returns true only if T_PUBLIC is present (and not T_PRIVATE/T_PROTECTED).
 */
function precedingModifierIsPublic(array $tokens, int $pos): bool
{
    [$public, $nonPublic] = scanPrecedingModifiers($tokens, $pos);
    return $public && !$nonPublic;
}

/**
 * Return the previous non-whitespace token before position $pos.
 */
function precedingNonWhitespace(array $tokens, int $pos): array|string|null
{
    $i = $pos - 1;
    while ($i >= 0) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_WHITESPACE) {
            $i--;
            continue;
        }
        return $t;
    }
    return null;
}

function currentContext(array $classStack, string $namespace): string
{
    if (!empty($classStack)) {
        return $classStack[count($classStack) - 1][0] . '::';
    }
    return $namespace !== '' ? $namespace . '\\' : '';
}
