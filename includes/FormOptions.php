<?php
namespace KdvLeads;

defined('ABSPATH') || exit;

/**
 * Single source of truth for the estimation form choices: used by the form view,
 * the server validator (phase 3), the PDF (phase 4), e-mails and admin.
 * Client texts are verbatim from the 19/10/2026 report.
 */
final class FormOptions
{
    public static function motorisations(): array
    {
        return ['essence' => 'Essence', 'diesel' => 'Diesel', 'hybride' => 'Hybride', 'hybride_rechargeable' => 'Hybride rechargeable', 'electrique' => 'Électrique', 'autre' => 'Autre'];
    }

    public static function boites(): array
    {
        return ['manuelle' => 'Manuelle', 'automatique' => 'Automatique'];
    }

    /** value => [title, description] */
    public static function etat_general(): array
    {
        return [
            'excellent' => ['Excellent', 'Véhicule en très bon état, proche du neuf, aucune réparation nécessaire.'],
            'bon'       => ['Bon', 'Véhicule bien entretenu, présentant une usure normale liée à son âge et à son kilométrage.'],
            'correct'   => ['Correct', 'Véhicule présentant des signes d\'usure visibles et certaines réparations à prévoir.'],
            'a_reparer' => ['À réparer', 'Véhicule présentant des dommages ou des réparations importantes à effectuer.'],
        ];
    }

    public static function etat_habitacle(): array
    {
        return [
            'propre' => ['Propre', 'Intérieur bien entretenu, sans défaut notable.'],
            'use'    => ['Usé', 'Intérieur présentant des traces d\'usure, des taches ou des dommages visibles.'],
        ];
    }

    public static function accident(): array
    {
        return [
            'aucun'  => ['Aucun', 'Véhicule jamais accidenté.'],
            'mineur' => ['Mineur', 'Accident léger ayant nécessité des réparations sans impact sur la structure du véhicule.'],
            'majeur' => ['Majeur', 'Accident important ayant nécessité des réparations structurelles.'],
        ];
    }

    public static function suivi_entretien(): array
    {
        return [
            'complet' => ['Suivi complet', 'Entretiens réalisés régulièrement, avec factures et/ou carnet d\'entretien disponibles.'],
            'partiel' => ['Suivi partiel', 'Certains entretiens ont été réalisés, mais les justificatifs ou l\'historique sont incomplets.'],
            'aucun'   => ['Aucun suivi', 'Aucun historique d\'entretien ou justificatif disponible.'],
        ];
    }

    /** value => [label, icon id] */
    public static function equipements(): array
    {
        return [
            'gps'           => ['GPS intégré', 'pin'],
            'clim_auto'     => ['Climatisation automatique', 'snow'],
            'toit_ouvrant'  => ['Toit ouvrant / panoramique', 'sunroof'],
            'cuir'          => ['Intérieur en cuir', 'seat'],
            'camera_recul'  => ['Caméra de recul', 'camera'],
            'audio_premium' => ['Système audio premium', 'speaker'],
        ];
    }

    /** value => [label, icon id, priority rank (1 = most urgent)] */
    public static function delais(): array
    {
        return [
            'asap'      => ['Dès que possible', 'bolt', 1],
            '1_mois'    => ['Dans le mois', 'calendar', 2],
            '3_mois'    => ['Dans les 3 mois', 'calendar', 3],
            'renseigne' => ['Je me renseigne simplement', 'info', 4],
        ];
    }

    /* ---------- Parcours recherche personnalisée ---------- */

    public static function carrosseries(): array
    {
        return ['citadine' => 'Citadine', 'berline' => 'Berline', 'break' => 'Break', 'suv' => 'SUV / 4x4', 'monospace' => 'Monospace',
            'coupe' => 'Coupé', 'cabriolet' => 'Cabriolet', 'utilitaire' => 'Utilitaire'];
    }

    public static function origines(): array
    {
        return ['france' => 'France', 'europe' => 'Europe', 'import' => 'Import (hors Europe)'];
    }

    public static function flexibilites(): array
    {
        return ['stricte' => 'Stricte', 'moderee' => 'Modérée', 'flexible' => 'Flexible'];
    }

    public static function creneaux(): array
    {
        return ['matin' => 'Matin', 'midi' => 'Midi', 'apres_midi' => 'Après-midi', 'soir' => 'Soir', 'indifferent' => 'Indifférent'];
    }

    public static function boites_recherche(): array
    {
        return ['' => 'Indifférent'] + self::boites();
    }

    public static function option_suggestions(): array
    {
        return ['CarPlay / Android Auto', 'Sièges chauffants', 'Toit ouvrant', 'Échappement sport', 'Caméra de recul', 'GPS', 'Intérieur cuir', 'Attelage', 'Régulateur adaptatif', 'Jantes alliage'];
    }

    public static function finitions(): array
    {
        return ['GT Line', 'Allure', 'Business', 'AMG Line', 'M Sport', 'S Line', 'Sport', 'Confort', 'Intens', 'Titanium', 'Style', 'Executive'];
    }

    /** Descending list of registration years (current year → 1970). */
    public static function years(): array
    {
        return range((int) wp_date('Y'), 1970);
    }

    /** @return array<string, string[]> brand => models */
    public static function vehicles(): array
    {
        $file = KDV_LEADS_DIR . 'assets/data/vehicles.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }

    public static function icon(string $id): string
    {
        return '<svg class="kdv-icon" aria-hidden="true" focusable="false"><use href="#kdv-i-' . esc_attr($id) . '"/></svg>';
    }
}
