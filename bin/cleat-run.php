#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Cleat billing runner: renewals, trial conversions, dunning retries,
 * period-end cancellations, payment link expiry. Safe to run every 15
 * minutes; runs never overlap (a second copy exits immediately).
 *
 *   php vendor/bin/cleat-run.php --bootstrap=/path/to/cleat-bootstrap.php
 *
 *   cron:     0,15,30,45 * * * * php /app/vendor/bin/cleat-run.php --bootstrap=/app/cleat-bootstrap.php
 *   Windows:  schtasks /create /sc minute /mo 15 /tn "Cleat runner"
 *               /tr "C:\Helm\resources\server\php\php.exe C:\app\vendor\bin\cleat-run.php --bootstrap=C:\app\cleat-bootstrap.php"
 *
 * Options:
 *   --bootstrap=FILE   PHP file that calls Cleat::configure($pdo, $config, $mailer). Required.
 *   --now=DATETIME     Run as of this UTC time instead of the current time.
 *   --json             Print the run report as JSON.
 *
 * Exit codes: 0 all good, 1 some rows failed (details in the report and the
 * log), 2 bad usage or bootstrap.
 */

use Cleat\Billing\Runner;
use Cleat\Cleat;

$candidates = [
    $GLOBALS['_composer_autoload_path'] ?? null, // set by Composer's bin proxy
    __DIR__ . '/../vendor/autoload.php',         // running from the package itself
    __DIR__ . '/../../../autoload.php',          // vendor/echodial/cleat/bin -> vendor/autoload.php
];
foreach ($candidates as $autoload) {
    if (is_string($autoload) && is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(Runner::class)) {
    fwrite(STDERR, "cleat-run: could not find Composer's vendor/autoload.php\n");
    exit(2);
}

$options = getopt('', ['bootstrap:', 'now:', 'json', 'help']);
if (isset($options['help']) || !isset($options['bootstrap'])) {
    fwrite(isset($options['help']) ? STDOUT : STDERR, "Usage: cleat-run.php --bootstrap=FILE [--now=\"YYYY-MM-DD HH:MM:SS\"] [--json]\n");
    exit(isset($options['help']) ? 0 : 2);
}

$bootstrap = (string) $options['bootstrap'];
if (!is_file($bootstrap)) {
    fwrite(STDERR, "cleat-run: bootstrap file not found: $bootstrap\n");
    exit(2);
}
require $bootstrap;
if (!Cleat::isConfigured()) {
    fwrite(STDERR, "cleat-run: the bootstrap file must call Cleat::configure(\$pdo, \$config, \$mailer)\n");
    exit(2);
}

try {
    $now = isset($options['now']) ? new DateTimeImmutable((string) $options['now'], new DateTimeZone('UTC')) : null;
} catch (Exception $e) {
    fwrite(STDERR, "cleat-run: --now is not a valid date/time\n");
    exit(2);
}

$report = (new Runner())->run($now);

if (isset($options['json'])) {
    fwrite(STDOUT, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} elseif ($report->skipped) {
    fwrite(STDOUT, "cleat-run: another run is in progress; skipped\n");
} else {
    fwrite(STDOUT, sprintf(
        "cleat-run: %d trial(s) converted, %d renewal(s), %d retr%s, %d succeeded, %d failed, %d cancellation(s), %d link(s) expired, %d rate-limit row(s) pruned\n",
        $report->trialsConverted, $report->renewals, $report->retries, $report->retries === 1 ? 'y' : 'ies',
        $report->paymentsSucceeded, $report->paymentsFailed, $report->cancellations, $report->linksDeactivated, $report->rateLimitRowsPruned,
    ));
    foreach ($report->errors as $error) {
        fwrite(STDERR, "cleat-run: error: $error\n");
    }
}
exit($report->errors === [] ? 0 : 1);
