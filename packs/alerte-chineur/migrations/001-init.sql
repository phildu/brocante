-- Alertes des chineurs : critères, jeton (confirmation / désinscription), état.
CREATE TABLE IF NOT EXISTS ac_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  keywords TEXT NOT NULL,
  max_price_cents INTEGER NOT NULL DEFAULT 0,
  token TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending (à confirmer), active, stopped
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at TEXT,
  last_notified_at TEXT
);
CREATE INDEX IF NOT EXISTS ac_alerts_email ON ac_alerts (email);

-- Pièces déjà signalées à une alerte (jamais deux fois).
CREATE TABLE IF NOT EXISTS ac_sent (
  alert_id INTEGER NOT NULL,
  product_ref TEXT NOT NULL,
  sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (alert_id, product_ref)
);

-- Limite d'inscriptions par visiteur (empreinte de l'adresse IP, jamais l'adresse elle-même).
CREATE TABLE IF NOT EXISTS ac_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ac_hits_ip ON ac_hits (ip_hash, at);

CREATE TABLE IF NOT EXISTS ac_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
