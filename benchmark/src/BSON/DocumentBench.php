<?php

namespace MongoDB\Benchmark\BSON;

use MongoDB\Benchmark\Fixtures\Data;
use MongoDB\BSON\Document;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use stdClass;

use function file_get_contents;
use function iterator_to_array;

#[BeforeMethods('prepareData')]
#[Revs(10)]
#[Warmup(1)]
final class DocumentBench
{
    private static Document $document;

    public function prepareData(): void
    {
        self::$document = Document::fromJSON(file_get_contents(Data::LARGE_FILE_PATH));
    }

    #[Revs(500)]
    public function benchCheckFirst(): void
    {
        self::$document->has('qx3MigjubFSm');
    }

    #[Revs(500)]
    public function benchCheckFirstMultipleTimes(): void
    {
        self::$document->has('qx3MigjubFSm');
        self::$document->has('qx3MigjubFSm');
        self::$document->has('qx3MigjubFSm');
    }

    #[Revs(500)]
    public function benchCheckLast(): void
    {
        self::$document->has('Zz2MOlCxDhLl');
    }

    #[Revs(500)]
    public function benchCheckLastMultipleTimes(): void
    {
        self::$document->has('Zz2MOlCxDhLl');
        self::$document->has('Zz2MOlCxDhLl');
        self::$document->has('Zz2MOlCxDhLl');
    }

    #[Revs(500)]
    public function benchAccessFirst(): void
    {
        self::$document->get('qx3MigjubFSm');
    }

    #[Revs(500)]
    public function benchAccessFirstMultipleTimes(): void
    {
        self::$document->get('qx3MigjubFSm');
        self::$document->get('qx3MigjubFSm');
        self::$document->get('qx3MigjubFSm');
    }

    #[Revs(500)]
    public function benchAccessLast(): void
    {
        self::$document->get('Zz2MOlCxDhLl');
    }

    #[Revs(500)]
    public function benchAccessLastMultipleTimes(): void
    {
        self::$document->get('Zz2MOlCxDhLl');
        self::$document->get('Zz2MOlCxDhLl');
        self::$document->get('Zz2MOlCxDhLl');
    }

    public function benchIteratorToArray(): void
    {
        iterator_to_array(self::$document);
    }

    public function benchToPHPObject(): void
    {
        self::$document->toPHP();
    }

    public function benchToPHPObjectViaIteration(): void
    {
        $object = new stdClass();

        foreach (self::$document as $key => $value) {
            $object->$key = $value;
        }
    }

    public function benchToPHPArray(): void
    {
        self::$document->toPHP(['root' => 'array']);
    }

    public function benchIteration(): void
    {
        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach
        // phpcs:ignore Generic.ControlStructures.InlineControlStructure.NotAllowed
        foreach (self::$document as $key => $value);
    }

    public function benchIterationAsArray(): void
    {
        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach
        // phpcs:ignore Generic.ControlStructures.InlineControlStructure.NotAllowed
        foreach (self::$document->toPHP(['root' => 'array']) as $key => $value);
    }

    public function benchIterationAsObject(): void
    {
        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach
        // phpcs:ignore Generic.ControlStructures.InlineControlStructure.NotAllowed
        foreach (self::$document->toPHP() as $key => $value);
    }
}
