-- Retraits sur stand : pièce réservée en ligne, somme bloquée sur la carte (paiement en deux temps), encaissée au scan du billet.
CREATE TABLE IF NOT EXISTS rt_pickups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  email TEXT NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  token TEXT NOT NULL UNIQUE,
  code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pending',  -- pending (paiement en cours), authorized, collecting, collected, released, expired, canceled
  amount_cents INTEGER NOT NULL,
  stripe_session_id TEXT NOT NULL DEFAULT '',
  payment_intent TEXT NOT NULL DEFAULT '',
  terms TEXT NOT NULL DEFAULT '',
  order_id INTEGER,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  authorized_at TEXT,
  expires_at INTEGER NOT NULL DEFAULT 0,
  collected_at TEXT
);
CREATE INDEX IF NOT EXISTS rt_pickups_status ON rt_pickups (status, expires_at);
CREATE INDEX IF NOT EXISTS rt_pickups_ref ON rt_pickups (product_ref, status);

CREATE TABLE IF NOT EXISTS rt_hits (ip_hash TEXT NOT NULL, at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS rt_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
