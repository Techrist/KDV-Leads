<?php
namespace KdvLeads\Vehicle;

defined('ABSPATH') || exit;

/**
 * Registration-plate lookup (P1). Provider and budget are pending the client's decision, so only the
 * architecture exists: plug an implementation in with the `kdv_plate_lookup` filter.
 */
interface PlateLookup
{
    /** True only when a provider is configured (API key present). */
    public function enabled(): bool;

    /**
     * @param string $plate canonical plate (AB-123-CD)
     * @return array{marque?:string, modele?:string, finition?:string, annee?:int, motorisation?:string, boite?:string}|null null when unknown
     * @throws \RuntimeException on provider failure (the form then falls back to manual entry)
     */
    public function lookup(string $plate): ?array;
}
