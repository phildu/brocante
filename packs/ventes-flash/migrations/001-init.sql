-- Ventes flash : une opération = une fenêtre de temps, un pas de baisse, un plafond de remise et un périmètre.
CREATE TABLE IF NOT EXISTS vf_sales (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  starts_at INTEGER NOT NULL,
  ends_at INTEGER NOT NULL,
  step_minutes INTEGER NOT NULL,
  step_percent REAL NOT NULL,
  max_percent REAL NOT NULL,
  refs TEXT NOT NULL DEFAULT '',            -- références séparées par des virgules ; vide = toutes les pièces à prix fixe en stock
  status TEXT NOT NULL DEFAULT 'scheduled', -- scheduled, running, ended, canceled
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- État d'origine des pièces concernées, pour tout rétablir à la fin (ou à l'annulation).
CREATE TABLE IF NOT EXISTS vf_items (
  sale_id INTEGER NOT NULL,
  product_ref TEXT NOT NULL,
  orig_badge TEXT NOT NULL DEFAULT '',
  orig_promo TEXT NOT NULL DEFAULT '',
  applied_percent REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (sale_id, product_ref)
);
