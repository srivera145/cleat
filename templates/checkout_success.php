<?php
/**
 * After a payment link checkout succeeds (when the link has no success_url).
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\PaymentLink $link
 * @var \Cleat\Price $price
 * @var \Cleat\Product $product
 * @var \Cleat\Customer $customer
 * @var \Cleat\Invoice|null $invoice
 * @var \Cleat\Subscription|null $subscription
 * @var bool $held
 * @var array $brand
 * @var string $deckCss       (base data from HostedPage)
 * @var string $cspNonce
 * @var string $acceptJsUrl
 * @var string $apiLoginId
 * @var string $clientKey
 * @var bool $fakeMode
 * @var array<string, string> $testTokens
 * @var string $captchaHtml
 */
$trial = $subscription !== null && $subscription->onTrial();
$heading = match (true) {
    $held => 'Your payment is being reviewed',
    $trial => 'Your free trial has started',
    $subscription !== null => "You're subscribed",
    default => 'Payment received',
};
$brandName = trim((string) ($brand['name'] ?? ''));
?>
<?= $partial('head', ['title' => $heading . ($brandName !== '' ? ' · ' . $brandName : ''), 'deckCss' => $deckCss, 'cspNonce' => $cspNonce, 'brand' => $brand]) ?>
<body class="cleat-page">
<main class="container container-sm cleat-shell">
  <div class="stack stack-6">
    <?= $partial('brand', ['brand' => $brand]) ?>
    <div class="card">
      <div class="card-body center">
        <span class="icon-tile icon-tile-lg <?= $e($held ? 'icon-tile-warn' : 'icon-tile-good') ?>" aria-hidden="true">
          <svg class="icon icon-lg" viewBox="0 0 24 24" fill="currentColor"><path d="M9.55 17.6 4.4 12.45l1.4-1.4 3.75 3.75 8.65-8.65 1.4 1.4Z"/></svg>
        </span>
        <h1 class="h3"><?= $e($heading) ?></h1>
        <p class="text-muted">
<?php if ($held): ?>
          Reviews usually finish within a day. We'll email <?= $e($customer->email) ?> once your payment clears. There's no need to pay again.
<?php elseif ($trial): ?>
          Nothing was charged today. Your trial of <?= $e($product->name) ?> runs until <?= $e($subscription->trial_ends_at?->format('F j, Y')) ?>, then <?= $e($price->display($subscription->quantity)) ?>.
<?php else: ?>
          Thank you. A receipt is on its way to <?= $e($customer->email) ?>.
<?php endif; ?>
        </p>
      </div>
<?php if ($invoice !== null && !$held): ?>
      <div class="card-footer">
        <dl class="stack stack-2 text-sm w-full">
          <div class="bar"><dt class="text-muted"><?= $e($product->name) ?></dt><dd class="push fw-semi nums"><?= $e($invoice->money($invoice->amount_paid)) ?></dd></div>
          <div class="bar"><dt class="text-muted">Invoice</dt><dd class="push mono"><?= $e($invoice->number) ?></dd></div>
<?php if ($subscription !== null): ?>
          <div class="bar"><dt class="text-muted">Renews</dt><dd class="push"><?= $e($subscription->current_period_end->format('F j, Y')) ?></dd></div>
<?php endif; ?>
        </dl>
      </div>
<?php endif; ?>
    </div>
    <?= $partial('foot', ['brand' => $brand]) ?>
  </div>
</main>
</body>
</html>
