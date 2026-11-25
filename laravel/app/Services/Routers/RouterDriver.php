<?php

namespace App\Services\Routers;

/**
 * One contract for MikroTik RouterOS and TP-Link Omada SDN (on-prem + cloud).
 * Drivers throw CapabilityException for operations their platform cannot do:
 * Omada has no script/fetch/import or raw RouterOS command channel.
 */
interface RouterDriver
{
    public function name(): string;

    public function supports(string $capability): bool;

    /** @param array $router row from mikrotiks (credentials already decrypted) */
    public function connect(array $router): RouterSession;

    public function disconnect(RouterSession $session): void;

    /** name, model, firmware, serial, uptime, cpu_load, memory_usage */
    public function identity(RouterSession $session): array;

    public function interfaces(RouterSession $session): array;

    public function setSsid(RouterSession $session, string $interface, string $ssid): bool;

    /** interface, address pool, profiles, trial limits, radius settings */
    public function configureHotspot(RouterSession $session, array $config): array;

    public function configureRadius(RouterSession $session, array $config): bool;

    public function setWalledGarden(RouterSession $session, array $hosts, array $ips): bool;

    /** MAC-as-username on RouterOS; portal voucher / RADIUS identity on Omada */
    public function grantAccess(RouterSession $session, string $clientKey, array $limits = [], bool $preserveBytes = false): array;

    public function revokeAccess(RouterSession $session, string $clientKey): bool;

    public function activeSessions(RouterSession $session, ?string $clientKey = null): array;

    public function kickSession(RouterSession $session, string $sessionId): bool;

    public function hotspotUsers(RouterSession $session): array;

    public function createVoucher(RouterSession $session, array $spec): array;

    public function reboot(RouterSession $session): bool;

    public function logs(RouterSession $session, int $limit = 50): array;

    /** Return a driver-specific provisioning report. */
    public function applyProvisioning(RouterSession $session, array $config): array;

    /** Bootstrap script (.rsc) for MikroTik, operator checklist for Omada. */
    public function bootstrapInstructions(array $config): string;

    /** MikroTik only. */
    public function fetchAndImportScript(RouterSession $session, string $url, string $filename): bool;

    /** MikroTik only: raw RouterOS API path + attributes. */
    public function rawCommand(RouterSession $session, string $path, array $args = []): array;
}
