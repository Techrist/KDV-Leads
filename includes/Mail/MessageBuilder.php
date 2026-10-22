<?php
namespace KdvLeads\Mail;

use KdvLeads\Config;
use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

/** Builds subject / HTML / text for the estimation e-mails. No sending here. */
final class MessageBuilder
{
    private static function payload(array $lead): array
    {
        return json_decode((string) ($lead['payload'] ?? ''), true) ?: [];
    }

    private static function one_line(string $s): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', wp_strip_all_tags($s)));
    }

    public static function km(?int $km): string
    {
        return $km === null ? '' : number_format($km, 0, ',', ' ');
    }

    public static function delai_label(?string $key): string
    {
        $all = O::delais();
        return isset($all[$key]) ? $all[$key][0] : 'Délai non précisé';
    }

    /** [bg, fg, border] colours shared with the PDF. */
    private static function delai_colors(?string $key): array
    {
        $red = (string) Config::get('brand_red');
        return match ($key) {
            'asap'   => [$red, '#FFFFFF', $red],
            '1_mois' => ['#FFFFFF', $red, $red],
            default  => ['#EDEDEF', '#333333', '#C9C9CE'],
        };
    }

    /** Logo URL used in e-mails: the setting if filled, else the plugin's own file (public once the site is online). */
    public static function logo_url(): string
    {
        $custom = trim((string) Config::get('email_logo_url'));
        return $custom !== '' ? $custom : KDV_LEADS_URL . 'assets/img/logo-kdv.png';
    }

    /**
     * E-mail header: logo on a white band underlined in brand red. If the mail client blocks images,
     * the alt text stays visible in the same bold style, so the header is never empty.
     */
    public static function header_html(): string
    {
        $red = esc_attr((string) Config::get('brand_red'));
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 20px;border-bottom:3px solid ' . $red . ';">'
            . '<tr><td style="padding:0 0 12px;">'
            . '<img src="' . esc_url(self::logo_url()) . '" alt="KDV MOTORS" width="150" height="57" style="display:block;width:150px;height:auto;border:0;outline:none;font:900 22px Arial,Helvetica,sans-serif;font-style:italic;color:#0A0A0A;">'
            . '</td></tr></table>';
    }

    public static function admin_subject(array $lead): string
    {
        $d = self::payload($lead);
        if (($lead['type'] ?? '') === 'recherche') {
            $first = (string) ($d['marques_modeles'][0] ?? 'Véhicule');
            $more = count((array) ($d['marques_modeles'] ?? [])) > 1 ? ' (+' . (count($d['marques_modeles']) - 1) . ')' : '';
            $budget = number_format((int) ($lead['budget_max'] ?? 0), 0, ',', ' ');
            return self::one_line('[KDV RECHERCHE] ' . $first . $more . ' | Budget ' . $budget . ' € | ' . $lead['full_name']);
        }
        $prefix = ($lead['sale_timeframe'] === 'asap' && Config::get('subject_priority_prefix')) ? '🔴 ' : '';
        $parts = [
            trim(($d['marque'] ?? '') . ' ' . ($d['modele'] ?? '')),
            (string) ($lead['vehicle_year'] ?? ''),
            self::km(isset($lead['mileage']) ? (int) $lead['mileage'] : null) . ' km',
            self::delai_label($lead['sale_timeframe'] ?? null),
            (string) $lead['full_name'],
        ];
        return self::one_line($prefix . '[KDV ESTIMATION] ' . implode(' | ', $parts));
    }

    public static function lead_url(array $lead): string
    {
        return admin_url('admin.php?page=kdv-leads&lead=' . (int) $lead['id']);
    }

    private static function source_text(array $lead): string
    {
        $s = json_decode((string) ($lead['source'] ?? ''), true) ?: [];
        $bits = array_filter([$s['utm_source'] ?? '', $s['utm_medium'] ?? '', $s['utm_campaign'] ?? '']);
        $txt = $bits ? implode(' / ', $bits) : 'Direct / non renseignée';
        if (!empty($s['gclid'])) {
            $txt .= ' (Google Ads)';
        }
        if (!empty($s['fbclid'])) {
            $txt .= ' (Meta)';
        }
        return $txt;
    }

    /** @return array<string, array<string,string>> section => label => value */
    public static function sections(array $lead): array
    {
        if (($lead['type'] ?? '') === 'recherche') {
            return self::recherche_sections($lead);
        }
        $d = self::payload($lead);
        $nr = 'Non renseigné';
        $v = static fn ($x) => ($x === null || $x === '') ? $nr : (string) $x;
        $title = static fn (array $map, $k) => isset($map[$k]) ? $map[$k][0] . ' : ' . $map[$k][1] : $nr;
        $simple = static fn (array $map, $k) => isset($map[$k]) ? (string) $map[$k] : $nr;

        $eq = [];
        foreach ((array) ($d['equipements'] ?? []) as $k) {
            $all = O::equipements();
            if (isset($all[$k])) {
                $eq[] = $all[$k][0];
            }
        }
        if (!empty($d['autre_equipement'])) {
            $eq[] = 'Autre : ' . $d['autre_equipement'];
        }

        $created = (new \DateTimeImmutable((string) $lead['created_at'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');

        return [
            'Véhicule' => [
                'Immatriculation' => $v($d['immatriculation'] ?? ''),
                'Marque' => $v($d['marque'] ?? ''),
                'Modèle' => $v($d['modele'] ?? ''),
                'Finition' => $v($d['finition'] ?? ''),
                'Année' => $v($d['annee_mise_en_circulation'] ?? ''),
                'Kilométrage' => isset($d['kilometrage']) ? self::km((int) $d['kilometrage']) . ' km' : $nr,
                'Motorisation' => $simple(O::motorisations(), $d['motorisation'] ?? null),
                'Boîte' => $simple(O::boites(), $d['boite'] ?? null),
            ],
            'État du véhicule' => [
                'État général' => $title(O::etat_general(), $d['etat_general'] ?? null),
                'Habitacle' => $title(O::etat_habitacle(), $d['etat_habitacle'] ?? null),
                'Accident' => $title(O::accident(), $d['accident'] ?? null),
                'Suivi d\'entretien' => $title(O::suivi_entretien(), $d['suivi_entretien'] ?? null),
            ],
            'Équipements' => [
                'Équipements' => $eq ? implode(', ', $eq) : 'Aucun équipement renseigné',
            ],
            'Demande' => [
                'Date' => $created,
                'Référence' => (string) $lead['reference'],
                'Source' => self::source_text($lead),
            ],
        ];
    }

    /** Extra contact lines (search journey collects a postal code and a call-back slot). */
    public static function contact_extra(array $lead): array
    {
        if (($lead['type'] ?? '') !== 'recherche') {
            return [];
        }
        $d = self::payload($lead);
        $slots = O::creneaux();
        return [
            'Code postal' => (string) ($lead['postal_code'] ?? '') ?: 'Non renseigné',
            'Créneau de rappel' => isset($slots[$d['creneau_rappel'] ?? '']) ? $slots[$d['creneau_rappel']] : 'Non renseigné',
        ];
    }

    private static function recherche_sections(array $lead): array
    {
        $d = self::payload($lead);
        $nr = 'Non renseigné';
        $list = static fn ($a) => $a ? implode(', ', (array) $a) : $nr;
        $labels = static function ($keys, array $map): string {
            $out = [];
            foreach ((array) $keys as $k) {
                if (isset($map[$k])) {
                    $out[] = is_array($map[$k]) ? $map[$k][0] : $map[$k];
                }
            }
            return $out ? implode(', ', $out) : 'Non renseigné';
        };
        $one = static fn (array $map, $k) => isset($map[$k]) ? (is_array($map[$k]) ? $map[$k][0] : $map[$k]) : $nr;
        $money = static fn ($n) => $n ? number_format((int) $n, 0, ',', ' ') . ' €' : '';

        if (!empty($d['annee_min']) && !empty($d['annee_max'])) {
            $years = $d['annee_min'] . ' – ' . $d['annee_max'];
        } elseif (!empty($d['annee_min'])) {
            $years = 'À partir de ' . $d['annee_min'];
        } elseif (!empty($d['annee_max'])) {
            $years = 'Jusqu\'à ' . $d['annee_max'];
        } else {
            $years = $nr;
        }
        $budget = !empty($d['budget_min']) ? $money($d['budget_min']) . ' à ' . $money($d['budget_max'] ?? 0) : 'Jusqu\'à ' . $money($d['budget_max'] ?? 0);
        $created = (new \DateTimeImmutable((string) $lead['created_at'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');
        $photos = \KdvLeads\Database\FileRepository::for_lead((int) $lead['id']);

        return [
            'Véhicule recherché' => [
                'Marques / modèles' => $list($d['marques_modeles'] ?? []),
                'Versions' => ($d['versions'] ?? '') ?: $nr,
                'Années' => $years,
                'Kilométrage maximum' => !empty($d['km_max']) ? self::km((int) $d['km_max']) . ' km' : $nr,
                'Carburant' => $labels($d['carburant'] ?? [], O::motorisations()),
                'Boîte' => $one(O::boites(), $d['boite'] ?? ''),
                'Carrosserie' => $labels($d['carrosserie'] ?? [], O::carrosseries()),
                'Couleurs souhaitées' => ($d['couleurs_souhaitees'] ?? '') ?: $nr,
                'Couleurs à exclure' => ($d['couleurs_exclues'] ?? '') ?: $nr,
            ],
            'Budget et options' => [
                'Budget' => $budget,
                'Options indispensables' => $list($d['options_indispensables'] ?? []),
                'Options souhaitables' => $list($d['options_souhaitables'] ?? []),
                'Origine acceptée' => $labels($d['origine_acceptee'] ?? [], O::origines()),
                'Flexibilité' => $one(O::flexibilites(), $d['flexibilite'] ?? ''),
            ],
            'Précisions' => [
                'Achat envisagé' => $one(O::delais(), $d['date_achat_envisagee'] ?? ''),
                'Localisation' => ($d['localisation'] ?? '') ?: $nr,
                'Reprise' => ['oui' => 'Oui', 'non' => 'Non'][$d['reprise'] ?? ''] ?? $nr,
                'Commentaire' => ($d['commentaire'] ?? '') ?: $nr,
                'Liens d\'annonces' => $list($d['liens_annonces'] ?? []),
                'Photos' => $photos ? count($photos) . ' photo(s) (voir la fiche)' : 'Aucune',
            ],
            'Demande' => [
                'Date' => $created,
                'Référence' => (string) $lead['reference'],
                'Source' => self::source_text($lead),
            ],
        ];
    }

    public static function admin_html(array $lead, bool $pdf_attached): string
    {
        $e = 'esc_html';
        [$bg, $fg, $border] = self::delai_colors($lead['sale_timeframe'] ?? null);
        $red = (string) Config::get('brand_red');
        $phone = (string) $lead['phone'];
        $email = (string) ($lead['email'] ?? '');

        $h = '<div style="font-family:Arial,Helvetica,sans-serif;color:#0A0A0A;max-width:640px;margin:0 auto;">' . self::header_html();
        if (($lead['type'] ?? '') === 'recherche') {
            $budget = number_format((int) ($lead['budget_max'] ?? 0), 0, ',', ' ');
            $h .= '<div style="background:#EDEDEF;color:#333;border:2px solid #C9C9CE;padding:14px;text-align:center;font-size:20px;font-weight:bold;">NOUVELLE RECHERCHE · BUDGET MAX ' . $e($budget) . ' €</div>';
        } else {
            $h .= '<div style="background:' . $e($bg) . ';color:' . $e($fg) . ';border:2px solid ' . $e($border) . ';padding:14px;text-align:center;font-size:20px;font-weight:bold;">'
                . ($lead['sale_timeframe'] === 'asap' ? 'PRIORITÉ : ' : '') . $e(mb_strtoupper(self::delai_label($lead['sale_timeframe'] ?? null))) . '</div>';
        }

        $h .= '<h2 style="font-size:15px;color:' . $e($red) . ';border-bottom:2px solid ' . $e($red) . ';padding-bottom:4px;margin:22px 0 8px;">COORDONNÉES</h2>';
        $h .= '<p style="margin:4px 0;font-size:16px;"><b>' . $e($lead['full_name']) . '</b></p>';
        $h .= '<p style="margin:4px 0;"><a href="tel:' . esc_attr($phone) . '" style="font-size:24px;font-weight:bold;color:#0A0A0A;text-decoration:none;">' . $e($phone) . '</a></p>';
        $h .= '<p style="margin:4px 0;">' . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '" style="color:#0A0A0A;">' . $e($email) . '</a>' : 'E-mail non renseigné') . '</p>';
        foreach (self::contact_extra($lead) as $k => $v) {
            $h .= '<p style="margin:4px 0;">' . $e($k) . ' : ' . $e($v) . '</p>';
        }

        foreach (self::sections($lead) as $title => $rows) {
            $h .= '<h2 style="font-size:15px;color:' . $e($red) . ';border-bottom:2px solid ' . $e($red) . ';padding-bottom:4px;margin:22px 0 8px;">' . $e(mb_strtoupper($title)) . '</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
            foreach ($rows as $label => $value) {
                $h .= '<tr><td style="width:36%;padding:5px 8px;background:#F7F7F8;color:#6B6B72;border-bottom:1px solid #E2E2E5;">' . $e($label)
                    . '</td><td style="padding:5px 8px;border-bottom:1px solid #E2E2E5;">' . $e($value) . '</td></tr>';
            }
            $h .= '</table>';
        }

        $h .= '<p style="margin:24px 0 8px;"><a href="' . esc_url(self::lead_url($lead)) . '" style="display:inline-block;background:' . $e($red) . ';color:#fff;padding:12px 20px;text-decoration:none;font-weight:bold;border-radius:6px;">Ouvrir la fiche dans KDV (connexion requise)</a></p>';
        $h .= '<p style="color:#6B6B72;font-size:12px;">' . ($pdf_attached ? 'La fiche PDF est jointe à ce message.' : 'La fiche PDF n\'est pas jointe (indisponible ou trop volumineuse) : toutes les informations figurent ci-dessus.') . '</p>';
        return $h . '</div>';
    }

    public static function admin_text(array $lead): string
    {
        $t = ($lead['type'] ?? '') === 'recherche' ? "NOUVELLE RECHERCHE PERSONNALISÉE\n\n" : strtoupper(self::delai_label($lead['sale_timeframe'] ?? null)) . "\n\n";
        $t .= "COORDONNÉES\n" . $lead['full_name'] . "\nTéléphone : " . $lead['phone'] . "\nE-mail : " . (($lead['email'] ?? '') ?: 'non renseigné') . "\n";
        foreach (self::contact_extra($lead) as $k => $v) {
            $t .= $k . ' : ' . $v . "\n";
        }
        foreach (self::sections($lead) as $title => $rows) {
            $t .= "\n" . mb_strtoupper($title) . "\n";
            foreach ($rows as $label => $value) {
                $t .= $label . ' : ' . $value . "\n";
            }
        }
        return $t . "\nFiche : " . self::lead_url($lead) . "\n";
    }

    private static function client_sentence(array $lead): string
    {
        return ($lead['type'] ?? '') === 'recherche'
            ? 'Nous avons bien reçu votre recherche. Nous allons étudier vos critères et vous recontacter.'
            : 'Nous avons bien reçu votre demande concernant votre véhicule. Nous allons étudier les informations transmises et vous recontacter.';
    }

    public static function client_subject(array $lead): string
    {
        $what = ($lead['type'] ?? '') === 'recherche' ? 'recherche personnalisée' : 'demande d\'estimation';
        return 'Votre ' . $what . ' KDV Motors, ' . $lead['reference'];
    }

    /** "What happens next" for the customer: order of events only, no promised delay. */
    private static function client_steps(array $lead): array
    {
        return ($lead['type'] ?? '') === 'recherche'
            ? [['Recherche reçue', 'Vos critères sont bien enregistrés.'], ['Un conseiller vous rappelle', 'Il revient vers vous pour préciser votre projet.'], ['Recherche personnalisée', 'KDV Motors étudie les véhicules qui correspondent.']]
            : [['Demande reçue', 'Les informations de votre véhicule sont bien enregistrées.'], ['Un conseiller vous rappelle', 'Il échange avec vous sur votre projet de vente.'], ['Rendez-vous', 'Nous organisons ensemble la suite, sans engagement.']];
    }

    /**
     * What the customer told us, echoed back so they can check it. Never contains a price or an estimate.
     *
     * @return array<string,string> label => value
     */
    private static function client_recap(array $lead): array
    {
        $d = self::payload($lead);
        $rows = [];
        if (($lead['type'] ?? '') === 'recherche') {
            if (!empty($d['marques_modeles'])) {
                $rows['Véhicule recherché'] = implode(', ', (array) $d['marques_modeles']);
            }
            if (!empty($d['budget_max'])) {
                $rows['Budget maximum'] = number_format((int) $d['budget_max'], 0, ',', ' ') . ' €';
            }
        } else {
            $vehicle = trim(($d['marque'] ?? '') . ' ' . ($d['modele'] ?? '') . ' ' . ($d['finition'] ?? ''));
            if ($vehicle !== '') {
                $rows['Véhicule'] = $vehicle;
            }
            if (!empty($lead['vehicle_year'])) {
                $rows['Mise en circulation'] = (string) $lead['vehicle_year'];
            }
            if (isset($lead['mileage']) && $lead['mileage'] !== null) {
                $rows['Kilométrage'] = self::km((int) $lead['mileage']) . ' km';
            }
            $delais = O::delais();
            if (isset($delais[$lead['sale_timeframe'] ?? ''])) {
                $rows['Votre projet de vente'] = $delais[$lead['sale_timeframe']][0];
            }
        }
        if (!empty($lead['phone'])) {
            $rows['Nous vous rappellerons au'] = (string) $lead['phone'];
        }
        return $rows;
    }

    private static function client_title(array $lead): string
    {
        return ($lead['type'] ?? '') === 'recherche' ? 'Votre recherche est bien transmise' : 'Votre demande est bien enregistrée';
    }

    public static function client_text(array $lead): string
    {
        $t = self::client_title($lead) . "\n\nBonjour,\n\n" . self::client_sentence($lead) . "\n\n";
        $t .= 'Référence de votre demande : ' . $lead['reference'] . "\n";
        if ($recap = self::client_recap($lead)) {
            $t .= "\nRécapitulatif\n";
            foreach ($recap as $label => $value) {
                $t .= '- ' . $label . ' : ' . $value . "\n";
            }
        }
        $t .= "\nEt maintenant ?\n";
        foreach (self::client_steps($lead) as $i => [$label, $text]) {
            $t .= ($i + 1) . '. ' . $label . ' : ' . $text . "\n";
        }
        $t .= "\nUne question ? Répondez simplement à cet e-mail.\n\nL'équipe KDV MOTORS\nAchat, vente, reprise\n";
        $t .= "\nGratuit et sans engagement. Vous recevez ce message car vous avez fait une demande sur " . wp_parse_url(home_url(), PHP_URL_HOST) . ".\n";
        return $t;
    }

    /**
     * Acknowledgement e-mail: table layout with inline styles (works in Gmail, Outlook, Apple Mail).
     */
    public static function client_html(array $lead): string
    {
        $e = 'esc_html';
        $red = esc_attr((string) Config::get('brand_red'));
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $sentence = self::client_sentence($lead);

        $h = '<div style="background:#F4F4F5;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#0A0A0A;">';
        // Inbox preview text (hidden in the message itself).
        $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;">Votre demande est bien enregistrée. Un conseiller vous recontacte prochainement.&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:12px;"><tr><td style="padding:28px 28px 8px;">';
        $h .= self::header_html();
        $h .= '<h1 style="margin:8px 0 12px;font-size:24px;line-height:1.25;color:#0A0A0A;">' . $e(self::client_title($lead)) . '</h1>';
        $h .= '<p style="margin:0 0 6px;font-size:16px;line-height:1.55;">Bonjour,</p>';
        $h .= '<p style="margin:0 0 20px;font-size:16px;line-height:1.55;">' . $e($sentence) . '</p>';

        // Reference.
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;background:#FDF1F1;border-left:4px solid ' . $red . ';border-radius:6px;"><tr><td style="padding:14px 16px;">'
            . '<div style="font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:#6B6B72;">Référence de votre demande</div>'
            . '<div style="font-size:20px;font-weight:bold;letter-spacing:.5px;color:#0A0A0A;">' . $e($lead['reference']) . '</div></td></tr></table>';

        // Recap.
        if ($recap = self::client_recap($lead)) {
            $h .= '<div style="margin:0 0 6px;font-size:13px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase;color:' . $red . ';">Récapitulatif</div>';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-collapse:collapse;font-size:14.5px;">';
            foreach ($recap as $label => $value) {
                $h .= '<tr><td style="width:42%;padding:8px 10px;background:#F7F7F8;border-bottom:1px solid #E2E2E5;color:#6B6B72;">' . $e($label)
                    . '</td><td style="padding:8px 10px;border-bottom:1px solid #E2E2E5;">' . $e($value) . '</td></tr>';
            }
            $h .= '</table>';
        }

        // Next steps.
        $h .= '<div style="margin:0 0 10px;font-size:13px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase;color:' . $red . ';">Et maintenant ?</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">';
        foreach (self::client_steps($lead) as $i => [$label, $text]) {
            $done = $i === 0;
            $dot = $done
                ? 'background:' . $red . ';color:#FFFFFF;border:2px solid ' . $red . ';'
                : 'background:#FFFFFF;color:#6B6B72;border:2px solid #C9C9CE;';
            $h .= '<tr><td width="40" valign="top" style="padding:0 12px 14px 0;"><div style="width:30px;height:30px;line-height:26px;border-radius:15px;text-align:center;font-weight:bold;font-size:14px;' . $dot . '">'
                . ($done ? '&#10003;' : (int) ($i + 1)) . '</div></td>'
                . '<td valign="top" style="padding:3px 0 14px;"><div style="font-size:15.5px;font-weight:bold;">' . $e($label) . '</div>'
                . '<div style="font-size:14px;color:#6B6B72;">' . $e($text) . '</div></td></tr>';
        }
        $h .= '</table>';

        $h .= '<p style="margin:0 0 6px;font-size:15px;line-height:1.55;">Une question ? Répondez simplement à cet e-mail.</p>';
        $h .= '<p style="margin:0 0 10px;font-size:15px;line-height:1.55;font-weight:bold;">L\'équipe KDV MOTORS</p>';
        $h .= '<img src="' . esc_url(self::logo_url()) . '" alt="KDV MOTORS" width="120" height="46" style="display:block;width:120px;height:auto;margin:0 0 24px;border:0;outline:none;font:900 18px Arial,Helvetica,sans-serif;font-style:italic;color:#0A0A0A;">';
        $h .= '</td></tr>';

        // Footer band.
        $h .= '<tr><td style="padding:16px 28px 22px;border-top:1px solid #E2E2E5;font-size:12.5px;line-height:1.5;color:#6B6B72;">'
            . '<strong style="color:#0A0A0A;">KDV Motors</strong> &middot; Achat, vente, reprise<br>'
            . 'Gratuit et sans engagement. Vous recevez ce message car vous avez fait une demande sur ' . $e($host) . '.'
            . '</td></tr></table></div>';

        return $h;
    }
}
