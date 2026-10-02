<?php
/**
 * Bulletproof button: a table cell with a background colour and a padded
 * link for every client, plus a VML roundrect for desktop Outlook, which
 * ignores padding on links.
 *
 * @var callable $e
 * @var string $url
 * @var string $label
 * @var string $color  #rrggbb
 */
$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0;">
<tr>
<td align="center" bgcolor="<?= $e($color) ?>" style="border-radius:6px;background-color:<?= $e($color) ?>;">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?= $e($url) ?>" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="13%" stroke="f" fillcolor="<?= $e($color) ?>">
<w:anchorlock/>
<center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;"><?= $e($label) ?></center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-- -->
<a href="<?= $e($url) ?>" target="_blank" rel="noopener" style="display:inline-block;padding:14px 32px;font-family:<?= $e($font) ?>;font-size:16px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;background-color:<?= $e($color) ?>;border:1px solid <?= $e($color) ?>;"><?= $e($label) ?></a>
<!--<![endif]-->
</td>
</tr>
</table>
