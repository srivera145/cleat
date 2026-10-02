<?php
/**
 * 403 / 404 / 410 page for the hosted links.
 *
 * @var callable $e
 * @var callable $partial
 * @var int $status
 * @var string $title
 * @var string $message
 * @var array $brand
 * @var string $deckCss       (base data from HostedPage)
 * @var string $cspNonce
 * @var string $acceptJsUrl
 * @var string $apiLoginId
 * @var string $clientKey
 * @var bool $fakeMode
 * @var array<string, string> $testTokens
 * @var string $captchaHtml
 */
$support = trim((string) ($brand['support_email'] ?? ''));
?>
<?= $partial('head', ['title' => $title, 'deckCss' => $deckCss, 'cspNonce' => $cspNonce, 'brand' => $brand]) ?>
<body class="cleat-page">
<main class="container container-sm cleat-shell">
  <div class="stack stack-6">
    <?= $partial('brand', ['brand' => $brand]) ?>
    <div class="card">
      <div class="card-body center">
        <span class="icon-tile icon-tile-lg" aria-hidden="true">
          <svg class="icon icon-lg" viewBox="0 0 24 24" fill="currentColor"><path d="M11 17h2v-6h-2Zm1-8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm0 13a10 10 0 1 1 0-20 10 10 0 0 1 0 20Zm0-2a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z"/></svg>
        </span>
        <h1 class="h3"><?= $e($title) ?></h1>
        <p class="text-muted"><?= $e($message) ?></p>
<?php if ($support !== ''): ?>
        <a class="btn btn-outline" href="mailto:<?= $e($support) ?>">Contact <?= $e($support) ?></a>
<?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
