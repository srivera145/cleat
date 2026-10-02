<?php
/**
 * Payment link checkout.
 *
 * @var callable $e
 * @var callable $json
 * @var callable $partial
 * @var \Cleat\PaymentLink $link
 * @var \Cleat\Price $price
 * @var \Cleat\Product $product
 * @var int $quantity
 * @var int $minQuantity
 * @var int $maxQuantity
 * @var \Cleat\Money $amount
 * @var string $priceText
 * @var string|null $trialText
 * @var array $values
 * @var list<string> $errors
 * @var string $csrf
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
$brandName = trim((string) ($brand['name'] ?? ''));
$recurring = $price->isRecurring();
$trialDays = $recurring ? $price->trial_days : 0;
$interval = $recurring ? ($price->interval_count === 1 ? ' / ' . $price->billing_interval->value : ' every ' . $price->intervalLabel()) : '';
$submitLabel = $trialDays > 0 ? 'Start free trial' : ($recurring ? 'Subscribe for ' . $priceText : 'Pay ' . $amount->format());
$title = $product->name . ($brandName !== '' ? ' · ' . $brandName : '');
?>
<?= $partial('head', ['title' => $title, 'deckCss' => $deckCss, 'cspNonce' => $cspNonce, 'brand' => $brand]) ?>
<body class="cleat-page">
<main class="container container-md cleat-shell">
  <div class="stack stack-6">
    <?= $partial('brand', ['brand' => $brand]) ?>

    <div class="split cleat-split">
      <section class="stack stack-4" aria-labelledby="cleat-product-heading">
        <div class="card">
          <div class="card-body">
            <h1 class="h3" id="cleat-product-heading"><?= $e($product->name) ?></h1>
<?php if ($product->description !== null && $product->description !== ''): ?>
            <p class="text-muted"><?= $e($product->description) ?></p>
<?php endif; ?>
            <p>
              <span class="cleat-amount" data-cleat-total data-unit="<?= $e($price->amount) ?>" data-currency="<?= $e($price->currency) ?>"><?= $e($amount) ?></span><?php if ($recurring): ?><span class="text-muted"><?= $e($interval) ?></span><?php endif; ?>
            </p>
<?php if ($trialText !== null): ?>
            <p><span class="badge badge-brand"><?= $e($trialDays) ?>-day free trial</span></p>
            <p class="text-sm text-muted"><?= $e($trialText) ?>. You won't be charged today, and you can cancel any time before the trial ends.</p>
<?php elseif ($recurring): ?>
            <p class="text-sm text-muted">Billed <?= $e($price->interval_count === 1
                ? match ($price->billing_interval->value) { 'day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', default => 'yearly' }
                : 'every ' . $price->intervalLabel()) ?> until you cancel.</p>
<?php endif; ?>
<?php if (!$link->allow_quantity_change && $quantity > 1): ?>
            <p class="text-sm text-muted">Quantity: <?= $e($quantity) ?></p>
<?php endif; ?>
          </div>
        </div>
      </section>

      <aside class="cleat-sticky" aria-label="Checkout">
        <div class="card">
          <div class="card-body">
            <h2 class="card-title"><?= $e($trialDays > 0 ? 'Start your trial' : ($recurring ? 'Subscribe' : 'Checkout')) ?></h2>
            <?= $partial('payment_form', [
                'csrf' => $csrf,
                'errors' => $errors,
                'submitLabel' => $submitLabel,
                'fieldsPartial' => 'checkout_fields',
                'fieldsVars' => ['link' => $link, 'values' => $values, 'minQuantity' => $minQuantity, 'maxQuantity' => $maxQuantity],
                'allowSave' => false,
                'acceptJsUrl' => $acceptJsUrl,
                'apiLoginId' => $apiLoginId,
                'clientKey' => $clientKey,
                'cspNonce' => $cspNonce,
                'fakeMode' => $fakeMode,
                'testTokens' => $testTokens,
                'captchaHtml' => $captchaHtml,
            ]) ?>
<?php if ($recurring && $trialDays === 0): ?>
            <p class="text-xs text-muted">Your card is saved to charge <?= $e($priceText) ?> each period until you cancel.</p>
<?php elseif ($trialDays > 0): ?>
            <p class="text-xs text-muted">Your card is saved now and first charged when the trial ends.</p>
<?php endif; ?>
          </div>
        </div>
      </aside>
    </div>

    <?= $partial('foot', ['brand' => $brand]) ?>
  </div>
</main>
<?php if ($link->allow_quantity_change): ?>
<script nonce="<?= $e($cspNonce) ?>">
(function () {
  'use strict';
  var input = document.getElementById('cleat-quantity');
  var total = document.querySelector('[data-cleat-total]');
  var label = document.querySelector('[data-cleat-submit] [data-cleat-idle]');
  var showLabel = <?= $json($trialDays === 0) ?>;
  var prefix = <?= $json($recurring ? 'Subscribe for ' : 'Pay ') ?>;
  var suffix = <?= $json($interval) ?>;
  if (!input || !total) { return; }
  var symbol = total.textContent.replace(/[\d.,\s-]/g, '');
  function format(cents) {
    var major = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var minor = String(cents % 100).padStart(2, '0');
    return symbol + major + '.' + minor;
  }
  input.addEventListener('input', function () {
    var qty = parseInt(input.value, 10);
    if (!(qty >= +input.min && qty <= +input.max)) { return; }
    var text = format(qty * parseInt(total.dataset.unit, 10));
    total.textContent = text;
    if (showLabel && label) { label.textContent = prefix + text + (prefix === 'Pay ' ? '' : suffix); }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
