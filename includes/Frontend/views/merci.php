<?php
/**
 * Thank-you page. $kind: 'estimation' (default) or 'recherche'.
 * Texts in $title / $lead are the client-approved wording.
 *
 * @var string $kind
 */

use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

$kind = $kind ?? 'estimation';
$is_search = $kind === 'recherche';

$title = $is_search ? 'Merci, votre recherche a bien été transmise !' : 'Merci, votre demande a bien été envoyée !';
$lead = $is_search
    ? 'Votre recherche a bien été transmise. KDV Motors vous recontactera prochainement.'
    : 'Nous avons bien reçu les informations concernant votre véhicule. Un conseiller vous recontactera prochainement pour échanger sur votre projet de vente et organiser un rendez-vous.';

// "What happens next": no delay is promised, only the order of events.
$steps = $is_search
    ? [
        ['Recherche reçue', 'Vos critères sont bien enregistrés.'],
        ['Un conseiller vous rappelle', 'Il revient vers vous pour préciser votre projet.'],
        ['Recherche personnalisée', 'KDV Motors étudie les véhicules qui correspondent.'],
    ]
    : [
        ['Demande reçue', 'Les informations de votre véhicule sont bien enregistrées.'],
        ['Un conseiller vous rappelle', 'Il échange avec vous sur votre projet de vente.'],
        ['Rendez-vous', 'Nous organisons ensemble la suite, sans engagement.'],
    ];

$logo_file = KDV_LEADS_DIR . 'assets/img/logo-kdv.png';
$art = $is_search ? 'kdv-thanks__art--m5' : 'kdv-thanks__art--suv';

if (is_readable(KDV_LEADS_DIR . 'assets/img/icons.svg')) {
    echo '<div hidden>' . file_get_contents(KDV_LEADS_DIR . 'assets/img/icons.svg') . '</div>'; // static plugin file, inline sprite
}
?>
<div class="kdv-app kdv-thanks">
  <header class="kdv-thanks__top">
    <a class="kdv-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php if (is_readable($logo_file)) : ?>
        <img src="<?php echo esc_url(KDV_LEADS_URL . 'assets/img/logo-kdv.png'); ?>" alt="KDV Motors, achat, vente, reprise">
      <?php else : ?>
        <span class="kdv-logo__mark" aria-hidden="true">KD<span>V</span></span>
        <span class="kdv-sr-only">KDV Motors</span>
      <?php endif; ?>
    </a>
  </header>

  <div class="kdv-thanks__grid">
    <div class="kdv-thanks__main" role="status">
      <span class="kdv-thanks__badge" aria-hidden="true">
        <svg viewBox="0 0 52 52" width="64" height="64" focusable="false">
          <circle class="kdv-thanks__ring" cx="26" cy="26" r="23" fill="none"/>
          <path class="kdv-thanks__tick" d="M15 27.5l8 8 15-17" fill="none"/>
        </svg>
      </span>
      <h1 class="kdv-thanks__title" tabindex="-1"><?php echo esc_html($title); ?></h1>
      <p class="kdv-thanks__lead"><?php echo esc_html($lead); ?></p>
      <p class="kdv-thanks__ref" data-kdv-ref hidden>Référence de votre demande : <strong></strong></p>

      <h2 class="kdv-thanks__subtitle">Et maintenant ?</h2>
      <ol class="kdv-next">
        <?php foreach ($steps as $i => [$label, $text]) : ?>
          <li class="kdv-next__item<?php echo $i === 0 ? ' is-done' : ''; ?>">
            <span class="kdv-next__dot" aria-hidden="true"><?php echo $i === 0 ? '&#10003;' : (int) ($i + 1); ?></span>
            <span class="kdv-next__text"><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html($text); ?></span></span>
          </li>
        <?php endforeach; ?>
      </ol>

      <div class="kdv-thanks__actions">
        <a class="kdv-btn kdv-btn--secondary kdv-btn--wide" href="<?php echo esc_url(home_url('/')); ?>"><?php echo O::icon('arrow-left'); ?>Retour à l'accueil</a>
      </div>
    </div>
    <div class="kdv-thanks__art <?php echo esc_attr($art); ?>" aria-hidden="true"></div>
  </div>
</div>
