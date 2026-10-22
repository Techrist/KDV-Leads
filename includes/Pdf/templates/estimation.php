<?php
/**
 * PDF "Fiche de demande d'estimation" (mPDF HTML subset: tables + inline CSS).
 *
 * @var array  $lead     lead row
 * @var array  $d        decoded payload
 * @var string $red      brand red
 * @var string $created  formatted creation date (Europe/Paris)
 * @var array  $photos   [] or [['path'=>..., 'caption'=>...]] (option §8)
 */

use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

$nr = '<span style="color:#6B6B72">Non renseigné</span>';
$val = static fn ($v) => ($v === null || $v === '' || $v === 0) ? $nr : esc_html((string) $v);
$opt = static function (array $map, ?string $key): string {
    return isset($map[$key]) ? (string) (is_array($map[$key]) ? $map[$key][0] : $map[$key]) : '';
};

$delai = (string) ($lead['sale_timeframe'] ?? '');
$badges = [
    'asap'      => ['PRIORITÉ : VENTE DÈS QUE POSSIBLE', "background:$red;color:#fff;border:2px solid $red;"],
    '1_mois'    => ['PRIORITÉ : VENTE DANS LE MOIS', "background:#fff;color:$red;border:2px solid $red;"],
    '3_mois'    => ['Vente dans les 3 mois', 'background:#EDEDEF;color:#333;border:2px solid #C9C9CE;'],
    'renseigne' => ['Je me renseigne simplement', 'background:#EDEDEF;color:#333;border:2px solid #C9C9CE;'],
];
[$badge_text, $badge_css] = $badges[$delai] ?? ['Délai non précisé', 'background:#EDEDEF;color:#333;border:2px solid #C9C9CE;'];

$row = static function (string $label, string $html): string {
    return '<tr><td class="l">' . esc_html($label) . '</td><td class="v">' . $html . '</td></tr>';
};
$choice = static function (array $map, ?string $key) use ($nr): string {
    if (!isset($map[$key])) {
        return $nr;
    }
    [$title, $desc] = $map[$key];
    return '<b>' . esc_html($title) . '</b><br><span class="d">' . esc_html($desc) . '</span>';
};

$phone = (string) ($lead['phone'] ?? '');
$email = (string) ($lead['email'] ?? '');
$eq_labels = [];
foreach ((array) ($d['equipements'] ?? []) as $k) {
    $eq = O::equipements();
    if (isset($eq[$k])) {
        $eq_labels[] = $eq[$k][0];
    }
}
?>
<style>
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #0A0A0A; }
  h1 { font-size: 17pt; margin: 0; letter-spacing: 0.5pt; }
  h2 { font-size: 11pt; color: <?php echo esc_html($red); ?>; border-bottom: 1.5pt solid <?php echo esc_html($red); ?>; padding-bottom: 2pt; margin: 14pt 0 5pt 0; text-transform: uppercase; }
  table.info { width: 100%; border-collapse: collapse; }
  td.l { width: 34%; padding: 4pt 6pt; background: #F7F7F8; border-bottom: 0.5pt solid #E2E2E5; color: #6B6B72; }
  td.v { padding: 4pt 6pt; border-bottom: 0.5pt solid #E2E2E5; }
  .d { color: #6B6B72; font-size: 8.5pt; }
  .badge { text-align: center; font-weight: bold; font-size: 13pt; padding: 9pt 6pt; margin-top: 8pt; <?php echo esc_html($badge_css); ?> }
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
    <h1>FICHE DE DEMANDE D'ESTIMATION</h1>
    <div class="ref"><b><?php echo esc_html($lead['reference']); ?></b> &nbsp;·&nbsp; <?php echo esc_html($created); ?></div>
  </td>
</tr></table>

<div class="badge"><?php echo esc_html($badge_text); ?></div>

<h2>Contact</h2>
<table class="info">
  <?php
  echo $row('Nom et prénom', $val($lead['full_name']));
  echo $row('Téléphone', $phone !== '' ? '<a class="phone" href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>' : $nr);
  echo $row('E-mail', $email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : $nr);
  ?>
</table>

<h2>Véhicule</h2>
<table class="info">
  <?php
  echo $row('Immatriculation', $val($d['immatriculation'] ?? ''));
  echo $row('Marque', $val($d['marque'] ?? ''));
  echo $row('Modèle', $val($d['modele'] ?? ''));
  echo $row('Finition / version', $val($d['finition'] ?? ''));
  echo $row('Mise en circulation', $val($d['annee_mise_en_circulation'] ?? ''));
  $km = isset($d['kilometrage']) && $d['kilometrage'] !== '' ? number_format((int) $d['kilometrage'], 0, ',', ' ') . ' km' : '';
  echo $row('Kilométrage', $val($km));
  echo $row('Motorisation', $val($opt(O::motorisations(), $d['motorisation'] ?? '')));
  echo $row('Boîte de vitesses', $val($opt(O::boites(), $d['boite'] ?? '')));
  ?>
</table>

<h2>État et historique</h2>
<table class="info">
  <?php
  echo $row('État général', $choice(O::etat_general(), $d['etat_general'] ?? null));
  echo $row('État de l\'habitacle', $choice(O::etat_habitacle(), $d['etat_habitacle'] ?? null));
  echo $row('Accident', $choice(O::accident(), $d['accident'] ?? null));
  echo $row('Suivi d\'entretien', $choice(O::suivi_entretien(), $d['suivi_entretien'] ?? null));
  ?>
</table>

<h2>Équipements</h2>
<?php if ($eq_labels || !empty($d['autre_equipement'])) : ?>
  <table class="info">
    <?php
    foreach ($eq_labels as $l) {
        echo '<tr><td class="v" colspan="2">&#10003; ' . esc_html($l) . '</td></tr>';
    }
    if (!empty($d['autre_equipement'])) {
        echo '<tr><td class="v" colspan="2">&#10003; Autre : ' . esc_html($d['autre_equipement']) . '</td></tr>';
    }
    ?>
  </table>
<?php else : ?>
  <p style="color:#6B6B72;">Aucun équipement renseigné</p>
<?php endif; ?>

<?php include KDV_LEADS_DIR . 'includes/Pdf/partials/photos.php'; ?>
