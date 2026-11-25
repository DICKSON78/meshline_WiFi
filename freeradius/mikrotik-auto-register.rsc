# Meshline MikroTik Heartbeat Script (embedded in zero-touch config)
# ============================================================================
# Keep on EACH MikroTik router. Checks in hourly with the Meshline server.
# The server marks the router ONLINE and returns whether a config refresh is
# needed. The full auto-provisioning flow lives in mikrotik-zero-touch.rsc.
# ============================================================================

# Replace placeholders:
#   HOTBANDO_SERVER  — portal hostname, e.g. hotbandowifi.online
#   REGISTRATION_SECRET — ROUTER_REGISTRATION_SECRET from server .env
#   ROUTER_ID        — the router_id assigned at registration (e.g. HB-XXXX)
# ============================================================================

:local MeshlineBaseUrl "https://HOTBANDO_SERVER"
:local MeshlineSecret "REGISTRATION_SECRET"
:local routerId "ROUTER_ID"

:log info "Meshline heartbeat..."
/tool fetch url=("$MeshlineBaseUrl/api/zero-touch/heartbeat") \
    http-method=post \
    http-content-type="application/json" \
    http-data="{\"router_id\":\"$routerId\",\"secret\":\"$MeshlineSecret\"}" \
    mode=https \
    output=none

:log info "Meshline heartbeat sent."