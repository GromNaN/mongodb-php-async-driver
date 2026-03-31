<?php

declare(strict_types=1);

// Fail if the mongodb extension is enabled but not the userland driver, which is required for these tests.
if (extension_loaded('mongodb')) {
    echo 'mongodb extension must not be enabled to run these tests.\n';
    exit(1);
}

// Loaded via -d auto_prepend_file=... to inject our userland driver into every
// .phpt FILE section without modifying the test source.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
