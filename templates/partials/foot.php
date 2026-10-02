<?php
/**
 * Page footer for the hosted pages.
 *
 * @var callable $e
 * @var array $brand
 */
$support = trim((string) ($brand['support_email'] ?? ''));
?>
<footer class="stack stack-2 text-center text-sm text-muted">
<?php if ($support !== ''): ?>
  <p>Questions? <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a></p>
<?php endif; ?>
  <p class="text-xs text-faint">Card details go straight to Authorize.net and never touch this site.</p>
</footer>
