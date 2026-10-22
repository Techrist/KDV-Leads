<?php
/**
 * Photos grid for the PDF (2 per row, ratio kept). Only rendered when photos exist.
 *
 * @var array $photos [['path' => absolute file path, 'caption' => string], ...]
 */
defined('ABSPATH') || exit;

if (empty($photos)) {
    return;
}
?>
<h2>Photos</h2>
<table width="100%" cellpadding="4" style="border-collapse:collapse;">
  <?php foreach (array_chunk($photos, 2) as $pair) : ?>
    <tr>
      <?php foreach ($pair as $p) : ?>
        <td width="50%" style="text-align:center;vertical-align:top;">
          <img src="<?php echo esc_attr($p['path']); ?>" style="max-width:86mm;max-height:80mm;"><br>
          <span class="d"><?php echo esc_html($p['caption']); ?></span>
        </td>
      <?php endforeach; ?>
      <?php if (count($pair) === 1) : ?><td width="50%"></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
</table>
