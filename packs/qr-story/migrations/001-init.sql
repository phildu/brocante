-- Histoire d'une pièce (page publique par QR Code) ; le jeton aléatoire fait l'adresse publique.
CREATE TABLE IF NOT EXISTS qs_stories (
  product_ref TEXT PRIMARY KEY,
  token TEXT NOT NULL UNIQUE,
  epoch TEXT NOT NULL DEFAULT '',
  origin TEXT NOT NULL DEFAULT '',
  expertise TEXT NOT NULL DEFAULT '',
  expert_name TEXT NOT NULL DEFAULT '',
  published INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS qs_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
