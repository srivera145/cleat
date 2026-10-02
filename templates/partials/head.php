<?php
/**
 * Document head for the hosted pages (pay, checkout, success, error).
 *
 * @var callable $e
 * @var string $title
 * @var string $deckCss
 * @var string|null $cspNonce
 * @var array $brand
 */
$hue = isset($brand['hue']) && is_numeric($brand['hue']) ? (int) $brand['hue'] : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= $e($title) ?></title>
<link rel="stylesheet" href="<?= $e($deckCss) ?>">
<style<?php if (!empty($cspNonce)): ?> nonce="<?= $e($cspNonce) ?>"<?php endif; ?>>
<?php if ($hue !== null): ?>
  :root { --hue-brand: <?= $e($hue) ?>; }
<?php endif; ?>
  .cleat-page { background: var(--bg-sunken); }
  .cleat-page [hidden] { display: none !important; }
  .cleat-shell { padding-block: var(--space-6) var(--space-12); }
  .cleat-split { --rail: 24rem; align-items: start; }
  .cleat-logo { display: block; block-size: 36px; inline-size: auto; max-inline-size: 180px; object-fit: contain; }
  .cleat-amount { font-size: var(--text-3xl); font-weight: 700; letter-spacing: -.02em; line-height: 1.1; font-variant-numeric: tabular-nums; }
  .cleat-choices { border: 0; margin: 0; padding: 0; min-inline-size: 0; }
  .cleat-card-input { font-variant-numeric: tabular-nums; letter-spacing: .02em; }
  .cleat-lines td { vertical-align: top; }
  .cleat-lines tfoot th, .cleat-lines tfoot td { font-weight: 600; }
  .cleat-lines .cleat-sub { display: block; font-size: var(--text-xs); color: var(--text-muted); font-weight: 400; }
  .cleat-secure { display: flex; align-items: center; justify-content: center; gap: var(--space-2); }
  .cleat-busy-label { display: inline-flex; align-items: center; gap: var(--space-2); }
  @media (min-width: 64rem) {
    .cleat-sticky { position: sticky; inset-block-start: var(--space-6); }
  }
</style>
</head>
