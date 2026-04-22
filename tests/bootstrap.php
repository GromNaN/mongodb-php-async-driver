<?php

declare(strict_types=1);

if (extension_loaded('mongodb')) {
    throw new RuntimeException(
        'ext-mongodb is loaded. Tests must run without the extension to avoid class conflicts.' . PHP_EOL .
        'Run tests with: PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit',
    );
}

require __DIR__ . '/../vendor/autoload.php';
