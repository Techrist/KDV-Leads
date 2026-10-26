<?php
namespace KdvLeads\Admin;

use KdvLeads\Database\EmailQueueRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Database\Schema;
use KdvLeads\LeadStatuses;
use KdvLeads\Mail\MessageBuilder;

defined('ABSPATH') || exit;

final class LeadDetailPage
{
    private const MESSAGES = [
        'saved' => 'Dossier mis à jour.', 'note' => 'Note ajoutée.', 'pdf_ok' => 'PDF régénéré.',
        'pdf_fail' => 'Échec de la génération du PDF (voir le journal).', 'anonymized' => 'Dossier anonymisé : les données personnelles ont été effacées.', 'resent' => 'E-mail remis en file d\'envoi.',
    ];

    private const EVENTS = [
        'created' => 'Dossier créé', 'status_changed' => 'Statut modifié', 'callback_set' => 'Date de rappel modifiée',
        'note_added' => 'Note ajoutée', 'pdf_generated' => 'PDF généré', 'pdf_failed' => 'Échec du PDF',
        'pdf_downloaded' => 'PDF téléchargé', 'email_sent' => 'E-mail envoyé', 'email_failed' => 'Échec d\'envoi d\'e-mail',
        'email_resent' => 'E-mail renvoyé',
    ];

    private static function fmt(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
    }

    private static function post_form(string $action, int $id, string $nonce_prefix, string $inner, array $extra = []): string
    {
        $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        $h .= '<input type="hidden" name="action" value="kdv_' . esc_attr($action) . '"><input type="hidden" name="lead" value="' . $id . '">';
        foreach ($extra as $k => $v) {
            $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        }
        return $h . wp_nonce_field($nonce_prefix . $id, '_wpnonce', true, false) . $inner . '</form>';
    }

