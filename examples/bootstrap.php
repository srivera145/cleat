<?php

declare(strict_types=1);

/*
 * Demo wiring shared by the examples. A real app does the same in its own
 * bootstrap: build a PDO, load the config, pass a mailer, call configure().
 *
 * Environment (all optional):
 *   CLEAT_DB_DSN    default mysql:host=127.0.0.1;port=3306;dbname=cleat_demo;charset=utf8mb4
 *   CLEAT_DB_USER   default root
 *   CLEAT_DB_PASS   default empty
 *   CLEAT_BASE_URL  default http://localhost:8080
 */

use Cleat\Cleat;
use Cleat\Mail\MailerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO(
    getenv('CLEAT_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=cleat_demo;charset=utf8mb4',
    getenv('CLEAT_DB_USER') ?: 'root',
    getenv('CLEAT_DB_PASS') ?: '',
);

$config = require dirname(__DIR__) . '/config/cleat.php';
$config['base_url'] = getenv('CLEAT_BASE_URL') ?: 'http://localhost:8080';
$config['brand'] = ['name' => 'Acme Tools', 'support_email' => 'billing@acme.test'] + $config['brand'];

// Demo mailer: each email is written to a temp folder so you can open it in a browser.
$mailer = new class implements MailerInterface {
    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cleat-demo-mail';
        is_dir($dir) || mkdir($dir, 0777, true);
        $name = gmdate('Ymd-His') . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($subject)), '-') . '.html';
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $html);
    }
};

Cleat::configure($pdo, $config, $mailer);
