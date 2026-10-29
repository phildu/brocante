-- Réglages du module Reçus & journal des ventes (vendeur, régime de TVA, comptes comptables).
CREATE TABLE IF NOT EXISTS rc_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

-- Reçus d'achat émis : un par commande payée, numérotation continue par année.
CREATE TABLE IF NOT EXISTS rc_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL UNIQUE,
  year INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  number TEXT NOT NULL,
  token TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL DEFAULT '',
  data TEXT NOT NULL,
  issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  emailed_at TEXT,
  UNIQUE (year, seq)
);
