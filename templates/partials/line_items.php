<?php
/**
 * Line items and totals for an invoice.
 *
 * @var callable $e
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 */
$currency = $invoice->currency;
?>
<div class="table-wrap">
  <table class="table table-compact cleat-lines">
    <caption class="sr-only">Invoice <?= $e($invoice->number) ?> line items</caption>
    <thead>
      <tr>
        <th scope="col">Description</th>
        <th scope="col" class="num">Amount</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($items as $item): ?>
      <tr>
        <td>
          <?= $e($item->description) ?>
<?php if ($item->quantity > 1): ?>
          <span class="cleat-sub"><?= $e($item->quantity) ?> × <?= $e($item->unitMoney($currency)) ?></span>
<?php endif; ?>
        </td>
        <td class="num"><?= $e($item->money($currency)) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
<?php if ($invoice->tax !== 0): ?>
      <tr><th scope="row" class="fw-normal">Subtotal</th><td class="num fw-normal"><?= $e($invoice->money($invoice->subtotal)) ?></td></tr>
      <tr><th scope="row" class="fw-normal">Tax</th><td class="num fw-normal"><?= $e($invoice->money($invoice->tax)) ?></td></tr>
<?php endif; ?>
      <tr><th scope="row">Total</th><td class="num"><?= $e($invoice->totalMoney()) ?></td></tr>
<?php if ($invoice->amount_paid > 0): ?>
      <tr><th scope="row" class="fw-normal">Amount paid</th><td class="num fw-normal">-<?= $e($invoice->money($invoice->amount_paid)) ?></td></tr>
      <tr><th scope="row">Amount due</th><td class="num"><?= $e($invoice->amountDueMoney()) ?></td></tr>
<?php endif; ?>
    </tfoot>
  </table>
</div>
