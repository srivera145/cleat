<?php
/**
 * Line items for emails, styled inline.
 *
 * @var callable $e
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var bool $showPaid
 */
$showPaid ??= false;
$currency = $invoice->currency;
$cell = 'padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:14px;line-height:20px;vertical-align:top;';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;">
<?php foreach ($items as $item): ?>
<tr>
<td style="<?= $e($cell) ?>color:#111827;"><?= $e($item->description) ?><?php if ($item->quantity > 1): ?><br><span style="color:#6b7280;font-size:13px;"><?= $e($item->quantity) ?> × <?= $e($item->unitMoney($currency)) ?></span><?php endif; ?></td>
<td align="right" style="<?= $e($cell) ?>color:#111827;white-space:nowrap;padding-left:16px;"><?= $e($item->money($currency)) ?></td>
</tr>
<?php endforeach; ?>
<?php if ($invoice->tax !== 0): ?>
<tr>
<td style="padding:10px 0 0;font-size:14px;color:#6b7280;">Tax</td>
<td align="right" style="padding:10px 0 0;font-size:14px;color:#6b7280;white-space:nowrap;"><?= $e($invoice->money($invoice->tax)) ?></td>
</tr>
<?php endif; ?>
<tr>
<td style="padding:12px 0 0;font-size:15px;font-weight:700;color:#111827;"><?= $e($showPaid ? 'Amount paid' : 'Total') ?></td>
<td align="right" style="padding:12px 0 0;font-size:15px;font-weight:700;color:#111827;white-space:nowrap;"><?= $e($showPaid ? $invoice->money($invoice->amount_paid) : $invoice->totalMoney()) ?></td>
</tr>
</table>
