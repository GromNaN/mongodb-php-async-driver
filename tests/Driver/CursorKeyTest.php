<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Server;
use MongoDB\Driver\ServerDescription;
use PHPUnit\Framework\TestCase;

use function iterator_count;

class CursorKeyTest extends TestCase
{
    /**
     * Regression: key() must return null before rewind() is called.
     *
     * PHPUnit's Count constraint captures key() before calling
     * iterator_count(), then tries to restore position with rewind()
     * if key() was non-null.  For non-rewindable cursors (like database
     * cursors), a second rewind() throws.  Returning null pre-rewind
     * short-circuits the restore path.
     */
    public function testKeyReturnsNullBeforeRewind(): void
    {
        $cursor = $this->makeCursor([['_id' => new ObjectId()]]);

        $this->assertNull($cursor->key(), 'key() must return null before rewind()');
    }

    public function testKeyReturnsIntAfterRewind(): void
    {
        $cursor = $this->makeCursor([['_id' => new ObjectId()]]);

        $cursor->rewind();
        $this->assertSame(0, $cursor->key());
    }

    public function testKeyAdvancesWithNext(): void
    {
        $cursor = $this->makeCursor([
            ['_id' => new ObjectId()],
            ['_id' => new ObjectId()],
        ]);

        $cursor->rewind();
        $this->assertSame(0, $cursor->key());

        $cursor->next();
        $this->assertSame(1, $cursor->key());
    }

    /**
     * Regression: iterator_count() on a fresh cursor must not throw
     * "Cursors cannot rewind after starting iteration".
     *
     * PHPUnit's assertCount calls this code path.
     */
    public function testIteratorCountDoesNotThrow(): void
    {
        $cursor = $this->makeCursor([
            ['_id' => new ObjectId()],
            ['_id' => new ObjectId()],
            ['_id' => new ObjectId()],
        ]);

        $this->assertSame(3, iterator_count($cursor));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCursor(array $items): Cursor
    {
        $sd = ServerDescription::createFromInternal(
            host: '127.0.0.1',
            port: 27017,
            type: 'Standalone',
            roundTripTime: 1.0,
            helloResponse: [],
            lastUpdateTime: 0,
        );
        $server = Server::createFromInternal(
            host: '127.0.0.1',
            port: 27017,
            type: Server::TYPE_STANDALONE,
            latency: 1.0,
            serverDescription: $sd,
        );

        return Cursor::createFromArray($items, $server);
    }
}
