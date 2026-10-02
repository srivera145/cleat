<?php
/**
 * Invoice email with a "Pay invoice" button to the hosted pay page.
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\Invoice $invoice
 * @var list<\Cleat\InvoiceItem> $items
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $brandName
 * @var string $buttonColor
 * @var string $payUrl
 */
$due = $invoice->amountDueMoney();
?>
<?= $partial('email_start', [
    'title' => 'Invoice ' . $invoice->number,
    'preheader' => sprintf('%s due %s. Pay online in a minute.', $due->format(), $invoice->due_at->format('M j, Y')),
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 4px;font-size:13px;line-height:20px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;">Invoice <?= $e($invoice->number) ?></p>
<p class="cleat-big" style="margin:0;font-size:36px;line-height:44px;font-weight:700;color:#111827;"><?= $e($due) ?></p>
<p style="margin:4px 0 0;font-size:15px;line-height:22px;color:#4b5563;">Due <?= $e($invoice->due_at->format('F j, Y')) ?></p>

<?= $partial('email_button', ['url' => $payUrl, 'label' => 'Pay invoice', 'color' => $buttonColor]) ?>

<p style="margin:0 0 4px;font-size:15px;line-height:22px;color:#111827;">Hi <?= $e($customer->name ?? 'there') ?>,</p>
<p style="margin:0 0 16px;font-size:15px;line-height:22px;color:#374151;">Here is your invoice from <?= $e($brandName) ?>. You can pay it securely online without an account.</p>

<?= $partial('email_items', ['invoice' => $invoice, 'items' => $items]) ?>

<?php if ($invoice->memo !== null && $invoice->memo !== ''): ?>
<p style="margin:24px 0 0;font-size:14px;line-height:21px;color:#374151;"><?= $e($invoice->memo) ?></p>
<?php endif; ?>

<p style="margin:28px 0 0;font-size:13px;line-height:20px;color:#6b7280;">Button not working? Paste this link into your browser:<br><a href="<?= $e($payUrl) ?>" style="color:#374151;word-break:break-all;"><?= $e($payUrl) ?></a></p>
<?= $partial('email_end', ['brand' => $brand, 'brandName' => $brandName]) ?>
