<?php
/**
 * A charge failed. Carries the pay link so the customer can enter a new card
 * and pay right away.
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $brandName
 * @var string $buttonColor
 * @var string|null $payUrl
 * @var \DateTimeImmutable|null $nextAttempt
 * @var string|null $cardLabel
 */
$due = $invoice->amountDueMoney();
?>
<?= $partial('email_start', [
    'title' => 'Payment failed',
    'preheader' => sprintf('We could not process your payment of %s. Update your card to keep things running.', $due->format()),
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 12px;font-size:22px;line-height:30px;font-weight:700;color:#111827;">We couldn't process your payment</p>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Hi <?= $e($customer->name ?? 'there') ?>, we tried to charge <?= $e($due) ?><?php if ($cardLabel !== null): ?> to your <?= $e($cardLabel) ?><?php endif; ?> for invoice <?= $e($invoice->number) ?>, but the payment didn't go through.</p>
<?php if ($nextAttempt !== null): ?>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">We'll try again on <strong><?= $e($nextAttempt->format('F j, Y')) ?></strong>. To avoid any interruption, update your card and pay now.</p>
<?php endif; ?>

<?php if ($payUrl !== null): ?>
<?= $partial('email_button', ['url' => $payUrl, 'label' => 'Update card and pay', 'color' => $buttonColor]) ?>
<?php endif; ?>

<?= $partial('email_items', ['invoice' => $invoice, 'items' => $items]) ?>

<p style="margin:24px 0 0;font-size:14px;line-height:21px;color:#374151;">Common reasons: the card expired, the bank flagged the charge, or there weren't enough funds. Paying with a different card fixes most of these.</p>
<?php if ($payUrl !== null): ?>
<p style="margin:20px 0 0;font-size:13px;line-height:20px;color:#6b7280;">Button not working? Paste this link into your browser:<br><a href="<?= $e($payUrl) ?>" style="color:#374151;word-break:break-all;"><?= $e($payUrl) ?></a></p>
<?php endif; ?>
<?= $partial('email_end', ['brand' => $brand, 'brandName' => $brandName]) ?>
