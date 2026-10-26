<?php
namespace KdvLeads\Http;

use KdvLeads\Config;
use KdvLeads\Database\Counters;

defined('ABSPATH') || exit;

/**
 * Server-signed "form opened at" token.
 *
 * The minimum fill time used to rely on a client-supplied elapsed_ms, which a script can simply lie about.
 * The token carries the time the page was rendered, signed with the site's secret salt: the server computes
 * the elapsed time itself and a forged or altered token is rejected. A token is also usable only a limited
 * number of times, so a bot must re-fetch the page to keep submitting.
 */
final class FormToken
{
    public const MAX_AGE = DAY_IN_SECONDS;
    public const MAX_USES = 12;

    private static function sign(int $t): string
    {
        return substr(hash_hmac('sha256', 'kdv-form|' . $t, wp_salt('nonce')), 0, 32);
    }

    /** @param int|null $at issue time (tests); defaults to now */
    public static function issue(?int $at = null): string
    {
        $t = $at ?? time();
        return $t . '.' . self::sign($t);
    }

    /**
     * @return string|null null when valid, otherwise a user-facing French message
     */
    public static function verify(string $token): ?string
    {
        if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $token, $m)) {
            return 'Votre session a expiré. Rechargez la page : vos informations sont conservées.';
        }
        $t = (int) $m[1];
        if (!hash_equals(self::sign($t), $m[2])) {
            return 'Votre session a expiré. Rechargez la page : vos informations sont conservées.';
        }
        $age = time() - $t;
        if ($age > self::MAX_AGE || $age < -60) {
            return 'Votre session a expiré. Rechargez la page : vos informations sont conservées.';
        }
        $min = (int) Config::get('min_fill_seconds');
        if ($min > 0 && $age < $min) {
            return 'Merci de prendre quelques secondes pour vérifier vos informations, puis de renvoyer votre demande.';
        }
        if (Counters::hit(Counters::hourly('ft', $token)) > self::MAX_USES) {
            return 'Votre session a expiré. Rechargez la page : vos informations sont conservées.';
        }
        return null;
    }
}
