-- Livre de police : une ligne par objet acquis, numérotation continue (jamais supprimée ni renumérotée).
CREATE TABLE IF NOT EXISTS pr_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  number INTEGER NOT NULL UNIQUE,
  product_ref TEXT NOT NULL UNIQUE,
  entry_date TEXT NOT NULL,
  description TEXT NOT NULL,
  purchase_cents INTEGER,
  payment_mode TEXT NOT NULL DEFAULT '',
  seller_type TEXT NOT NULL DEFAULT 'particulier',
  seller_lastname TEXT NOT NULL DEFAULT '',
  seller_firstname TEXT NOT NULL DEFAULT '',
  seller_birth_date TEXT NOT NULL DEFAULT '',
  seller_birth_place TEXT NOT NULL DEFAULT '',
  seller_address TEXT NOT NULL DEFAULT '',
  id_doc_type TEXT NOT NULL DEFAULT '',
  id_doc_number TEXT NOT NULL DEFAULT '',
  id_doc_issuer TEXT NOT NULL DEFAULT '',
  id_doc_date TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  sale_date TEXT,
  sale_cents INTEGER,
  buyer TEXT NOT NULL DEFAULT '',
  sale_channel TEXT NOT NULL DEFAULT '',
  order_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Journal des modifications : ajout seulement, chaque ligne est chaînée à la précédente (hash) pour déceler toute altération.
CREATE TABLE IF NOT EXISTS pr_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id INTEGER NOT NULL,
  at TEXT NOT NULL,
  action TEXT NOT NULL,
  payload TEXT NOT NULL,
  hash TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pr_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
