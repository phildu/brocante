-- Réservations avec arrhes : une pièce retenue par un client contre un versement en ligne.
CREATE TABLE IF NOT EXISTS ra_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  email TEXT NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  token TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pending',  -- pending (paiement en cours), active, honored, expired, forfeited, refunded, canceled
  price_cents INTEGER NOT NULL,
  deposit_cents INTEGER NOT NULL,
  days INTEGER NOT NULL,
  stripe_session_id TEXT NOT NULL DEFAULT '',
  payment_intent TEXT NOT NULL DEFAULT '',
  terms TEXT NOT NULL DEFAULT '',
  terms_accepted_at TEXT,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at TEXT,
  expires_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ra_res_status ON ra_reservations (status, expires_at);
CREATE INDEX IF NOT EXISTS ra_res_ref ON ra_reservations (product_ref, status);

CREATE TABLE IF NOT EXISTS ra_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS ra_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
