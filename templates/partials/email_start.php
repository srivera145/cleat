<?php
/**
 * Email shell, top half. Email clients ignore @layer and most external CSS,
 * so every style that matters is inline; the <style> block only adds a
 * narrow-screen padding tweak for clients that honour it.
 *
 * @var callable $e
 * @var string $title
 * @var string $preheader
 * @var array $brand
 * @var string $brandName
 */
$logo = $brand['logo_url'] ?? null;
$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<!doctype html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?= $e($title) ?></title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
  @media (max-width: 620px) { .cleat-pad { padding: 24px 20px !important; } .cleat-big { font-size: 30px !important; } }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;"><?= $e($preheader) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
<tr>
<td class="cleat-pad" style="padding:36px 40px;font-family:<?= $e($font) ?>;font-size:16px;line-height:24px;color:#111827;">
<?php if (is_string($logo) && $logo !== ''): ?>
<img src="<?= $e($logo) ?>" alt="<?= $e($brandName) ?>" height="36" style="display:block;height:36px;width:auto;border:0;margin:0 0 28px;">
<?php else: ?>
<p style="margin:0 0 28px;font-size:18px;line-height:24px;font-weight:700;color:#111827;"><?= $e($brandName) ?></p>
<?php endif; ?>
