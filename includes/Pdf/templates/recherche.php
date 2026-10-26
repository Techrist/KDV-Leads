<?php
/**
 * PDF "Fiche recherche personnalisée".
 *
 * @var array  $lead
 * @var array  $d       decoded payload
 * @var string $red     brand red
 * @var string $created formatted creation date
 * @var array  $photos
 */

use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

$nr = '<span style="color:#6B6B72">Non renseigné</span>';
$val = static fn ($v) => ($v === null || $v === '' || $v === []) ? $nr : esc_html(is_array($v) ? implode(', ', $v) : (string) $v);
$labels = static function (array $keys, array $map): array {
    return array_values(array_filter(array_map(static function ($k) use ($map) {
        return isset($map[$k]) ? (is_array($map[$k]) ? $map[$k][0] : $map[$k]) : null;
    }, (array) $keys)));
};
$one = static fn (array $map, $k) => isset($map[$k]) ? (is_array($map[$k]) ? $map[$k][0] : $map[$k]) : '';
$row = static fn (string $label, string $html) => '<tr><td class="l">' . esc_html($label) . '</td><td class="v">' . $html . '</td></tr>';
$money = static fn ($n) => $n ? number_format((int) $n, 0, ',', ' ') . ' €' : '';

$years = ($d['annee_min'] ?? null) && ($d['annee_max'] ?? null) ? $d['annee_min'] . ' – ' . $d['annee_max']
    : (($d['annee_min'] ?? null) ? 'À partir de ' . $d['annee_min'] : (($d['annee_max'] ?? null) ? 'Jusqu\'à ' . $d['annee_max'] : ''));
$budget = ($d['budget_min'] ?? null) ? $money($d['budget_min']) . ' à ' . $money($d['budget_max'] ?? 0) : 'Jusqu\'à ' . $money($d['budget_max'] ?? 0);
$phone = (string) $lead['phone'];
$email = (string) ($lead['email'] ?? '');
?>
<style>
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #0A0A0A; }
  h1 { font-size: 17pt; margin: 0; letter-spacing: 0.5pt; }
  h2 { font-size: 11pt; color: <?php echo esc_html($red); ?>; border-bottom: 1.5pt solid <?php echo esc_html($red); ?>; padding-bottom: 2pt; margin: 14pt 0 5pt 0; text-transform: uppercase; }
  table.info { width: 100%; border-collapse: collapse; }
  td.l { width: 34%; padding: 4pt 6pt; background: #F7F7F8; border-bottom: 0.5pt solid #E2E2E5; color: #6B6B72; }
  td.v { padding: 4pt 6pt; border-bottom: 0.5pt solid #E2E2E5; }
  .d { color: #6B6B72; font-size: 8.5pt; }
  .badge { text-align: center; font-weight: bold; font-size: 12pt; padding: 8pt 6pt; margin-top: 8pt; background: #EDEDEF; color: #333; border: 2px solid #C9C9CE; }
  .ref { color: #6B6B72; font-size: 9pt; }
  .phone { font-size: 15pt; font-weight: bold; }
  a { color: #0A0A0A; }
</style>

<table width="100%"><tr>
  <td width="30%" style="font-size:30pt;font-weight:bold;font-style:italic;"><?php
  $logo_file = KDV_LEADS_DIR . 'assets/img/logo-kdv.png';
  if (is_readable($logo_file)) {
      echo '<img src="' . esc_attr($logo_file) . '" style="height:16mm;">';
  } else {
      echo 'KD<span style="color:' . esc_attr($red) . '">V</span><br><span style="font-size:6.5pt;font-style:normal;letter-spacing:0.6pt;">ACHAT - VENTE - REPRISE</span>';
  }
?></td>
  <td width="70%" style="text-align:right;">
    <h1>FICHE RECHERCHE PERSONNALISÉE</h1>
    <div class="ref"><b><?php echo esc_html($lead['reference']); ?></b> &nbsp;·&nbsp; <?php echo esc_html($created); ?></div>
  </td>
</tr></table>

<div class="badge">Budget maximum : <?php echo esc_html($money($d['budget_max'] ?? 0) ?: '—'); ?></div>

<h2>Contact</h2>
<table class="info">
  <?php
  echo $row('Nom et prénom', $val($lead['full_name']));
  echo $row('Téléphone', $phone !== '' ? '<a class="phone" href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>' : $nr);
  echo $row('E-mail', $email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : $nr);
  echo $row('Code postal', $val($lead['postal_code'] ?? ''));
  echo $row('Créneau de rappel', $val($one(O::creneaux(), $d['creneau_rappel'] ?? '')));
  ?>
</table>

<h2>Véhicule recherché</h2>
<table class="info">
  <?php
  echo $row('Marques / modèles', $val($d['marques_modeles'] ?? []));
  echo $row('Versions souhaitées', $val($d['versions'] ?? ''));
  echo $row('Années', $val($years));
  echo $row('Kilométrage maximum', $val(!empty($d['km_max']) ? number_format((int) $d['km_max'], 0, ',', ' ') . ' km' : ''));
  echo $row('Carburant', $val($labels($d['carburant'] ?? [], O::motorisations())));
  echo $row('Boîte de vitesses', $val($one(O::boites(), $d['boite'] ?? '')));
  echo $row('Carrosserie', $val($labels($d['carrosserie'] ?? [], O::carrosseries())));
  echo $row('Couleurs souhaitées', $val($d['couleurs_souhaitees'] ?? ''));
  echo $row('Couleurs à exclure', $val($d['couleurs_exclues'] ?? ''));
  ?>
</table>

<h2>Budget et options</h2>
<table class="info">
  <?php
  echo $row('Budget', $val($budget));
  echo $row('Options indispensables', $val($d['options_indispensables'] ?? []));
  echo $row('Options souhaitables', $val($d['options_souhaitables'] ?? []));
  echo $row('Origine acceptée', $val($labels($d['origine_acceptee'] ?? [], O::origines())));
  echo $row('Flexibilité', $val($one(O::flexibilites(), $d['flexibilite'] ?? '')));
  ?>
</table>

<h2>Calendrier et reprise</h2>
<table class="info">
  <?php
  echo $row('Date d\'achat envisagée', $val($one(O::delais(), $d['date_achat_envisagee'] ?? '')));
  echo $row('Localisation', $val($d['localisation'] ?? ''));
  echo $row('Reprise d\'un véhicule', $val(['oui' => 'Oui', 'non' => 'Non'][$d['reprise'] ?? ''] ?? ''));
  echo $row('Commentaire', !empty($d['commentaire']) ? nl2br(esc_html($d['commentaire'])) : $nr);
  ?>
</table>

<h2>Liens d'annonces</h2>
<?php if (!empty($d['liens_annonces'])) : ?>
  <table class="info">
    <?php foreach ($d['liens_annonces'] as $url) : ?>
      <tr><td class="v" colspan="2"><?php echo esc_html($url); ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php else : ?>
  <p style="color:#6B6B72;">Aucun lien renseigné</p>
<?php endif; ?>

<?php include KDV_LEADS_DIR . 'includes/Pdf/partials/photos.php'; ?>
