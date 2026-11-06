-- Bourse d'échanges : propositions de vente des visiteurs et recherches d'achat du commerçant.
-- La table reprend le schéma déjà utilisé en production ; « IF NOT EXISTS » préserve les demandes existantes
-- si la boutique utilisait la version intégrée au socle avant l'activation du plugin.
CREATE TABLE IF NOT EXISTS bourse_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL DEFAULT 'offre',
  status TEXT NOT NULL DEFAULT 'new',
  parent_id INTEGER,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  condition TEXT NOT NULL DEFAULT '',
  price_cents INTEGER,
  contact_name TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  photo TEXT,
  notes TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'site',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS bourse_items_status ON bourse_items(status);
CREATE INDEX IF NOT EXISTS bourse_items_kind ON bourse_items(kind);
