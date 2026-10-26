<?php
/**
 * Estimation form (4 steps). Markup mirrors tests/preview-estimation.html.
 *
 * @var array $faq
 * @var array $settings
 */

use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

$icons_file = KDV_LEADS_DIR . 'assets/img/icons.svg';
$logo_file  = KDV_LEADS_DIR . 'assets/img/logo-kdv.png';
$req        = '<span class="kdv-req" aria-hidden="true">*</span>';
$privacy    = (string) $settings['privacy_url'];

/** Radio card group (title + description). */
$radio_cards = static function (string $name, array $options, string $extra_class = '') {
    echo '<div class="kdv-choices ' . esc_attr($extra_class) . '">';
    foreach ($options as $value => [$title, $desc]) {
        printf(
            '<label class="kdv-choice"><input class="kdv-choice__input" type="radio" name="%1$s" value="%2$s"><span class="kdv-choice__box"><span class="kdv-choice__title">%3$s</span><span class="kdv-choice__desc">%4$s</span></span></label>',
            esc_attr($name), esc_attr($value), esc_html($title), esc_html($desc)
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

if (is_readable($icons_file)) {
    echo '<div hidden>' . file_get_contents($icons_file) . '</div>'; // static plugin file, inline sprite
}
?>
<div class="kdv-app" data-kdv-form="estimation" data-home-url="<?php echo esc_url(home_url('/')); ?>">

  <header class="kdv-banner">
    <div class="kdv-banner__side">
      <p class="kdv-banner__pitch">Confiez-nous votre véhicule, on s'occupe de tout !</p>
      <ul class="kdv-assurances">
        <li class="kdv-assurance"><?php echo O::icon('stopwatch'); ?><span><strong>Simple</strong>et rapide</span></li>
        <li class="kdv-assurance"><?php echo O::icon('euro'); ?><span><strong>Gratuit</strong>et sans engagement</span></li>
        <li class="kdv-assurance"><?php echo O::icon('phone'); ?><span><strong>Un conseiller</strong>vous recontacte</span></li>
      </ul>
    </div>
  </header>

  <ol class="kdv-stepper" data-kdv-stepper aria-label="Progression de la demande">
    <?php foreach (['Votre véhicule', 'État', 'Options', 'Votre demande'] as $i => $label) : ?>
      <li class="kdv-stepper__item"><span class="kdv-stepper__dot"><span class="kdv-stepper__num"><?php echo (int) ($i + 1); ?></span></span><span class="kdv-stepper__label"><?php echo esc_html($label); ?></span></li>
    <?php endforeach; ?>
  </ol>

  <form novalidate autocomplete="on">
    <div class="kdv-hp" aria-hidden="true">
      <label>Ne pas remplir ce champ<input type="text" name="website" tabindex="-1" autocomplete="off" data-no-draft></label>
    </div>

    <!-- Étape 1 -->
    <section data-kdv-step aria-labelledby="kdv-s1">
      <div class="kdv-ill kdv-ill--suv" aria-hidden="true"></div>
      <h2 class="kdv-step__title" id="kdv-s1" data-kdv-heading tabindex="-1">Étape 1 : Parlez-nous de votre véhicule</h2>
      <p class="kdv-step__subtitle">Renseignez les informations principales de votre véhicule pour obtenir une estimation personnalisée.</p>
      <div class="kdv-grid">
        <div class="kdv-field" data-validate="plate">
          <label class="kdv-label" for="kdv-plate">Immatriculation <small>(facultatif)</small></label>
          <div class="kdv-plate"><span class="kdv-plate__band" aria-hidden="true">F</span>
            <input class="kdv-input" id="kdv-plate" name="immatriculation" data-format="plate" placeholder="Ex : AB-123-CD" autocomplete="off" maxlength="12"></div>
        </div>

        <div class="kdv-field" data-required>
          <label class="kdv-label" for="kdv-marque">Marque <?php echo $req; ?></label>
          <?php
          $brands = array_combine(array_keys(O::vehicles()), array_keys(O::vehicles()));
          $brands['autre'] = 'Autre';
          $select('kdv-marque', 'marque', 'Sélectionnez une marque', $brands);
          ?>
        </div>
        <div class="kdv-field" data-required data-min="2" data-max="60" data-other-for="marque" hidden>
          <label class="kdv-label" for="kdv-marque-autre">Précisez la marque <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-marque-autre" name="marque_autre" maxlength="60" disabled>
        </div>

        <div class="kdv-field" data-required data-model-select>
          <label class="kdv-label" for="kdv-modele">Modèle <?php echo $req; ?></label>
          <select class="kdv-select" id="kdv-modele" name="modele" disabled><option value="">Sélectionnez un modèle</option></select>
        </div>
        <div class="kdv-field" data-required data-min="1" data-max="60" data-other-for="modele" hidden>
          <label class="kdv-label" for="kdv-modele-autre">Précisez le modèle <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-modele-autre" name="modele_autre" maxlength="60" disabled>
        </div>

        <div class="kdv-field"<?php echo !empty($settings['finition_required']) ? ' data-required' : ''; ?> data-max="60">
          <label class="kdv-label" for="kdv-finition">Finition / Version <?php echo !empty($settings['finition_required']) ? $req : '<small>(facultatif)</small>'; ?></label>
          <input class="kdv-input" id="kdv-finition" name="finition" list="kdv-finitions" maxlength="60" placeholder="Ex : GT Line, Allure, Business...">
          <datalist id="kdv-finitions"><?php foreach (O::finitions() as $f) { echo '<option value="' . esc_attr($f) . '">'; } ?></datalist>
        </div>

        <div class="kdv-field" data-required>
          <label class="kdv-label" for="kdv-annee">Année de mise en circulation <?php echo $req; ?></label>
          <?php $years = O::years(); $select('kdv-annee', 'annee_mise_en_circulation', "Sélectionnez l'année", array_combine($years, $years)); ?>
        </div>
        <div class="kdv-field" data-required data-validate="km">
          <label class="kdv-label" for="kdv-km">Kilométrage actuel <?php echo $req; ?></label>
          <div class="kdv-input-wrap kdv-input-wrap--suffix"><input class="kdv-input" id="kdv-km" name="kilometrage" inputmode="numeric" data-format="thousands" placeholder="Ex : 50 000" autocomplete="off"><span class="kdv-suffix" aria-hidden="true">km</span></div>
        </div>
        <div class="kdv-field" data-required>
          <label class="kdv-label" for="kdv-moto">Motorisation <?php echo $req; ?></label>
          <?php $select('kdv-moto', 'motorisation', 'Sélectionnez une motorisation', O::motorisations()); ?>
        </div>
        <div class="kdv-field" data-required>
          <label class="kdv-label" for="kdv-boite">Boîte de vitesses <?php echo $req; ?></label>
          <?php $select('kdv-boite', 'boite', 'Sélectionnez une boîte', O::boites()); ?>
        </div>
      </div>
    </section>

    <!-- Étape 2 -->
    <section data-kdv-step aria-labelledby="kdv-s2" hidden>
      <h2 class="kdv-step__title" id="kdv-s2" data-kdv-heading tabindex="-1">Étape 2 : Quel est l'état de votre véhicule ?</h2>
      <p class="kdv-step__subtitle">Ces informations nous permettent de mieux évaluer votre véhicule.</p>
      <div class="kdv-cols">
        <fieldset class="kdv-field" data-required>
          <legend>État général du véhicule <?php echo $req; ?></legend>
          <?php $radio_cards('etat_general', O::etat_general(), 'kdv-choices--2'); ?>
        </fieldset>
        <fieldset class="kdv-field" data-required>
          <legend>État de l'habitacle <?php echo $req; ?></legend>
          <?php $radio_cards('etat_habitacle', O::etat_habitacle()); ?>
        </fieldset>
        <fieldset class="kdv-field" data-required>
          <legend>Votre véhicule a-t-il déjà été accidenté ? <?php echo $req; ?></legend>
          <?php $radio_cards('accident', O::accident()); ?>
        </fieldset>
        <fieldset class="kdv-field" data-required>
          <legend>Quel est le niveau de suivi d'entretien de votre véhicule ? <?php echo $req; ?></legend>
          <?php $radio_cards('suivi_entretien', O::suivi_entretien()); ?>
        </fieldset>
      </div>
    </section>

    <!-- Étape 3 -->
    <section data-kdv-step aria-labelledby="kdv-s3" hidden>
      <div class="kdv-ill kdv-ill--wheel" aria-hidden="true"></div>
      <h2 class="kdv-step__title" id="kdv-s3" data-kdv-heading tabindex="-1">Étape 3 : Quels sont les équipements de votre véhicule ?</h2>
      <p class="kdv-step__subtitle">Sélectionnez les équipements présents sur votre véhicule. (plusieurs choix possibles)</p>
      <fieldset class="kdv-field">
        <legend class="kdv-sr-only">Équipements</legend>
        <div class="kdv-choices kdv-choices--checks">
          <?php foreach (O::equipements() as $value => [$label, $icon]) : ?>
            <label class="kdv-choice kdv-choice--icon kdv-choice--check"><input class="kdv-choice__input" type="checkbox" name="equipements[]" value="<?php echo esc_attr($value); ?>"><span class="kdv-choice__box"><?php echo O::icon($icon); ?><?php echo esc_html($label); ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="kdv-field kdv-field--spaced" data-max="200">
        <label class="kdv-label" for="kdv-autre">Autre équipement <small>(facultatif)</small></label>
        <input class="kdv-input" id="kdv-autre" name="autre_equipement" maxlength="200" placeholder="Précisez un autre équipement...">
      </div>
    </section>

    <!-- Étape 4 -->
    <section data-kdv-step aria-labelledby="kdv-s4" hidden>
      <h2 class="kdv-step__title" id="kdv-s4" data-kdv-heading tabindex="-1">Étape 4 : Finalisez votre demande d'estimation</h2>
      <p class="kdv-step__subtitle">Indiquez votre projet de vente et vos coordonnées pour que nous puissions vous recontacter.</p>
      <fieldset class="kdv-field" data-required>
        <legend>Quand souhaitez-vous vendre votre véhicule ? <?php echo $req; ?></legend>
        <div class="kdv-choices kdv-choices--icons">
          <?php foreach (O::delais() as $value => [$label, $icon]) : ?>
            <label class="kdv-choice kdv-choice--icon"><input class="kdv-choice__input" type="radio" name="delai_vente" value="<?php echo esc_attr($value); ?>"><span class="kdv-choice__box"><?php echo O::icon($icon); ?><?php echo esc_html($label); ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <h3 class="kdv-section-title">Vos coordonnées</h3>
      <div class="kdv-grid kdv-grid--3">
        <div class="kdv-field" data-required data-min="2" data-max="120">
          <label class="kdv-label" for="kdv-nom">Nom et prénom <?php echo $req; ?></label>
          <input class="kdv-input" id="kdv-nom" name="nom_complet" autocomplete="name" maxlength="120" placeholder="Ex : Dupont Jean">
        </div>
        <div class="kdv-field" data-required data-validate="phone"<?php echo !empty($settings['accept_foreign_phones']) ? ' data-foreign="1"' : ''; ?>>
          <label class="kdv-label" for="kdv-tel">Téléphone <?php echo $req; ?></label>
          <div class="kdv-input-wrap"><?php echo O::icon('phone'); ?><input class="kdv-input" id="kdv-tel" name="telephone" type="tel" autocomplete="tel" placeholder="Ex : 06 12 34 56 78"></div>
        </div>
        <div class="kdv-field" data-validate="email" data-max="190">
          <label class="kdv-label" for="kdv-mail">Email <small>(facultatif)</small></label>
          <div class="kdv-input-wrap"><?php echo O::icon('mail'); ?><input class="kdv-input" id="kdv-mail" name="email" type="email" autocomplete="email" maxlength="190" placeholder="Ex : votre@email.com"></div>
        </div>
      </div>

      <div class="kdv-field" data-required>
        <label class="kdv-consent"><input type="checkbox" name="consentement" value="1"><span>J'accepte que mes données soient utilisées afin d'être recontacté dans le cadre de ma demande d'estimation. <?php echo $req; ?></span></label>
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

    <?php if (!empty($settings["turnstile_site_key"])) : ?>
      <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($settings["turnstile_site_key"]); ?>" data-language="fr"></div>
    <?php endif; ?>

    <div class="kdv-alert" data-kdv-alert role="alert" hidden></div>

    <div class="kdv-actions">
      <button type="button" class="kdv-btn kdv-btn--secondary" data-kdv-prev><?php echo O::icon('arrow-left'); ?>Retour</button>
      <button type="button" class="kdv-btn kdv-btn--primary" data-kdv-next>Continuer <?php echo O::icon('arrow-right'); ?></button>
      <button type="submit" class="kdv-btn kdv-btn--primary kdv-btn--car" data-kdv-submit hidden><img class="kdv-btn__img" src="<?php echo esc_url(KDV_LEADS_URL . 'assets/img/icon-car.png'); ?>" alt="" width="34" height="25"><span data-kdv-submit-label>Demander mon estimation gratuite</span> <?php echo O::icon('arrow-right'); ?></button>
    </div>
    <p class="kdv-reassure" data-kdv-last-only hidden>Gratuit et sans engagement. Notre équipe vous recontacte pour échanger sur votre véhicule.</p>
  </form>

  <?php include __DIR__ . '/estimation-faq.php'; ?>
</div>
