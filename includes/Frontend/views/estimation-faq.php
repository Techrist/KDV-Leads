<?php
/** @var array $faq */
defined('ABSPATH') || exit;
if (empty($faq)) {
    return;
}
$ld = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(static fn ($i) => [
        '@type' => 'Question',
        'name'  => $i['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']],
    ], $faq),
];
?>
<section class="kdv-faq" aria-labelledby="kdv-faq-title" data-kdv-faq>
  <h2 class="kdv-faq__title" id="kdv-faq-title">Questions fréquentes</h2>
  <?php foreach ($faq as $i => $item) : ?>
    <details class="kdv-faq__item" name="kdv-faq">
      <summary class="kdv-faq__q">
        <span><?php echo esc_html($item['q']); ?></span>
        <span class="kdv-faq__icon" aria-hidden="true"></span>
      </summary>
      <div class="kdv-faq__a"><p><?php echo esc_html($item['a']); ?></p></div>
    </details>
  <?php endforeach; ?>
  <script type="application/ld+json"><?php echo wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</section>
