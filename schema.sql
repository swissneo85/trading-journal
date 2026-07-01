CREATE TABLE IF NOT EXISTS activities (
  deal_id       TEXT NOT NULL,
  date_utc      TEXT NOT NULL,
  epic          TEXT,
  instrument    TEXT,
  direction     TEXT,
  size          REAL,
  level         REAL,
  open_price    REAL,
  source        TEXT,
  PRIMARY KEY (deal_id, date_utc)
);

CREATE TABLE IF NOT EXISTS transactions (
  reference        TEXT PRIMARY KEY,
  deal_id          TEXT,
  date_utc         TEXT,
  instrument       TEXT,
  transaction_type TEXT,
  pl_chf           REAL,
  note             TEXT
);

CREATE TABLE IF NOT EXISTS trade_tags (
  deal_id     TEXT PRIMARY KEY,
  quelle      TEXT,
  notiz       TEXT,
  tagged_at   TEXT
);

CREATE TABLE IF NOT EXISTS config (
  key   TEXT PRIMARY KEY,
  value TEXT
);

INSERT OR IGNORE INTO config VALUES ('quellen', 'Gruppe A,Gruppe B,Gruppe C,Eigene Idee');
