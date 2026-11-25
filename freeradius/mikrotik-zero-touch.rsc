# ============================================================================
# Meshline Zero-Touch Router Bootstrap — ONE-TIME import (layman friendly)
# ============================================================================
# Run this once on a FRESH MikroTik (WinBox Terminal > paste, or /import).
# The router will then configure ITSELF:
#   1. Detect WAN + hotspot interfaces automatically
#   2. Phone home to the Meshline server
#   3. Download and apply its complete config (WG tunnel, hotspot, RADIUS, SSID)
#   4. Check in hourly with the server
#
# Once online, the router is managed over the WireGuard tunnel from ANYWHERE
# in Tanzania — no need to be on the same LAN.
#
# Set these values before importing:
#   <SERVER_HOST>   — Meshline portal hostname (e.g. hotbandowifi.online)
#   <REGISTRATION_SECRET> — router registration secret (from server .env
#                           ROUTER_REGISTRATION_SECRET)
# ============================================================================

:global MeshlineBaseUrl "https://<SERVER_HOST>"
:global MeshlineSecret "<REGISTRATION_SECRET>"
:global hbSerial [/system routerboard get serial-number]
:global hbIdentity [/system identity get name]
:global hbModel [/system resource get board-name]
:global hbFirmware [/system resource get version]

:put "============================================="
:put "Meshline Zero-Touch Setup"
:put "Serial: $hbSerial"
:put "Phone home: $MeshlineBaseUrl/api/zero-touch/register"
:put "============================================="

# ---------------------------------------------------------------------------
# 1. Phone home & save the generated config script
# ---------------------------------------------------------------------------
/tool fetch url=("$MeshlineBaseUrl/api/zero-touch/register") \
    http-method=post \
    http-content-type="application/json" \
    http-data="{\"serial\":\"$hbSerial\",\"identity\":\"$hbIdentity\",\"model\":\"$hbModel\",\"firmware\":\"$hbFirmware\",\"secret\":\"$MeshlineSecret\"}" \
    dst-path="Meshline-provision.rsc" \
    mode=https \
    output=none

:if ([:len [/file find name="Meshline-provision.rsc"]] = 0) do={
    :put "ERROR: could not download provisioning script!"
    :put "Check the portal hostname, firewall (TCP 443), and secret."
    :error "Meshline phone-home failed"
}

# ---------------------------------------------------------------------------
# 2. Apply the downloaded config
# ---------------------------------------------------------------------------
:put "Applying Meshline configuration..."
/import file-name="Meshline-provision.rsc"

:put "============================================="
:put "Meshline setup complete. Router is online."
:put "Configurable from anywhere via the server."
:put "============================================="