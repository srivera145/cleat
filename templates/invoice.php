<?php
/**
 * The invoice document: on screen, saved as PDF, or printed on A4 or Letter.
 *
 * @var callable $e
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $deckCss
 * @var string|null $payUrl
 * @var string|null $cspNonce
 */
$cspNonce ??= null;
$brandName = trim((string) ($brand['name'] ?? ''));
$logo = $brand['logo_url'] ?? null;
$support = trim((string) ($brand['support_email'] ?? ''));
$currency = $invoice->currency;
[$badgeClass, $badgeText] = match ($invoice->status->value) {
    'paid' => ['badge-good', 'Paid'],
    'void' => ['', 'Void'],
    'uncollectible' => ['badge-bad', 'Uncollectible'],
    'draft' => ['', 'Draft'],
    default => $invoice->isPastDue() ? ['badge-bad', 'Overdue'] : ['badge-warn', 'Due'],
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Invoice <?= $e($invoice->number ?? 'draft') ?><?php if ($brandName !== ''): ?> · <?= $e($brandName) ?><?php endif; ?></title>
<link rel="stylesheet" href="<?= $e($deckCss) ?>">
<style<?php if ($cspNonce !== null): ?> nonce="<?= $e($cspNonce) ?>"<?php endif; ?>>
  /* Unlayered, so it beats Deck's print layer (which defaults to Letter):
     size auto lets the printer's paper decide, and these margins fit A4 and Letter. */
  @page { size: auto; margin: 14mm 14mm 16mm; }
  .cleat-invoice { max-inline-size: 52rem; margin-inline: auto; padding: var(--space-6) var(--space-gutter) var(--space-12); }
  .cleat-invoice-head, .cleat-parties { display: grid; gap: var(--space-6); grid-template-columns: 1fr; }
  .cleat-meta { display: grid; grid-template-columns: auto 1fr; gap: var(--space-1) var(--space-4); margin: 0; }
  .cleat-meta dt { color: var(--text-muted); }
  .cleat-meta dd { margin: 0; }
  .cleat-logo { display: block; block-size: 40px; inline-size: auto; max-inline-size: 200px; object-fit: contain; }
  .cleat-totals { margin-inline-start: auto; inline-size: min(100%, 22rem); }
  .cleat-totals th { font-weight: 400; color: var(--text-muted); text-align: start; }
  .cleat-totals .cleat-grand th, .cleat-totals .cleat-grand td { font-weight: 700; color: var(--text); font-size: var(--text-md); }
  .cleat-break { overflow-wrap: anywhere; }
  /* Unlayered, so it also beats the print layer's h2 size. */
  .cleat-label { margin: 0; font-size: var(--text-xs); font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
  @media (min-width: 40rem) {
    .cleat-invoice-head { grid-template-columns: 1fr auto; align-items: start; }
    .cleat-parties { grid-template-columns: 1fr 1fr; }
    .cleat-doc-title { text-align: end; }
  }
  @media print {
    .cleat-invoice { padding: 0; max-inline-size: none; }
    .cleat-invoice-head { grid-template-columns: 1fr auto; }
    .cleat-parties { grid-template-columns: 1fr 1fr; }
    .cleat-doc-title { text-align: end; }
    .cleat-totals { inline-size: 45%; }
    .cleat-label { font-size: 8pt; }
  }
</style>
</head>
<body>
<main class="stack cleat-invoice stack-8">
  <header class="cleat-invoice-head">
    <div class="stack stack-2">
<?php if (is_string($logo) && $logo !== ''): ?>
      <img class="cleat-logo" src="<?= $e($logo) ?>" alt="<?= $e($brandName !== '' ? $brandName : 'Logo') ?>">
<?php elseif ($brandName !== ''): ?>
      <p class="h3 m-0"><?= $e($brandName) ?></p>
<?php endif; ?>
<?php if ($support !== ''): ?>
      <p class="text-sm text-muted"><?= $e($support) ?></p>
<?php endif; ?>
    </div>
    <div class="stack stack-2 cleat-doc-title">
      <h1 class="h2 m-0">Invoice</h1>
      <p class="mono"><?= $e($invoice->number ?? 'Draft') ?></p>
      <p><span class="badge <?= $e($badgeClass) ?>"><?= $e($badgeText) ?></span></p>
    </div>
  </header>

  <section class="cleat-parties" aria-label="Invoice details">
    <div class="stack stack-1">
      <h2 class="cleat-label">Billed to</h2>
<?php if ($customer->name !== null): ?>
      <p class="fw-semi"><?= $e($customer->name) ?></p>
<?php endif; ?>
      <p class="cleat-break"><?= $e($customer->email) ?></p>
    </div>
    <dl class="cleat-meta text-sm">
      <dt>Issued</dt><dd><?= $e($invoice->created_at->format('F j, Y')) ?></dd>
      <dt>Due</dt><dd><?= $e($invoice->due_at->format('F j, Y')) ?></dd>
<?php if ($invoice->paid_at !== null): ?>
      <dt>Paid</dt><dd><?= $e($invoice->paid_at->format('F j, Y')) ?></dd>
<?php endif; ?>
<?php if ($invoice->period_start !== null && $invoice->period_end !== null): ?>
      <dt>Period</dt><dd><?= $e($invoice->period_start->format('M j, Y')) ?> – <?= $e($invoice->period_end->format('M j, Y')) ?></dd>
<?php endif; ?>
    </dl>
  </section>

  <section class="stack stack-4" aria-label="Line items">
    <div class="table-wrap">
      <table class="table table-stack">
        <thead>
          <tr>
            <th scope="col">Description</th>
            <th scope="col" class="num">Qty</th>
            <th scope="col" class="num">Unit price</th>
            <th scope="col" class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($items as $item): ?>
          <tr class="keep-together">
            <td data-label="Description"><?= $e($item->description) ?></td>
            <td data-label="Qty" class="num"><span class="block text-end"><?= $e($item->quantity) ?></span></td>
            <td data-label="Unit price" class="num"><span class="block text-end"><?= $e($item->unitMoney($currency)) ?></span></td>
            <td data-label="Amount" class="num"><span class="block text-end"><?= $e($item->money($currency)) ?></span></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <table class="table table-compact cleat-totals keep-together">
      <tbody>
        <tr><th scope="row">Subtotal</th><td class="num"><?= $e($invoice->money($invoice->subtotal)) ?></td></tr>
<?php if ($invoice->tax !== 0): ?>
        <tr><th scope="row">Tax</th><td class="num"><?= $e($invoice->money($invoice->tax)) ?></td></tr>
<?php endif; ?>
        <tr class="cleat-grand"><th scope="row">Total</th><td class="num"><?= $e($invoice->totalMoney()) ?></td></tr>
<?php if ($invoice->amount_paid > 0): ?>
        <tr><th scope="row">Amount paid</th><td class="num">-<?= $e($invoice->money($invoice->amount_paid)) ?></td></tr>
<?php endif; ?>
        <tr class="cleat-grand"><th scope="row">Amount due</th><td class="num"><?= $e($invoice->amountDueMoney()) ?></td></tr>
      </tbody>
    </table>
  </section>

<?php if ($invoice->memo !== null && $invoice->memo !== ''): ?>
  <section class="stack stack-1 keep-together">
    <h2 class="cleat-label">Notes</h2>
    <p class="text-sm"><?= $e($invoice->memo) ?></p>
  </section>
<?php endif; ?>

<?php if ($payUrl !== null): ?>
  <section class="card card-brand keep-together">
    <div class="card-body">
      <p class="fw-semi">Pay online</p>
      <p class="text-sm cleat-break"><a class="no-print-url" href="<?= $e($payUrl) ?>"><?= $e($payUrl) ?></a></p>
      <p class="no-print"><a class="btn btn-primary" href="<?= $e($payUrl) ?>">Pay <?= $e($invoice->amountDueMoney()) ?></a></p>
    </div>
  </section>
<?php endif; ?>

  <footer class="text-sm text-muted border-t pbs-4">
    <p>Thank you for your business.<?php if ($support !== ''): ?> Questions about this invoice? Email <?= $e($support) ?>.<?php endif; ?></p>
  </footer>
</main>
</body>
</html>
