<?php
namespace KdvLeads;

defined('ABSPATH') || exit;

final class LeadStatuses
{
    /** @return array<string,string> value => label */
    public static function for_type(string $type): array
    {
        if ($type === 'recherche') {
            return [
                'nouveau' => 'Nouveau', 'qualifie' => 'Qualifié', 'recherche_en_cours' => 'Recherche en cours',
                'trouve' => 'Trouvé', 'perdu' => 'Perdu',
            ];
        }
        return [
            'nouveau' => 'Nouveau', 'a_rappeler' => 'À rappeler', 'contacte' => 'Contacté', 'rdv' => 'RDV',
            'mandat' => 'Mandat', 'perdu' => 'Perdu', 'vendu' => 'Vendu',
        ];
    }

    /** Statuses that leave the "open" pool (sorted last in the list). */
    public static function closed(): array
    {
        return ['perdu', 'vendu', 'trouve'];
    }

    public static function label(string $type, string $status): string
    {
        return self::for_type($type)[$status] ?? $status;
    }
}
