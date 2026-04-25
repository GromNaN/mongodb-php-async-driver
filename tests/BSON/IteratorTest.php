<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use Exception;
use MongoDB\BSON\Document;
use MongoDB\BSON\Iterator;
use MongoDB\BSON\PackedArray;
use MongoDB\Driver\Exception\LogicException;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class IteratorTest extends TestCase
{
    // ------------------------------------------------------------------
    // Document iteration
    // ------------------------------------------------------------------

    public function testDocumentIteratesKeysAndValues(): void
    {
        $doc  = Document::fromPHP(['x' => 1, 'y' => 'hello', 'z' => true]);
        $keys = [];
        $vals = [];

        foreach ($doc as $k => $v) {
            $keys[] = $k;
            $vals[] = $v;
        }

        $this->assertSame(['x', 'y', 'z'], $keys);
        $this->assertSame([1, 'hello', true], $vals);
    }

    public function testDocumentEmptyIterator(): void
    {
        $doc  = Document::fromPHP([]);
        $keys = [];

        foreach ($doc as $k => $v) {
            $keys[] = $k;
        }

        $this->assertSame([], $keys);
    }

    // ------------------------------------------------------------------
    // PackedArray iteration
    // ------------------------------------------------------------------

    public function testPackedArrayIteratesIndexedValues(): void
    {
        $arr  = PackedArray::fromPHP([10, 20, 30]);
        $keys = [];
        $vals = [];

        foreach ($arr as $k => $v) {
            $keys[] = $k;
            $vals[] = $v;
        }

        $this->assertSame([0, 1, 2], $keys);
        $this->assertSame([10, 20, 30], $vals);
    }

    public function testPackedArrayEmptyIterator(): void
    {
        $arr  = PackedArray::fromPHP([]);
        $vals = [];

        foreach ($arr as $v) {
            $vals[] = $v;
        }

        $this->assertSame([], $vals);
    }

    // ------------------------------------------------------------------
    // Lazy loading: current() calls getValue() only when accessed
    // ------------------------------------------------------------------

    public function testEarlyBreakDoesNotExhaustIterator(): void
    {
        $doc      = Document::fromPHP(['a' => 1, 'b' => 2, 'c' => 3]);
        $iterator = $doc->getIterator();

        // Consume only the first element.
        $iterator->rewind();
        $this->assertTrue($iterator->valid());
        $this->assertSame('a', $iterator->key());
        $this->assertSame(1, $iterator->current());

        // The iterator is not exhausted — subsequent keys are still available.
        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertSame('b', $iterator->key());
    }

    // ------------------------------------------------------------------
    // rewind / clone reset position
    // ------------------------------------------------------------------

    public function testRewindResetsToStart(): void
    {
        $doc      = Document::fromPHP(['a' => 1, 'b' => 2]);
        $iterator = $doc->getIterator();

        iterator_to_array($iterator); // exhaust

        $this->assertFalse($iterator->valid());

        $iterator->rewind();
        $this->assertTrue($iterator->valid());
        $this->assertSame('a', $iterator->key());
        $this->assertSame(1, $iterator->current());
    }

    public function testIteratorToArrayRoundTrip(): void
    {
        $doc = Document::fromPHP(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], iterator_to_array($doc->getIterator()));
    }

    public function testCloneResetsToStart(): void
    {
        $doc      = Document::fromPHP(['a' => 1, 'b' => 2]);
        $iterator = $doc->getIterator();

        $iterator->next();
        $iterator->next(); // exhausted

        $cloned = clone $iterator;
        $this->assertTrue($cloned->valid());
        $this->assertSame('a', $cloned->key());
    }

    // ------------------------------------------------------------------
    // Errors on exhausted iterator
    // ------------------------------------------------------------------

    public function testCurrentOnExhaustedIteratorThrows(): void
    {
        $doc      = Document::fromPHP(['a' => 1]);
        $iterator = $doc->getIterator();

        $iterator->next(); // move past end

        $this->expectException(LogicException::class);
        $iterator->current();
    }

    public function testKeyOnExhaustedIteratorThrows(): void
    {
        $doc      = Document::fromPHP(['a' => 1]);
        $iterator = $doc->getIterator();

        $iterator->next();

        $this->expectException(LogicException::class);
        $iterator->key();
    }

    // ------------------------------------------------------------------
    // Serialization blocked
    // ------------------------------------------------------------------

    public function testSerializeThrows(): void
    {
        $doc      = Document::fromPHP(['a' => 1]);
        $iterator = $doc->getIterator();

        $this->expectException(Exception::class);
        $iterator->__serialize();
    }

    // ------------------------------------------------------------------
    // __debugInfo
    // ------------------------------------------------------------------

    public function testDebugInfoExposesBson(): void
    {
        $doc      = Document::fromPHP(['a' => 1]);
        $iterator = $doc->getIterator();

        $info = $iterator->__debugInfo();
        $this->assertArrayHasKey('bson', $info);
        $this->assertSame($doc, $info['bson']);
    }

    // ------------------------------------------------------------------
    // Iterator is instanceof Iterator
    // ------------------------------------------------------------------

    public function testGetIteratorReturnsIteratorInstance(): void
    {
        $doc = Document::fromPHP(['a' => 1]);
        $this->assertInstanceOf(Iterator::class, $doc->getIterator());

        $arr = PackedArray::fromPHP([1, 2]);
        $this->assertInstanceOf(Iterator::class, $arr->getIterator());
    }
}
