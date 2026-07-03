# Trading Journal
Persönliches CFD Trading Journal für Capital.com
Stack: Laravel (SQLite, kein separater DB-Server), Nginx, statisches Frontend (Alpine.js)

## Setup

### Proxmox LXC (empfohlen)
```
bash -c "$(curl -fsSL https://raw.githubusercontent.com/swissneo85/trading-journal/main/proxmox-install.sh)"
```
Erstellt einen LXC Container und führt darin `install.sh` aus.

### Manuell im Container
```
git clone https://github.com/swissneo85/trading-journal.git /opt/trading-journal
cd /opt/trading-journal
bash install.sh
```

Beim ersten Aufruf der Weboberfläche (`http://<IP>:8080`) den Settings-Tab öffnen
und Capital.com- sowie Telegram-Zugangsdaten eintragen — diese werden in der
Datenbank gepflegt, nicht in `.env`.

## Updates

Es gibt kein Auto-Deploy — nach jedem Merge auf `main` muss der Container
manuell aktualisiert werden. `<CTID>` ist die Container-ID aus `pct list`
auf dem Proxmox-Host (Standard beim Setup: `110`).

**Nur Frontend geändert** (z.B. `www/index.html`) — reicht ein `git pull` +
Kopieren der statischen Datei, kein Neustart nötig:
```bash
pct exec <CTID> -- bash -c "cd /opt/trading-journal && git pull && cp www/index.html /var/www/trading-journal/"
```

**Backend geändert** (`backend/`, Migrations, `.php`-Dateien) — komplett
`install.sh` erneut laufen lassen (idempotent, zieht auch Composer-Abhängigkeiten
und Migrationen nach):
```bash
pct exec <CTID> -- bash -c "cd /opt/trading-journal && git pull && bash install.sh"
```

Danach im Browser einen harten Reload machen (Safari/Chrome cachen aggressiv).

## Struktur
- `backend/` — Laravel-API (Trades, Import, Settings, Telegram-Webhook), SQLite-Datei
  unter `data/journal.sqlite`
- `www/` — statisches Frontend (Alpine.js, kein Build-Schritt)
- `install.sh` — Setup-Skript, läuft innerhalb des LXC
- `proxmox-install.sh` — erstellt den LXC auf dem Proxmox-Host und ruft `install.sh` auf
