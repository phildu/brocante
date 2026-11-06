-- Actions commerciales : braderie, foire ou période de soldes, avec son périmètre et sa remise.
CREATE TABLE IF NOT EXISTS aco_operations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL DEFAULT 'braderie',          -- braderie, foire, soldes
  name TEXT NOT NULL,
  label TEXT NOT NULL DEFAULT '',                 -- mention affichée (bandeau, étiquettes)
  starts_at INTEGER NOT NULL,
  ends_at INTEGER NOT NULL,
  discount_type TEXT NOT NULL DEFAULT 'percent',  -- percent, amount
  discount_value REAL NOT NULL DEFAULT 0,         -- pourcentage, ou euros pour « amount »
  round_to INTEGER NOT NULL DEFAULT 0,            -- arrondi vers le bas, en centimes (0, 5, 10, 50, 100)
  cats TEXT NOT NULL DEFAULT '',                  -- catégories séparées par des virgules ; vide = toutes
  refs TEXT NOT NULL DEFAULT '',                  -- références séparées par des virgules ; si renseigné, prioritaire sur les catégories
  min_cents INTEGER NOT NULL DEFAULT 0,
  max_cents INTEGER NOT NULL DEFAULT 0,           -- 0 = pas de maximum
  min_age_days INTEGER NOT NULL DEFAULT 0,
  respect_floor INTEGER NOT NULL DEFAULT 1,
  place TEXT NOT NULL DEFAULT '',                 -- lieu ou stand (foire, braderie)
  show_banner INTEGER NOT NULL DEFAULT 1,
  banner_text TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'scheduled',       -- scheduled, running, ended, canceled
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Photographie des pièces touchées : prix et mention d'origine, pour tout rétablir à la fin.
CREATE TABLE IF NOT EXISTS aco_items (
  operation_id INTEGER NOT NULL,
  product_ref TEXT NOT NULL,
  orig_badge TEXT NOT NULL DEFAULT '',
  orig_promo TEXT NOT NULL DEFAULT '',
  old_cents INTEGER NOT NULL DEFAULT 0,
  new_cents INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 0,              -- 1 : le prix de l'opération est actuellement appliqué
  PRIMARY KEY (operation_id, product_ref)
);
CREATE INDEX IF NOT EXISTS aco_items_ref ON aco_items (product_ref);
