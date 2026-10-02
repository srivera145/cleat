<?php
/**
 * Brand mark for the hosted pages: the logo if configured, else the name.
 *
 * @var callable $e
 * @var array $brand
 */
$name = trim((string) ($brand['name'] ?? ''));
$logo = $brand['logo_url'] ?? null;
?>
<div class="bar">
<?php if (is_string($logo) && $logo !== ''): ?>
  <img class="cleat-logo" src="<?= $e($logo) ?>" alt="<?= $e($name !== '' ? $name : 'Logo') ?>">
<?php elseif ($name !== ''): ?>
  <span class="h5 fw-bold"><?= $e($name) ?></span>
<?php endif; ?>
  <span class="push text-sm text-muted cleat-secure">
    <svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5Zm-3 8V7a3 3 0 1 1 6 0v3H9Z"/></svg>
    Secure payment
  </span>
</div>
