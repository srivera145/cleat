<?php

declare(strict_types=1);

/*
 * Cleat configuration. Copy this file into your app, fill in the values, and
 * pass the array to Cleat::configure($pdo, $config, $mailer).
 *
 * Keys marked "extra" are additions beyond the original spec; every one of
 * them has a safe default and can be left out.
 */
return [
    'gateway' => 'fake',                 // 'authorizenet' or 'fake'
    'authorizenet' => [
        'login_id'        => '',
        'transaction_key' => '',         // server side only; never sent to a browser, never logged
        'signature_key'   => '',         // webhook HMAC key (128 hex chars from the merchant interface)
        'client_key'      => '',         // public client key for Accept.js
        'sandbox'         => true,
    ],
    'connect' => ['driver' => null],     // null = NullConnectGateway; 'finix' arrives in the Connect prompt
    'currency' => 'USD',
    'invoice_prefix' => 'CLT-',
    'base_url' => 'http://localhost',    // used to build pay and checkout URLs
    'routes' => ['pay_invoice' => '/pay/{token}', 'checkout' => '/checkout/{token}'],
    'app_key' => '',                     // 32+ byte secret for CSRF HMAC; Cleat refuses to boot if empty in non-fake mode
    'brand' => [
        'name'          => '',
        'logo_url'      => null,
        'support_email' => '',
        'hue'           => null,         // extra: Deck --hue-brand (0-360) for the hosted pages
        'color'         => '#0f766e',    // extra: hex colour for email buttons (emails cannot read CSS variables)
    ],
    'invoice_link_days' => 30,           // hosted invoice link lifetime; null = until paid or void
    'rate_limits' => [
        'per_ip'          => [10, 600],  // [max hits, window seconds]
        'per_link'        => [30, 600],
        'failures_per_ip' => [5, 3600],
    ],
    'automation' => [
        'dunning_enabled' => true,
        'retry_attempts'  => [1, 3, 7],  // days after initial failure
        'failed_action'   => 'past_due', // status while retrying
        'final_action'    => 'unpaid',   // 'unpaid' or 'canceled' after last retry fails
        'dispatch_emails' => true,
    ],
    'grace_days' => 0,

    // extra: where the hosted pages load Deck from. A path on your own site
    // ('/assets/deck/deck.min.css') works too; the CSP follows whatever origin
    // this points at.
    'deck_css' => 'https://cdn.jsdelivr.net/npm/@echodial/deck@0.1/dist/deck.min.css',

    // extra: a directory checked before the package's own templates/, so a
    // host can override any template by dropping a file with the same
    // relative path (e.g. emails/receipt.php) into it.
    'templates_path' => null,

    // extra: FakeGateway knobs, used only when gateway = 'fake'.
    'fake' => ['delay_ms' => 0],
];
