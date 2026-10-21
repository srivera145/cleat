<?php
/**
 * A trial ends soon and the first paid period will be charged. Sent once,
 * trial_ending_days before the trial ends.
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\Subscription $subscription
 * @var \Cleat\Price $price
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $brandName
 * @var string $buttonColor
 * @var string|null $cardLabel
 */
$row = 'padding:8px 0;font-size:14px;line-height:20px;';
$endsOn = $subscription->trial_ends_at?->format('F j, Y') ?? '';
?>
<?= $partial('email_start', [
    'title' => 'Your trial ends soon',
    'preheader' => sprintf('Your free trial ends on %s.', $endsOn),
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 12px;font-size:22px;line-height:30px;font-weight:700;color:#111827;">Your free trial ends on <?= $e($endsOn) ?></p>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Hi <?= $e($customer->name ?? 'there') ?>, your trial of <?= $e($price->label()) ?> ends soon. On that day the first paid period starts and is charged.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0;border-top:1px solid #e5e7eb;">
<tr><td style="<?= $e($row) ?>color:#6b7280;">Trial ends</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($endsOn) ?></td></tr>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Then</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($price->display($subscription->quantity)) ?></td></tr>
<?php if ($cardLabel !== null): ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Card on file</td><td align="right" style="<?= $e($row) ?>color:#111827;"><?= $e($cardLabel) ?></td></tr>
<?php else: ?>
<tr><td style="<?= $e($row) ?>color:#6b7280;">Card on file</td><td align="right" style="<?= $e($row) ?>color:#111827;">None yet: add one to keep your plan</td></tr>
<?php endif; ?>
</table>
<p style="margin:20px 0 0;font-size:14px;line-height:21px;color:#374151;">Cancel before the trial ends and you won't be charged.</p>
<?= $partial('email_end', ['brand' => $brand, 'brandName' => $brandName]) ?>
