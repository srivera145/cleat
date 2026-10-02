<?php
/**
 * Hosted invoice page. States: 'pay' (form), 'paid' (already paid),
 * 'receipt' (just paid), 'review' (held for fraud review).
 *
 * @var callable $e
 * @var callable $partial
 * @var string $state
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var \Cleat\Customer $customer
 * @var string|null $savedCard
 * @var \Cleat\Charge|null $charge
 * @var list<string> $errors
 * @var string|null $csrf
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
$due = $invoice->amountDueMoney();
$isPaidState = in_array($state, ['paid', 'receipt'], true);
$overdue = !$isPaidState && $invoice->isPastDue();
$title = ($isPaidState ? 'Invoice ' . $invoice->number . ' (paid)' : 'Pay invoice ' . $invoice->number) . ($brandName !== '' ? ' · ' . $brandName : '');
?>
<?= $partial('head', ['title' => $title, 'deckCss' => $deckCss, 'cspNonce' => $cspNonce, 'brand' => $brand]) ?>
<body class="cleat-page">
<main class="container container-md cleat-shell">
  <div class="stack stack-6">
    <?= $partial('brand', ['brand' => $brand]) ?>

    <div class="split cleat-split">
      <section class="stack stack-4" aria-labelledby="cleat-invoice-heading">
        <div class="card">
          <div class="card-body">
            <div class="bar">
              <h1 class="text-sm fw-medium text-muted m-0" id="cleat-invoice-heading">Invoice <?= $e($invoice->number) ?></h1>
<?php if ($isPaidState): ?>
              <span class="push badge badge-good badge-dot">Paid</span>
<?php elseif ($overdue): ?>
              <span class="push badge badge-bad badge-dot">Overdue</span>
<?php else: ?>
              <span class="push badge badge-warn badge-dot">Due <?= $e($invoice->due_at->format('M j')) ?></span>
<?php endif; ?>
            </div>
            <p class="cleat-amount"><?= $e($isPaidState ? $invoice->money($invoice->amount_paid) : $due) ?></p>
            <p class="text-sm text-muted">
<?php if ($isPaidState && $invoice->paid_at !== null): ?>
              Paid <?= $e($invoice->paid_at->format('F j, Y')) ?>
<?php else: ?>
              Due <?= $e($invoice->due_at->format('F j, Y')) ?>
<?php endif; ?>
              <?php if ($brandName !== ''): ?>· from <?= $e($brandName) ?><?php endif; ?>
            </p>
            <p class="text-sm">Billed to <span class="fw-semi"><?= $e($customer->name ?? $customer->email) ?></span><?php if ($customer->name !== null): ?> <span class="text-muted"><?= $e($customer->email) ?></span><?php endif; ?></p>
<?php if ($invoice->memo !== null && $invoice->memo !== ''): ?>
            <p class="text-sm text-muted border-t pbs-3"><?= $e($invoice->memo) ?></p>
<?php endif; ?>
          </div>
        </div>

        <?= $partial('line_items', ['invoice' => $invoice, 'items' => $items]) ?>
      </section>

      <aside class="cleat-sticky" aria-label="Payment">
        <div class="card">
          <div class="card-body">
<?php if ($state === 'pay'): ?>
            <h2 class="card-title">Pay <?= $e($due) ?></h2>
            <?= $partial('payment_form', [
                'csrf' => $csrf,
                'errors' => $errors,
                'submitLabel' => 'Pay ' . $due->format(),
                'savedCard' => $savedCard,
                'allowSave' => true,
                'acceptJsUrl' => $acceptJsUrl,
                'apiLoginId' => $apiLoginId,
                'clientKey' => $clientKey,
                'cspNonce' => $cspNonce,
                'fakeMode' => $fakeMode,
                'testTokens' => $testTokens,
                'captchaHtml' => $captchaHtml,
            ]) ?>
<?php elseif ($state === 'review'): ?>
            <div class="alert alert-info">
              <div>
                <p class="alert-title">Payment received for review</p>
                <p class="alert-body">Your payment is being reviewed, which usually takes less than a day. We'll email <?= $e($customer->email) ?> as soon as it clears. There's no need to pay again.</p>
              </div>
            </div>
<?php else: ?>
            <div class="alert alert-good">
              <div>
                <p class="alert-title"><?= $e($state === 'receipt' ? 'Payment received. Thank you!' : 'This invoice is paid') ?></p>
                <p class="alert-body">
<?php if ($state === 'receipt'): ?>
                  A receipt is on its way to <?= $e($customer->email) ?>.
<?php else: ?>
                  Paid in full<?php if ($invoice->paid_at !== null): ?> on <?= $e($invoice->paid_at->format('F j, Y')) ?><?php endif; ?>. Nothing more is due.
<?php endif; ?>
                </p>
              </div>
            </div>
            <dl class="stack stack-2 text-sm">
              <div class="bar"><dt class="text-muted">Amount paid</dt><dd class="push fw-semi nums"><?= $e($invoice->money($invoice->amount_paid)) ?></dd></div>
              <div class="bar"><dt class="text-muted">Invoice</dt><dd class="push mono"><?= $e($invoice->number) ?></dd></div>
<?php if ($charge !== null && $savedCard !== null): ?>
              <div class="bar"><dt class="text-muted">Card</dt><dd class="push"><?= $e($savedCard) ?></dd></div>
<?php endif; ?>
            </dl>
<?php endif; ?>
          </div>
        </div>
      </aside>
    </div>

    <?= $partial('foot', ['brand' => $brand]) ?>
  </div>
</main>
</body>
</html>
