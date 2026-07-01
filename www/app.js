const MASK = '●●●●●●';

const CAPITAL_URLS = {
    live: 'https://api-capital.backend-capital.com',
    demo: 'https://demo-api-capital.backend-capital.com',
};

function app() {
    return {
        tab: 'trades',

        // Trades tab
        trades: [],
        selectedTrade: null,
        detail: [],
        quellenList: [],
        tagForm: { quelle: '', notiz: '' },
        tagStatus: '',

        // Import tab
        importText: '',
        importStatus: '',

        // Settings tab
        settings: {
            quellen: '',
            capital_api_key: '',
            capital_email: '',
            capital_password: '',
            capital_url: '',
            poll_interval_seconds: 60,
            telegram_bot_token: '',
            telegram_chat_id: '',
        },
        settingsMasked: {
            capital_api_key: false,
            capital_password: false,
            telegram_bot_token: false,
        },
        capitalUrlMode: 'live',
        settingsStatus: '',

        init() {
            this.loadTrades();
            this.loadConfig();
        },

        formatDate(value) {
            if (!value) return '';
            const d = new Date(value);
            if (isNaN(d.getTime())) return value;
            return d.toLocaleString('de-CH');
        },

        async loadTrades() {
            const res = await fetch('/trading/trades');
            this.trades = await res.json();
        },

        async loadConfig() {
            const res = await fetch('/trading/config');
            const data = await res.json();
            this.quellenList = (data.quellen || '')
                .split(',')
                .map((q) => q.trim())
                .filter(Boolean);
        },

        async selectTrade(trade) {
            this.selectedTrade = trade;
            this.tagForm.quelle = trade.quelle || '';
            this.tagForm.notiz = trade.notiz || '';
            this.tagStatus = '';

            const res = await fetch(`/trading/detail?dealId=${encodeURIComponent(trade.deal_id)}`);
            this.detail = await res.json();
        },

        async saveTag() {
            const res = await fetch('/trading/tag', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    dealId: this.selectedTrade.deal_id,
                    quelle: this.tagForm.quelle,
                    notiz: this.tagForm.notiz,
                }),
            });
            const data = await res.json();
            this.tagStatus = data.message || 'Gespeichert';

            this.selectedTrade.quelle = this.tagForm.quelle;
            this.selectedTrade.notiz = this.tagForm.notiz;
        },

        async runImport() {
            let payload;
            try {
                payload = JSON.parse(this.importText);
            } catch (e) {
                this.importStatus = '⚠️ Ungültiges JSON';
                return;
            }

            const res = await fetch('/trading/import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            this.importStatus = data.message || 'Import abgeschlossen';
            this.loadTrades();
        },

        async loadSettings() {
            const res = await fetch('/trading/settings');
            const data = await res.json();

            for (const key of Object.keys(this.settings)) {
                if (data[key] === undefined) continue;

                if (this.settingsMasked[key] !== undefined) {
                    this.settingsMasked[key] = data[key] === MASK;
                    this.settings[key] = this.settingsMasked[key] ? '' : data[key];
                } else {
                    this.settings[key] = data[key];
                }
            }

            this.capitalUrlMode = Object.entries(CAPITAL_URLS).find(
                ([, url]) => url === this.settings.capital_url
            )?.[0] || 'custom';
        },

        onCapitalUrlModeChange() {
            if (this.capitalUrlMode !== 'custom') {
                this.settings.capital_url = CAPITAL_URLS[this.capitalUrlMode];
            }
        },

        async saveSettings() {
            const payload = {};

            for (const [key, value] of Object.entries(this.settings)) {
                if (key in this.settingsMasked && value === '') {
                    continue; // untouched secret field, keep server value
                }
                payload[key] = value;
            }

            const res = await fetch('/trading/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            this.settingsStatus = data.message || 'Gespeichert';
            this.loadSettings();
        },
    };
}
