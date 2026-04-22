<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Custom\IssetTypeCheckToNullCoalesceRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __FILE__,
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/references',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
    ])
    ->withRules([
        IssetTypeCheckToNullCoalesceRector::class,
    ]);
