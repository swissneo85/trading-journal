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

## Struktur
- `backend/` — Laravel-API (Trades, Import, Settings, Telegram-Webhook), SQLite-Datei
  unter `data/journal.sqlite`
- `www/` — statisches Frontend (Alpine.js, kein Build-Schritt)
- `install.sh` — Setup-Skript, läuft innerhalb des LXC
- `proxmox-install.sh` — erstellt den LXC auf dem Proxmox-Host und ruft `install.sh` auf
