<?php

declare(strict_types=1);

use Cleat\Support\Schema;
use Cleat\Tests\Support\TestDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Rebuild the test schema once per run from the real migration file.
$pdo = TestDatabase::connect(createDatabase: true);
Schema::dropAll($pdo);
Schema::migrate($pdo);
