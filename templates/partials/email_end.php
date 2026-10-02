<?php
/**
 * Email shell, bottom half.
 *
 * @var callable $e
 * @var array $brand
 * @var string $brandName
 */
$support = trim((string) ($brand['support_email'] ?? ''));
$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
</td>
</tr>
</table>
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
<tr>
<td style="padding:20px 24px;font-family:<?= $e($font) ?>;font-size:13px;line-height:20px;color:#6b7280;text-align:center;">
<?php if ($support !== ''): ?>
Questions? Reply to this email or write to <a href="mailto:<?= $e($support) ?>" style="color:#6b7280;text-decoration:underline;"><?= $e($support) ?></a>.<br>
<?php endif; ?>
Payments are processed securely by Authorize.net.
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
