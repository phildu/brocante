-- Réglages de l'option Annonces places de marché (jeton d'extension, abonnement).
CREATE TABLE IF NOT EXISTS am_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

-- Historique des publications assistées (Leboncoin, Vinted…).
CREATE TABLE IF NOT EXISTS am_listings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  channel TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  listing_url TEXT NOT NULL DEFAULT '',
  external_id TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  published_at TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS am_listings_ref ON am_listings (product_ref);
CREATE INDEX IF NOT EXISTS am_listings_channel ON am_listings (channel, status);
