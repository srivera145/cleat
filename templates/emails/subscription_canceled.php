<?php
/**
 * A subscription was canceled: either at the end of its period ($immediately
 * false: it keeps working until ends_at and can still be resumed) or right
 * away ($immediately true: by the customer, the host, or the last failed
 * dunning retry).
 *
 * @var callable $e
 * @var callable $partial
 * @var \Cleat\Subscription $subscription
 * @var \Cleat\Price $price
 * @var \Cleat\Customer $customer
 * @var array $brand
 * @var string $brandName
 * @var string $buttonColor
 * @var bool $immediately
 */
$endsOn = ($subscription->ends_at ?? $subscription->current_period_end)->format('F j, Y');
?>
<?= $partial('email_start', [
    'title' => 'Your subscription is canceled',
    'preheader' => $immediately ? 'Your subscription has ended.' : sprintf('Your subscription stays active until %s.', $endsOn),
    'brand' => $brand,
    'brandName' => $brandName,
]) ?>
<p style="margin:0 0 12px;font-size:22px;line-height:30px;font-weight:700;color:#111827;">Your subscription is canceled</p>
<?php if ($immediately): ?>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Hi <?= $e($customer->name ?? 'there') ?>, your subscription to <?= $e($price->label()) ?> ended on <?= $e($endsOn) ?>. You won't be charged again.</p>
<?php else: ?>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Hi <?= $e($customer->name ?? 'there') ?>, your subscription to <?= $e($price->label()) ?> stays active until <strong><?= $e($endsOn) ?></strong>, and you won't be charged again.</p>
<p style="margin:0 0 12px;font-size:15px;line-height:22px;color:#374151;">Changed your mind? You can reactivate it any time before then.</p>
<?php endif; ?>
<?= $partial('email_end', ['brand' => $brand, 'brandName' => $brandName]) ?>
