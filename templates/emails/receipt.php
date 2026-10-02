<?php
/**
 * Receipt for a paid invoice, or (with $trial) confirmation that a free
 * trial started and nothing was charged.
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\Invoice|null $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $brandName
 * @var string $buttonColor
 * @var \Cleat\Charge|null $charge
 * @var \Cleat\Subscription|null $trial
 * @var \Cleat\Price|null $trialPrice
 * @var string|null $cardLabel
 */
$trialPrice ??= null;
$row = 'padding:8px 0;font-size:14px;line-height:20px;';
?>
<?php if ($trial !== null): ?>
<?= $partial('email_start', [
    'title' => 'Your trial has started',
    'preheader' => 'Nothing was charged today.',
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 12px;font-size:22px;line-height:30px;font-weight:700;color:#111827;">Your free trial has started</p>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Hi <?= $e($customer->name ?? 'there') ?>, thanks for signing up<?php if ($trialPrice !== null): ?> for <?= $e($trialPrice->label()) ?><?php endif; ?>. Nothing was charged today.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0;border-top:1px solid #e5e7eb;">
<tr><td style="<?= $e($row) ?>color:#6b7280;">Trial ends</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($trial->trial_ends_at?->format('F j, Y')) ?></td></tr>
<?php if ($trialPrice !== null): ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Then</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($trialPrice->display($trial->quantity)) ?></td></tr>
<?php endif; ?>
<?php if ($cardLabel !== null): ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Card on file</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($cardLabel) ?></td></tr>
<?php endif; ?>
</table>
<p style="margin:20px 0 0;font-size:14px;line-height:21px;color:#374151;">Cancel any time before the trial ends and you won't be charged.</p>
<?php else: ?>
<?= $partial('email_start', [
    'title' => 'Receipt ' . $invoice->number,
    'preheader' => sprintf('Payment of %s received. Thank you.', $invoice->money($invoice->amount_paid)->format()),
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 4px;font-size:13px;line-height:20px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;">Receipt</p>
<p class="cleat-big" style="margin:0;font-size:36px;line-height:44px;font-weight:700;color:#111827;"><?= $e($invoice->money($invoice->amount_paid)) ?></p>
<p style="margin:4px 0 20px;font-size:15px;line-height:22px;color:#4b5563;">Paid <?= $e(($invoice->paid_at ?? $invoice->updated_at)->format('F j, Y')) ?>. Thank you, <?= $e($customer->name ?? $customer->email) ?>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
<tr><td style="<?= $e($row) ?>color:#6b7280;">Invoice</td><td align="right" style="<?= $e($row) ?>color:#111827;font-family:Menlo,Consolas,monospace;"><?= $e($invoice->number) ?></td></tr>
<?php if ($charge !== null && $charge->source->value === 'stored_profile' && $cardLabel !== null): ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Payment method</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($cardLabel) ?></td></tr>
<?php endif; ?>
<?php if ($invoice->period_start !== null && $invoice->period_end !== null): ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Period</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($invoice->period_start->format('M j')) ?> – <?= $e($invoice->period_end->format('M j, Y')) ?></td></tr>
<?php endif; ?>
</table>
<?= $partial('email_items', ['invoice' => $invoice, 'items' => $items, 'showPaid' => true]) ?>
<?php endif; ?>
<?= $partial('email_end', ['brand' => $brand, 'brandName' => $brandName]) ?>
