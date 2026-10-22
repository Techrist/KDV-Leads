<?php
namespace KdvLeads\Vehicle;

defined('ABSPATH') || exit;

/** Default implementation: lookup disabled. */
final class NullPlateLookup implements PlateLookup
{
    public function enabled(): bool
    {
        return false;
    }

    public function lookup(string $plate): ?array
    {
        return null;
    }
}
