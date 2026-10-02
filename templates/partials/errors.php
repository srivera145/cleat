<?php
/**
 * Server-side form errors. No role="alert": the message is present at load,
 * and an alert role would interrupt a screen reader before it reads the page.
 *
 * @var callable $e
 * @var list<string> $errors
 */
?>
<?php if (!empty($errors)): ?>
<div class="alert alert-bad" id="cleat-form-errors" tabindex="-1">
  <div>
    <p class="alert-title">We couldn't complete that</p>
<?php foreach ($errors as $message): ?>
    <p class="alert-body"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
