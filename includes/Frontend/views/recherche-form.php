<?php
/**
 * Custom vehicle search form (4 steps). Same design system as the estimation form.
 *
 * @var array $settings
 */

use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

$icons_file = KDV_LEADS_DIR . 'assets/img/icons.svg';
$logo_file  = KDV_LEADS_DIR . 'assets/img/logo-kdv.png';
$req        = '<span class="kdv-req" aria-hidden="true">*</span>';
$privacy    = (string) $settings['privacy_url'];
$years      = O::years();
array_unshift($years, (int) wp_date('Y') + 1);

/** Checkbox/radio "tag" cards: label only. */
$tags = static function (string $name, array $options, string $type, string $class = '') {
    echo '<div class="kdv-choices kdv-choices--tags ' . esc_attr($class) . '">';
    foreach ($options as $value => $label) {
        printf(
            '<label class="kdv-choice kdv-choice--tag%1$s"><input class="kdv-choice__input" type="%2$s" name="%3$s" value="%4$s"><span class="kdv-choice__box"><span class="kdv-choice__title">%5$s</span></span></label>',
            $type === 'checkbox' ? ' kdv-choice--check' : '', esc_attr($type), esc_attr($name . ($type === 'checkbox' ? '[]' : '')), esc_attr((string) $value), esc_html((string) $label)
        );
    }
    echo '</div>';
};

$select = static function (string $id, string $name, string $placeholder, array $options) {
    echo '<select class="kdv-select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="">' . esc_html($placeholder) . '</option>';
    foreach ($options as $value => $label) {
        echo '<option value="' . esc_attr((string) $value) . '">' . esc_html((string) $label) . '</option>';
    }
    echo '</select>';
};

/** Chips widget: free entries stored as a JSON array in a hidden input. */
$chips = static function (string $id, string $name, string $label, string $placeholder, bool $required, int $max, array $suggestions = []) use ($req) {
    ?>
    <div class="kdv-field" data-field="<?php echo esc_attr($name); ?>"<?php echo $required ? ' data-required' : ''; ?>>
      <label class="kdv-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?> <?php echo $required ? $req : '<small>(facultatif)</small>'; ?></label>
      <div class="kdv-chips" data-kdv-chips data-max="<?php echo (int) $max; ?>">
        <ul class="kdv-chips__list" data-chips-list aria-label="<?php echo esc_attr($label); ?> : éléments ajoutés"></ul>
        <div class="kdv-chips__add">
          <input class="kdv-input" id="<?php echo esc_attr($id); ?>" type="text" maxlength="80" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off" enterkeyhint="done">
          <button type="button" class="kdv-btn kdv-btn--secondary kdv-btn--small" data-chips-add>Ajouter</button>
        </div>
        <?php if ($suggestions) : ?>
          <div class="kdv-chips__suggest" role="group" aria-label="Suggestions">
            <?php foreach ($suggestions as $s) : ?>
              <button type="button" class="kdv-chip kdv-chip--suggest" data-chips-suggest="<?php echo esc_attr($s); ?>" aria-pressed="false"><?php echo esc_html($s); ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="[]" data-chips-value>
      </div>
    </div>
    <?php
};

if (is_readable($icons_file)) {
    echo '<div hidden>' . file_get_contents($icons_file) . '</div>'; // static plugin file, inline sprite
}
?>
<section class="kdv-app kdv-hero" aria-labelledby="kdv-hero-title">
  <h1 class="kdv-hero__title" id="kdv-hero-title">Vous recherchez un véhicule précis ?</h1>
  <p class="kdv-hero__text">Décrivez vos critères. KDV Motors étudie votre projet et vous recontacte pour une recherche personnalisée.</p>
  <a class="kdv-btn kdv-btn--primary kdv-hero__cta" href="#kdv-recherche-form" data-kdv-scroll>Décrire ma recherche <?php echo O::icon('arrow-right'); ?></a>
</section>

