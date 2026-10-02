<?php
/**
 * Buyer details on the checkout form.
 *
 * @var callable $e
 * @var \Cleat\PaymentLink $link
 * @var array{email: string, name: ?string, phone: ?string, quantity: int} $values
 * @var int $minQuantity
 * @var int $maxQuantity
 */
?>
<div class="stack stack-3">
  <div class="field">
    <label class="label" for="cleat-email">Email</label>
    <input class="input" id="cleat-email" name="email" type="email" autocomplete="email" required maxlength="255" value="<?= $e($values['email'] ?? '') ?>">
    <p class="help">Your receipt goes here.</p>
  </div>
<?php if ($link->collect_name): ?>
  <div class="field">
    <label class="label" for="cleat-name">Name</label>
    <input class="input" id="cleat-name" name="name" type="text" autocomplete="name" required maxlength="255" value="<?= $e($values['name'] ?? '') ?>">
  </div>
<?php endif; ?>
<?php if ($link->collect_phone): ?>
  <div class="field">
    <label class="label" for="cleat-phone">Phone</label>
    <input class="input" id="cleat-phone" name="phone" type="tel" autocomplete="tel" required maxlength="32" value="<?= $e($values['phone'] ?? '') ?>">
  </div>
<?php endif; ?>
<?php if ($link->allow_quantity_change): ?>
  <div class="field">
    <label class="label" for="cleat-quantity">Quantity</label>
    <input class="input w-auto" id="cleat-quantity" name="quantity" type="number" inputmode="numeric" min="<?= $e($minQuantity) ?>" max="<?= $e($maxQuantity) ?>" step="1" value="<?= $e($values['quantity'] ?? $link->quantity) ?>">
  </div>
<?php endif; ?>
</div>
