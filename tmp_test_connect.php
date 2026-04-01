<?php
require_once 'vendor/autoload.php';
$m = new MongoDB\Driver\Manager('mongodb://127.0.0.1/');
$rp = new MongoDB\Driver\ReadPreference('primary');
$s = $m->selectServer($rp);
echo get_class($s) . ' type=' . $s->getType() . PHP_EOL;

// Test a ping command
$result = $m->executeCommand('admin', new MongoDB\Driver\Command(['ping' => 1]));
foreach ($result as $doc) {
    var_dump((array)$doc);
}
