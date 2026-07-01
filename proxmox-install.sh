#!/usr/bin/env bash
# ============================================================================
# Trading Journal — Proxmox LXC Installer
# Läuft auf dem PROXMOX HOST. Erstellt einen neuen LXC Container und
# führt darin install.sh aus.
#
# Aufruf auf dem Proxmox Host:
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/swissneo85/trading-journal/main/proxmox-install.sh)"
# ============================================================================
set -euo pipefail

REPO_URL="https://github.com/swissneo85/trading-journal.git"
RAW_INSTALL_SH="https://raw.githubusercontent.com/swissneo85/trading-journal/main/install.sh"

echo "=== Trading Journal LXC Installer ==="

# ---- Container Parameter (anpassbar, sonst Defaults) ----
read -rp "Container ID [110]: " CTID
CTID=${CTID:-110}
read -rp "Hostname [trading-journal]: " HOSTNAME
HOSTNAME=${HOSTNAME:-trading-journal}
read -rp "RAM in MB [1024]: " RAM
RAM=${RAM:-1024}
read -rp "Disk in GB [4]: " DISK
DISK=${DISK:-4}
read -rp "CPU Cores [1]: " CORES
CORES=${CORES:-1}
read -rp "Storage [local-lvm]: " STORAGE
STORAGE=${STORAGE:-local-lvm}
read -rp "Netzwerk-Bridge [vmbr0]: " BRIDGE
BRIDGE=${BRIDGE:-vmbr0}

# ---- Debian 13 Template sicherstellen ----
TEMPLATE="debian-13-standard_13.1-2_amd64.tar.zst"
if ! pveam list local 2>/dev/null | grep -q "$TEMPLATE"; then
    echo "Lade Debian 13 Template..."
    pveam update
    pveam download local "$TEMPLATE"
fi

# ---- Container erstellen ----
echo "Erstelle LXC Container $CTID ($HOSTNAME)..."
pct create "$CTID" "local:vztmpl/$TEMPLATE" \
    --hostname "$HOSTNAME" \
    --memory "$RAM" \
    --cores "$CORES" \
    --rootfs "${STORAGE}:${DISK}" \
    --net0 "name=eth0,bridge=${BRIDGE},ip=dhcp" \
    --unprivileged 1 \
    --features nesting=1 \
    --onboot 1

pct start "$CTID"
echo "Warte auf Netzwerk..."
sleep 8

# ---- Installer im Container ausführen ----
echo "Lade install.sh in den Container..."
pct exec "$CTID" -- bash -c "curl -fsSL $RAW_INSTALL_SH -o /root/install.sh && chmod +x /root/install.sh"

echo "Starte Installation im Container..."
pct exec "$CTID" -- bash /root/install.sh

IP=$(pct exec "$CTID" -- hostname -I | awk '{print $1}')

echo ""
echo "============================================"
echo "✅ Fertig!"
echo "Container ID: $CTID"
echo "Weboberfläche: http://${IP}:8080"
echo "============================================"