<div class="kdv-app" id="kdv-recherche-form" data-kdv-form="recherche" data-home-url="<?php echo esc_url(home_url('/')); ?>">

  <header class="kdv-banner">
    <a class="kdv-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php if (is_readable($logo_file)) : ?>
        <img src="<?php echo esc_url(KDV_LEADS_URL . 'assets/img/logo-kdv.png'); ?>" alt="KDV Motors, achat, vente, reprise">
      <?php else : ?>
        <span class="kdv-logo__mark" aria-hidden="true">KD<span>V</span></span>
        <span class="kdv-logo__tag">ACHAT - VENTE - REPRISE</span>
        <span class="kdv-sr-only">KDV Motors</span>
      <?php endif; ?>
    </a>
    <div class="kdv-banner__side">
      <ul class="kdv-assurances">
        <li class="kdv-assurance"><?php echo O::icon('stopwatch'); ?><span><strong>Simple</strong>et rapide</span></li>
        <li class="kdv-assurance"><?php echo O::icon('euro'); ?><span><strong>Gratuit</strong>et sans engagement</span></li>
        <li class="kdv-assurance"><?php echo O::icon('phone'); ?><span><strong>Un conseiller</strong>vous recontacte</span></li>
      </ul>
    </div>
  </header>

  <ol class="kdv-stepper" data-kdv-stepper aria-label="Progression de la demande">
    <?php foreach (['Le véhicule', 'Budget et options', 'Précisions', 'Vos coordonnées'] as $i => $label) : ?>
      <li class="kdv-stepper__item"><span class="kdv-stepper__dot"><span class="kdv-stepper__num"><?php echo (int) ($i + 1); ?></span></span><span class="kdv-stepper__label"><?php echo esc_html($label); ?></span></li>
    <?php endforeach; ?>
  </ol>

  <form novalidate autocomplete="on">
    <div class="kdv-hp" aria-hidden="true">
      <label>Ne pas remplir ce champ<input type="text" name="website" tabindex="-1" autocomplete="off" data-no-draft></label>
    </div>

    <!-- Étape 1 -->
    <section data-kdv-step aria-labelledby="kdv-r1">
      <h2 class="kdv-step__title" id="kdv-r1" data-kdv-heading tabindex="-1">Étape 1 : Quel véhicule recherchez-vous ?</h2>
      <p class="kdv-step__subtitle">Indiquez la ou les marques et modèles qui vous intéressent, puis vos critères principaux.</p>
      <div class="kdv-grid">
        <?php $chips('kdv-mm', 'marques_modeles', 'Marque(s) et modèle(s) recherchés', 'Ex : Porsche Cayman', true, 10); ?>
        <div class="kdv-field" data-max="200">
          <label class="kdv-label" for="kdv-versions">Versions / finitions souhaitées <small>(facultatif)</small></label>
          <input class="kdv-input" id="kdv-versions" name="versions" maxlength="200" placeholder="Ex : GTS, PDK, pack Sport Chrono">
        </div>
        <div class="kdv-field">
          <label class="kdv-label" for="kdv-amin">Année minimum <small>(facultatif)</small></label>
          <?php $select('kdv-amin', 'annee_min', 'Peu importe', array_combine($years, $years)); ?>
        </div>
        <div class="kdv-field">
          <label class="kdv-label" for="kdv-amax">Année maximum <small>(facultatif)</small></label>
          <?php $select('kdv-amax', 'annee_max', 'Peu importe', array_combine($years, $years)); ?>
        </div>
        <div class="kdv-field" data-validate="km">
          <label class="kdv-label" for="kdv-kmmax">Kilométrage maximum <small>(facultatif)</small></label>
          <div class="kdv-input-wrap kdv-input-wrap--suffix"><input class="kdv-input" id="kdv-kmmax" name="km_max" inputmode="numeric" data-format="thousands" placeholder="Ex : 80 000" autocomplete="off"><span class="kdv-suffix" aria-hidden="true">km</span></div>
        </div>
      </div>

      <fieldset class="kdv-field kdv-field--spaced">
        <legend>Carburant <small>(plusieurs choix possibles)</small></legend>
        <?php $tags('carburant', O::motorisations(), 'checkbox'); ?>
      </fieldset>
      <fieldset class="kdv-field kdv-field--spaced">
        <legend>Carrosserie <small>(plusieurs choix possibles)</small></legend>
        <?php $tags('carrosserie', O::carrosseries(), 'checkbox'); ?>
      </fieldset>
      <fieldset class="kdv-field kdv-field--spaced">
        <legend>Boîte de vitesses</legend>
        <?php $tags('boite', O::boites_recherche(), 'radio'); ?>
      </fieldset>
      <div class="kdv-grid kdv-field--spaced">
        <div class="kdv-field" data-max="150">
          <label class="kdv-label" for="kdv-csou">Couleurs souhaitées <small>(facultatif)</small></label>
          <input class="kdv-input" id="kdv-csou" name="couleurs_souhaitees" maxlength="150" placeholder="Ex : noir, gris anthracite">
        </div>
        <div class="kdv-field" data-max="150">
          <label class="kdv-label" for="kdv-cexc">Couleurs à exclure <small>(facultatif)</small></label>
          <input class="kdv-input" id="kdv-cexc" name="couleurs_exclues" maxlength="150" placeholder="Ex : jaune, orange">
        </div>
      </div>
    </section>

    <!-- Étape 2 -->
    <section data-kdv-step aria-labelledby="kdv-r2" hidden>
      <h2 class="kdv-step__title" id="kdv-r2" data-kdv-heading tabindex="-1">Étape 2 : Votre budget et vos options</h2>
      <p class="kdv-step__subtitle">Précisez votre enveloppe et les équipements qui comptent pour vous.</p>
      <div class="kdv-grid">
        <div class="kdv-field" data-validate="money">
          <label class="kdv-label" for="kdv-bmin">Budget minimum <small>(facultatif)</small></label>
          <div class="kdv-input-wrap kdv-input-wrap--suffix"><input class="kdv-input" id="kdv-bmin" name="budget_min" inputmode="numeric" data-format="thousands7" placeholder="Ex : 30 000" autocomplete="off"><span class="kdv-suffix" aria-hidden="true">€</span></div>
        </div>
        <div class="kdv-field" data-required data-validate="money">
          <label class="kdv-label" for="kdv-bmax">Budget maximum <?php echo $req; ?></label>
          <div class="kdv-input-wrap kdv-input-wrap--suffix"><input class="kdv-input" id="kdv-bmax" name="budget_max" inputmode="numeric" data-format="thousands7" placeholder="Ex : 50 000" autocomplete="off"><span class="kdv-suffix" aria-hidden="true">€</span></div>
        </div>
      </div>
      <div class="kdv-field--spaced">
        <?php $chips('kdv-oind', 'options_indispensables', 'Options indispensables', 'Ajouter une option…', false, 15, O::option_suggestions()); ?>
      </div>
      <div class="kdv-field--spaced">
        <?php $chips('kdv-osou', 'options_souhaitables', 'Options souhaitables', 'Ajouter une option…', false, 15, O::option_suggestions()); ?>
      </div>
      <fieldset class="kdv-field kdv-field--spaced">
        <legend>Origine acceptée <small>(plusieurs choix possibles)</small></legend>
        <?php $tags('origine_acceptee', O::origines(), 'checkbox'); ?>
      </fieldset>
      <fieldset class="kdv-field kdv-field--spaced">
        <legend>Flexibilité sur vos critères</legend>
        <?php $tags('flexibilite', O::flexibilites(), 'radio'); ?>
      </fieldset>
    </section>

    <!-- Étape 3 -->
    <section data-kdv-step aria-labelledby="kdv-r3" hidden>
      <h2 class="kdv-step__title" id="kdv-r3" data-kdv-heading tabindex="-1">Étape 3 : Vos précisions</h2>
      <p class="kdv-step__subtitle">Quelques éléments pour mieux cibler la recherche. Tout est facultatif.</p>
      <fieldset class="kdv-field">
        <legend>Date d'achat envisagée</legend>
        <div class="kdv-choices kdv-choices--icons">
          <?php foreach (O::delais() as $value => [$label, $icon]) : ?>
            <label class="kdv-choice kdv-choice--icon"><input class="kdv-choice__input" type="radio" name="date_achat_envisagee" value="<?php echo esc_attr($value); ?>"><span class="kdv-choice__box"><?php echo O::icon($icon); ?><?php echo esc_html($label); ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="kdv-grid kdv-field--spaced">
        <div class="kdv-field" data-max="100">
          <label class="kdv-label" for="kdv-loc">Localisation souhaitée <small>(facultatif)</small></label>
          <input class="kdv-input" id="kdv-loc" name="localisation" maxlength="100" placeholder="Ex : Île-de-France, rayon 100 km">
        </div>
        <fieldset class="kdv-field">
          <legend>Avez-vous un véhicule à faire reprendre ?</legend>
          <?php $tags('reprise', ['oui' => 'Oui', 'non' => 'Non'], 'radio'); ?>
        </fieldset>
      </div>
      <div class="kdv-field kdv-field--spaced" data-max="2000">
        <label class="kdv-label" for="kdv-com">Commentaire <small>(facultatif)</small></label>
        <textarea class="kdv-input kdv-textarea" id="kdv-com" name="commentaire" rows="4" maxlength="2000" placeholder="Tout ce qui peut nous aider : usage prévu, détails importants…"></textarea>
      </div>
      <div class="kdv-field kdv-field--spaced" data-validate="links">
        <label class="kdv-label" for="kdv-liens">Liens d'annonces qui vous plaisent <small>(facultatif, 5 maximum, un par ligne)</small></label>
        <textarea class="kdv-input kdv-textarea" id="kdv-liens" name="liens_annonces" rows="3" maxlength="1500" placeholder="https://…"></textarea>
      </div>

      <div class="kdv-field kdv-field--spaced kdv-upload" data-kdv-uploader data-max="5">
        <span class="kdv-label">Photos d'exemple <small>(facultatif, 5 maximum)</small></span>
        <div class="kdv-upload__drop">
          <input class="kdv-sr-only" type="file" id="kdv-photos" accept="image/jpeg,image/png,image/webp" multiple>
          <label for="kdv-photos" class="kdv-btn kdv-btn--secondary kdv-upload__btn">Ajouter des photos</label>
          <span class="kdv-upload__hint">JPEG, PNG ou WebP</span>
        </div>
        <ul class="kdv-upload__list" data-upload-list></ul>
        <p class="kdv-error" data-upload-error hidden role="alert"></p>
      </div>
    </section>

    <!-- Étape 4 -->
    <section data-kdv-step aria-labelledby="kdv-r4" hidden>
      <h2 class="kdv-step__title" id="kdv-r4" data-kdv-heading tabindex="-1">Étape 4 : Vos coordonnées</h2>
      <p class="kdv-step__subtitle">Pour que nous puissions vous recontacter au sujet de votre recherche.</p>
      <div class="kdv-grid">
        <div class="kdv-field" data-required data-min="2" data-max="100">
          <label class="kdv-label" for="kdv-prenom">Prénom <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-prenom" name="prenom" autocomplete="given-name" maxlength="100">
        </div>
        <div class="kdv-field" data-required data-min="2" data-max="100">
          <label class="kdv-label" for="kdv-nom">Nom <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-nom" name="nom" autocomplete="family-name" maxlength="100">
        </div>
        <div class="kdv-field" data-required data-validate="phone"<?php echo !empty($settings['accept_foreign_phones']) ? ' data-foreign="1"' : ''; ?>>
          <label class="kdv-label" for="kdv-tel">Téléphone <?php echo $req; ?></label>
          <div class="kdv-input-wrap"><?php echo O::icon('phone'); ?><input class="kdv-input" id="kdv-tel" name="telephone" type="tel" autocomplete="tel" placeholder="Ex : 06 12 34 56 78"></div>
        </div>
        <div class="kdv-field" data-required data-validate="email" data-max="190">
          <label class="kdv-label" for="kdv-mail">E-mail <?php echo $req; ?></label>
          <div class="kdv-input-wrap"><?php echo O::icon('mail'); ?><input class="kdv-input" id="kdv-mail" name="email" type="email" autocomplete="email" maxlength="190" placeholder="Ex : votre@email.com"></div>
        </div>
        <div class="kdv-field" data-required data-validate="postal">
          <label class="kdv-label" for="kdv-cp">Code postal <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-cp" name="code_postal" inputmode="numeric" autocomplete="postal-code" maxlength="5" placeholder="Ex : 75008">
        </div>
        <div class="kdv-field">
          <label class="kdv-label" for="kdv-creneau">Créneau de rappel <small>(facultatif)</small></label>
          <?php $select('kdv-creneau', 'creneau_rappel', 'Peu importe', O::creneaux()); ?>
        </div>
      </div>

      <div class="kdv-field" data-required>
        <label class="kdv-consent"><input type="checkbox" name="consentement" value="1"><span>J'accepte que mes données soient utilisées afin d'être recontacté dans le cadre de ma recherche personnalisée. <?php echo $req; ?></span></label>
        <?php if ($privacy !== '') : ?>
          <a class="kdv-link" href="<?php echo esc_url($privacy); ?>" target="_blank" rel="noopener">Voir la politique de confidentialité</a>
        <?php endif; ?>
      </div>
      <?php if (!empty($settings['show_marketing_consent'])) : ?>
        <div class="kdv-field">
          <label class="kdv-consent"><input type="checkbox" name="consentement_marketing" value="1"><span>J'accepte de recevoir les offres et actualités de KDV Motors. (facultatif)</span></label>
        </div>
      <?php endif; ?>
    </section>

    <?php if (!empty($settings['turnstile_site_key'])) : ?>
      <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($settings['turnstile_site_key']); ?>" data-language="fr"></div>
    <?php endif; ?>

    <div class="kdv-alert" data-kdv-alert role="alert" hidden></div>

    <div class="kdv-actions">
      <button type="button" class="kdv-btn kdv-btn--secondary" data-kdv-prev><?php echo O::icon('arrow-left'); ?>Retour</button>
      <button type="button" class="kdv-btn kdv-btn--primary" data-kdv-next>Continuer <?php echo O::icon('arrow-right'); ?></button>
      <button type="submit" class="kdv-btn kdv-btn--primary" data-kdv-submit hidden><span data-kdv-submit-label>Envoyer ma recherche</span> <?php echo O::icon('arrow-right'); ?></button>
    </div>
    <p class="kdv-reassure" data-kdv-last-only hidden>Gratuit et sans engagement. Notre équipe vous recontacte pour échanger sur votre recherche.</p>
  </form>
</div>