    public static function render(int $id): void
    {
        $lead = LeadRepository::find($id);
        $list = admin_url('admin.php?page=' . (($lead['type'] ?? '') === 'recherche' ? 'kdv-recherches' : 'kdv-leads'));
        if (!$lead) {
            echo '<div class="wrap"><h1>Dossier introuvable</h1><p><a href="' . esc_url($list) . '">← Retour à la liste</a></p></div>';
            return;
        }
        $type = $lead['type'];
        $callback_local = '';
        if ($lead['next_callback_at']) {
            $callback_local = (new \DateTimeImmutable($lead['next_callback_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i');
        }

        Chrome::open(
            $lead['reference'],
            $type === 'recherche' ? 'Recherche personnalisée' : 'Demande d\'estimation',
            '<a class="button" href="' . esc_url($list) . '">← Retour à la liste</a>'
        );

        // Header card: who to call, how urgent, where it stands.
        $initial = $lead['full_name'] === 'Anonymisé' ? '—' : mb_strtoupper(mb_substr(trim((string) $lead['full_name']), 0, 1));
        echo '<section class="kdv-lead"><span class="kdv-lead__avatar" aria-hidden="true">' . esc_html($initial) . '</span><div class="kdv-lead__main">';
        echo '<h2 class="kdv-lead__name">' . esc_html($lead['full_name']) . '</h2><p class="kdv-lead__meta">';
        echo $type === 'estimation' ? LeadListTable::delai_badge((string) $lead['sale_timeframe']) : '';
        echo '<span class="kdv-badge kdv-badge--status' . (in_array($lead['status'], LeadStatuses::closed(), true) ? ' kdv-badge--closed' : '') . '">' . esc_html(LeadStatuses::label($type, $lead['status'])) . '</span>';
        echo '<span>' . esc_html($lead['vehicle_label']) . '</span><span>Reçue le ' . esc_html(self::fmt($lead['created_at'])) . '</span></p></div>';
        echo '<div class="kdv-lead__contact">';
        if ($lead['phone'] !== '') {
            echo '<a class="kdv-lead__call" href="tel:' . esc_attr($lead['phone']) . '">☎ ' . esc_html($lead['phone']) . '</a>';
        }
        if (!empty($lead['email'])) {
            echo '<a class="button" href="mailto:' . esc_attr($lead['email']) . '">✉ Écrire</a>';
        }
        echo '</div></section>';

        $msg = isset($_GET['kdv_msg']) ? sanitize_key(wp_unslash($_GET['kdv_msg'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (isset(self::MESSAGES[$msg])) {
            echo '<div class="notice ' . ($msg === 'pdf_fail' ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html(self::MESSAGES[$msg]) . '</p></div>';
        }

        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">';

        // ---- Left: the answers ----
        echo '<div style="flex:2 1 520px;min-width:0">';
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Coordonnées</h2></div><div class="inside"><table class="form-table"><tbody>';
        self::row('Nom et prénom', esc_html($lead['full_name']));
        self::row('Téléphone', '<a style="font-size:18px;font-weight:600" href="tel:' . esc_attr($lead['phone']) . '">' . esc_html($lead['phone']) . '</a>');
        self::row('E-mail', $lead['email'] ? '<a href="mailto:' . esc_attr($lead['email']) . '">' . esc_html($lead['email']) . '</a>' : 'Non renseigné');
        echo '</tbody></table></div></div>';

        foreach (MessageBuilder::sections($lead) as $title => $rows) {
            if ($title === 'Demande') {
                continue;
            }
            echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html($title) . '</h2></div><div class="inside"><table class="form-table"><tbody>';
            foreach ($rows as $label => $value) {
                self::row($label, nl2br(esc_html($value)));
            }
            echo '</tbody></table></div></div>';
        }

        $photos = \KdvLeads\Database\FileRepository::for_lead($id);
        if ($photos) {
            echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Photos (' . count($photos) . ')</h2></div><div class="inside" style="display:flex;gap:10px;flex-wrap:wrap">';
            foreach ($photos as $p) {
                $fid = (int) $p['id'];
                $thumb = wp_nonce_url(admin_url('admin-post.php?action=kdv_image&file=' . $fid . '&thumb=1'), 'kdv_image_' . $fid);
                $full = wp_nonce_url(admin_url('admin-post.php?action=kdv_image&file=' . $fid), 'kdv_image_' . $fid);
                echo '<a href="' . esc_url($full) . '"><img src="' . esc_url($thumb) . '" alt="Photo ' . (int) ($p['position'] + 1) . '" style="width:160px;height:120px;object-fit:cover;border:1px solid #c3c4c7"></a>';
            }
            echo '</div></div>';
        }

        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Demande</h2></div><div class="inside"><table class="form-table"><tbody>';
        self::row('Reçue le', esc_html(self::fmt($lead['created_at'])));
        $demande = MessageBuilder::sections($lead)['Demande'] ?? [];
        self::row('Source', esc_html($demande['Source'] ?? '—'));
        self::row('Consentement au recontact', $lead['data_consent'] ? 'Oui (texte v' . esc_html($lead['consent_text_version']) . ')' : 'Non');
        self::row('Consentement marketing', $lead['marketing_consent'] ? 'Oui' : 'Non');
        echo '</tbody></table></div></div>';
        echo '</div>';

        // ---- Right: management ----
        echo '<div style="flex:1 1 320px;min-width:280px">';

        $opts = '';
        foreach (LeadStatuses::for_type($type) as $k => $label) {
            $opts .= '<option value="' . esc_attr($k) . '"' . selected($lead['status'], $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Suivi</h2></div><div class="inside">';
        echo self::post_form('lead_update', $id, 'kdv_lead_',
            '<p><label><strong>Statut</strong><br><select name="status" style="width:100%">' . $opts . '</select></label></p>'
            . '<p><label><strong>Rappel prévu</strong><br><input type="datetime-local" name="next_callback_at" value="' . esc_attr($callback_local) . '" style="width:100%"></label></p>'
            . get_submit_button('Enregistrer', 'primary', 'submit', false));
        echo '</div></div>';

        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Fiche PDF</h2></div><div class="inside">';
        if ($lead['pdf_status'] === 'ok' && $lead['pdf_path']) {
            $dl = wp_nonce_url(admin_url('admin-post.php?action=kdv_download&lead=' . $id), 'kdv_download_' . $id);
            echo '<p><a class="button button-primary" href="' . esc_url($dl) . '">Télécharger le PDF</a> ';
        } else {
            echo '<p>PDF : <strong>' . esc_html($lead['pdf_status']) . '</strong> ';
        }
        echo self::post_form('regen_pdf', $id, 'kdv_regen_', get_submit_button('Régénérer', 'secondary', 'submit', false)) . '</p></div></div>';

        // Notes
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Notes internes</h2></div><div class="inside">';
        echo self::post_form('lead_note', $id, 'kdv_note_', '<p><textarea name="note" rows="3" style="width:100%" placeholder="Ajouter une note…"></textarea></p>' . get_submit_button('Ajouter la note', 'secondary', 'submit', false));
        if (!empty($lead['internal_notes'])) {
            echo '<div style="margin-top:12px;max-height:260px;overflow:auto;border-top:1px solid #dcdcde;padding-top:8px">' . nl2br(esc_html($lead['internal_notes'])) . '</div>';
        }
        echo '</div></div>';

        // E-mails
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">E-mails</h2></div><div class="inside">';
        global $wpdb;
        $q = Schema::table('email_queue');
        $mails = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$q` WHERE lead_id = %d ORDER BY id DESC", $id), ARRAY_A); // phpcs:ignore WordPress.DB
        $kinds = ['admin_notification' => 'Notification KDV', 'client_ack' => 'Accusé client', 'admin_alert' => 'Alerte technique'];
        if (!$mails) {
            echo '<p>Aucun e-mail.</p>';
        }
        foreach ($mails as $m) {
            $state = ['sent' => '✅ envoyé ' . self::fmt($m['sent_at']), 'pending' => '⏳ en attente', 'failed' => '⚠️ échec, nouvelle tentative ' . self::fmt($m['next_attempt_at']), 'abandoned' => '❌ abandonné'][$m['status']] ?? $m['status'];
            echo '<p><strong>' . esc_html($kinds[$m['kind']] ?? $m['kind']) . '</strong> → ' . esc_html($m['to_email']) . '<br>' . esc_html($state);
            if ($m['last_error'] && $m['status'] !== 'sent' && current_user_can(\KdvLeads\Config::CAP_SETTINGS)) {
                echo '<br><span class="description">' . esc_html($m['last_error']) . '</span>';
            }
            echo '<br>' . self::post_form('resend', $id, 'kdv_resend_', get_submit_button('Renvoyer', 'small', 'submit', false), ['queue' => (int) $m['id']]) . '</p>';
        }
        if ($type === 'estimation') {
            echo '<p>' . self::post_form('resend', $id, 'kdv_resend_', get_submit_button('Renvoyer la notification KDV', 'secondary', 'submit', false)) . '</p>';
        }
        echo '</div></div>';

        // Privacy (RGPD)
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Données personnelles</h2></div><div class="inside">';
        if ($lead['pdf_status'] === 'erased') {
            echo '<p>Ce dossier a été anonymisé.</p>';
        } else {
            $exp = wp_nonce_url(admin_url('admin-post.php?action=kdv_export_lead&lead=' . $id), 'kdv_export_lead_' . $id);
            echo '<p><a class="button" href="' . esc_url($exp) . '">Exporter les données (JSON)</a></p>';
            if (current_user_can(\KdvLeads\Config::CAP_SETTINGS)) {
                echo self::post_form('anonymize', $id, 'kdv_anonymize_',
                    '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Anonymiser définitivement ce dossier ? Nom, téléphone, e-mail, PDF et photos seront effacés.\');">Anonymiser ce dossier</button>');
            }
        }
        echo '</div></div>';

        // History
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Historique</h2></div><div class="inside"><ul class="kdv-timeline">';
        foreach (LeadRepository::events($id) as $e) {
            $data = json_decode((string) $e['data'], true) ?: [];
            $extra = '';
            if ($e['event'] === 'status_changed') {
                $extra = ' : ' . LeadStatuses::label($type, (string) ($data['from'] ?? '')) . ' → ' . LeadStatuses::label($type, (string) ($data['to'] ?? ''));
            }
            $who = $e['user_id'] ? ' · ' . esc_html((string) get_the_author_meta('display_name', (int) $e['user_id'])) : '';
            echo '<li><time>' . esc_html(self::fmt($e['created_at'])) . '</time>' . esc_html((self::EVENTS[$e['event']] ?? $e['event']) . $extra) . $who . '</li>';
        }
        echo '</ul></div></div>';

        echo '</div></div>';   // right column, two-column flex
        Chrome::close();
    }

    private static function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>';
    }
}
