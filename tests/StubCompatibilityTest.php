<?php

declare(strict_types=1);

namespace MongoDB\Tests;

use Generator;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function interface_exists;
use function ltrim;
use function sort;
use function sprintf;
use function str_contains;
use function substr;

/**
 * Validates that our userland implementations match the ext-mongodb stub signatures.
 *
 * For each .stub.php file found in tests/references/mongo-php-driver/src/, we:
 *   1. Parse the stub with PHP-Parser to extract the public API contract.
 *   2. Reflect on our loaded class/interface.
 *   3. Assert that all public/protected methods exist with matching signatures.
 *   4. Assert that all constants exist with matching names.
 *   5. Assert that class/method/property modifiers (final, visibility) match.
 */
class StubCompatibilityTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Data provider
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function stubClassProvider(): array
    {
        $stubDirs = [
            __DIR__ . '/references/mongo-php-driver/src/BSON',
            __DIR__ . '/references/mongo-php-driver/src/MongoDB',
            __DIR__ . '/references/mongo-php-driver/src/MongoDB/Exception',
            __DIR__ . '/references/mongo-php-driver/src/MongoDB/Monitoring',
        ];

        $parser    = (new ParserFactory())->createForHostVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $cases = [];
        foreach ($stubDirs as $dir) {
            foreach (glob($dir . '/*.stub.php') as $stubFile) {
                $stmts = $parser->parse((string) file_get_contents($stubFile));
                if ($stmts === null) {
                    continue;
                }

                $stmts = $traverser->traverse($stmts);

                foreach (self::collectClassLike($stmts) as $node) {
                    $className        = (string) $node->namespacedName;
                    $cases[$className] = [$stubFile, $className];
                }
            }
        }

        return $cases;
    }

    // -----------------------------------------------------------------------
    // Test
    // -----------------------------------------------------------------------

    /** @dataProvider stubClassProvider */
    public function testClassMatchesStub(string $stubFile, string $className): void
    {
        $this->assertTrue(
            class_exists($className) || interface_exists($className),
            sprintf('Class or interface %s does not exist in userland', $className),
        );

        $ref     = new ReflectionClass($className);
        $stubApi = $this->parseStubApi($stubFile, $className);

        // --- Class-level: final -------------------------------------------
        // Enums are implicitly final in PHP, so we only check classes/interfaces
        if (! $ref->isEnum()) {
            $this->assertSame(
                $stubApi['final'],
                $ref->isFinal(),
                sprintf('%s class finality mismatch', $className),
            );
        }

        // --- Constants -------------------------------------------------------
        foreach ($stubApi['constants'] as $constName) {
            $this->assertTrue(
                $ref->hasConstant($constName),
                sprintf('%s should define constant %s', $className, $constName),
            );
        }

        // --- Properties (only those declared in stub) -----------------------
        foreach ($stubApi['properties'] as $propName => $propInfo) {
            $this->assertTrue(
                $ref->hasProperty($propName),
                sprintf('%s should declare property $%s', $className, $propName),
            );

            $prop = $ref->getProperty($propName);

            $this->assertSame(
                $propInfo['visibility'],
                $this->reflectVisibilityFromFlags($prop->isPublic(), $prop->isProtected()),
                sprintf('%s::$%s property visibility mismatch', $className, $propName),
            );

            if ($propInfo['type'] === null) {
                continue;
            }

            $actualType = $prop->getType() ? $this->reflectTypeString($prop->getType()) : null;
            $this->assertSame(
                $this->normalizeType($propInfo['type']),
                $this->normalizeType($actualType),
                sprintf('%s::$%s property type mismatch', $className, $propName),
            );
        }

        // --- Methods ---------------------------------------------------------
        foreach ($stubApi['methods'] as $methodName => $stubSig) {
            $this->assertTrue(
                $ref->hasMethod($methodName),
                sprintf('%s should implement method %s()', $className, $methodName),
            );

            $method = $ref->getMethod($methodName);

            // Visibility
            $this->assertSame(
                $stubSig['visibility'],
                $this->reflectVisibilityFromFlags($method->isPublic(), $method->isProtected()),
                sprintf('%s::%s() visibility mismatch', $className, $methodName),
            );

            // Final — only meaningful if the class itself is not already final
            if (! $ref->isFinal()) {
                $this->assertSame(
                    $stubSig['final'],
                    $method->isFinal(),
                    sprintf('%s::%s() final mismatch', $className, $methodName),
                );
            }

            // Static
            $this->assertSame(
                $stubSig['static'],
                $method->isStatic(),
                sprintf('%s::%s() static mismatch', $className, $methodName),
            );

            // Return type — skip for methods inherited from a parent class: built-in
            // parent classes (e.g. ArrayIterator) often omit explicit return types.
            if ($stubSig['returnType'] !== null && $method->getDeclaringClass()->getName() === $className) {
                $actualReturn = $this->reflectTypeString($method->getReturnType());
                // `static` return type is valid for final classes and means the class itself
                if ($actualReturn === 'static') {
                    $actualReturn = $ref->getName();
                }

                $this->assertSame(
                    $this->normalizeType($stubSig['returnType']),
                    $this->normalizeType($actualReturn),
                    sprintf('%s::%s() return type mismatch', $className, $methodName),
                );
            }

            // Parameters: skip count/type checks for private constructors —
            // those have internal-only params not visible in the public API.
            if ($methodName === '__construct' && $stubSig['visibility'] === 'private') {
                continue;
            }

            $actualParams = $method->getParameters();
            $this->assertCount(
                count($stubSig['params']),
                $actualParams,
                sprintf('%s::%s() parameter count mismatch', $className, $methodName),
            );

            foreach ($stubSig['params'] as $i => $stubParam) {
                $actualParam = $actualParams[$i];

                $this->assertSame(
                    $stubParam['name'],
                    $actualParam->getName(),
                    sprintf('%s::%s() param #%d name mismatch', $className, $methodName, $i),
                );

                if ($stubParam['type'] !== null) {
                    $actualType = $this->reflectTypeString($actualParam->getType());
                    $this->assertSame(
                        $this->normalizeType($stubParam['type']),
                        $this->normalizeType($actualType),
                        sprintf('%s::%s() param $%s type mismatch', $className, $methodName, $stubParam['name']),
                    );
                }

                $this->assertSame(
                    $stubParam['variadic'],
                    $actualParam->isVariadic(),
                    sprintf('%s::%s() param $%s variadic mismatch', $className, $methodName, $stubParam['name']),
                );

                $this->assertSame(
                    $stubParam['byRef'],
                    $actualParam->isPassedByReference(),
                    sprintf('%s::%s() param $%s by-ref mismatch', $className, $methodName, $stubParam['name']),
                );

                // Default value: check hasDefault, not the actual value
                $this->assertSame(
                    $stubParam['hasDefault'],
                    $actualParam->isOptional(),
                    sprintf('%s::%s() param $%s optional mismatch', $className, $methodName, $stubParam['name']),
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Stub parsing helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *     final: bool,
     *     constants: list<string>,
     *     properties: array<string, array{visibility: string, type: string|null}>,
     *     methods: array<string, array{
     *         visibility: string,
     *         final: bool,
     *         static: bool,
     *         returnType: string|null,
     *         params: list<array{name: string, type: string|null, variadic: bool, byRef: bool, hasDefault: bool}>
     *     }>
     * }
     */
    private function parseStubApi(string $stubFile, string $className): array
    {
        $parser    = (new ParserFactory())->createForHostVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $stmts = $parser->parse((string) file_get_contents($stubFile));
        $stmts = $traverser->traverse($stmts ?? []);

        $classNode = null;
        foreach (self::collectClassLike($stmts) as $node) {
            if ((string) $node->namespacedName === $className) {
                $classNode = $node;
                break;
            }
        }

        $this->assertNotNull($classNode, sprintf('Could not find %s in stub file', $className));

        $api = [
            'final'      => $classNode instanceof Stmt\Class_ && (bool) ($classNode->flags & Modifiers::FINAL),
            'constants'  => [],
            'properties' => [],
            'methods'    => [],
        ];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $api['constants'][] = (string) $const->name;
                }
            }

            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $api['properties'][(string) $prop->name] = [
                        'visibility' => $this->modifiersToVisibility($stmt->flags),
                        'type'       => $stmt->type ? $this->nodeTypeToString($stmt->type) : null,
                    ];
                }
            }

            if (! ($stmt instanceof Stmt\ClassMethod)) {
                continue;
            }

            $name   = (string) $stmt->name;
            $params = [];

            foreach ($stmt->params as $param) {
                $params[] = [
                    'name'       => (string) $param->var->name,
                    'type'       => $param->type ? $this->nodeTypeToString($param->type) : null,
                    'variadic'   => $param->variadic,
                    'byRef'      => $param->byRef,
                    'hasDefault' => $param->default !== null,
                ];
            }

            $api['methods'][$name] = [
                'visibility' => $this->modifiersToVisibility($stmt->flags),
                'final'      => (bool) ($stmt->flags & Modifiers::FINAL),
                'static'     => (bool) ($stmt->flags & Modifiers::STATIC),
                'returnType' => $stmt->returnType ? $this->nodeTypeToString($stmt->returnType) : null,
                'params'     => $params,
            ];
        }

        return $api;
    }

    /** @param Node[] $stmts */
    private static function collectClassLike(array $stmts): Generator
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                yield from self::collectClassLike($stmt->stmts);
            } elseif ($stmt instanceof Stmt\ClassLike) {
                yield $stmt;
            }
        }
    }

    private function modifiersToVisibility(int $flags): string
    {
        if ($flags & Modifiers::PROTECTED) {
            return 'protected';
        }

        if ($flags & Modifiers::PRIVATE) {
            return 'private';
        }

        return 'public';
    }

    private function nodeTypeToString(Node $type): string
    {
        if ($type instanceof Node\NullableType) {
            return $this->nodeTypeToString($type->type) . '|null';
        }

        if ($type instanceof Node\UnionType) {
            $parts = array_map([$this, 'nodeTypeToString'], $type->types);

            return implode('|', $parts);
        }

        if ($type instanceof Node\IntersectionType) {
            $parts = array_map([$this, 'nodeTypeToString'], $type->types);

            return implode('&', $parts);
        }

        if ($type instanceof Node\Name) {
            // Already FQN after NameResolver — strip leading backslash for consistency
            return ltrim((string) $type, '\\');
        }

        if ($type instanceof Node\Identifier) {
            return (string) $type;
        }

        return (string) $type;
    }

    /**
     * Normalize a type string for comparison:
     * - Expand `?T` to `T|null`
     * - Sort union parts alphabetically, with `null` always last
     */
    private function normalizeType(string|null $type): string|null
    {
        if ($type === null) {
            return null;
        }

        // Expand ?T to T|null
        if ($type[0] === '?') {
            $type = substr($type, 1) . '|null';
        }

        if (! str_contains($type, '|')) {
            return $type;
        }

        $parts    = explode('|', $type);
        $nullPart = array_filter($parts, static fn ($p) => $p === 'null');
        $rest     = array_values(array_filter($parts, static fn ($p) => $p !== 'null'));
        sort($rest);

        return implode('|', [...$rest, ...$nullPart]);
    }

    // -----------------------------------------------------------------------
    // Reflection helpers
    // -----------------------------------------------------------------------

    private function reflectVisibilityFromFlags(bool $isPublic, bool $isProtected): string
    {
        if ($isProtected) {
            return 'protected';
        }

        if (! $isPublic) {
            return 'private';
        }

        return 'public';
    }

    private function reflectTypeString(ReflectionType|null $type): string|null
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                return $name . '|null';
            }

            return $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn ($t) => $this->reflectTypeString($t),
                $type->getTypes(),
            ));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(
                fn ($t) => $this->reflectTypeString($t),
                $type->getTypes(),
            ));
        }

        return null;
    }
}
