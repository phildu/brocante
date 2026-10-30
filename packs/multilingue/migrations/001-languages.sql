-- Versions linguistiques de la boutique : une ligne par langue générée.
-- Les textes traduits ne sont pas en base : ils sont écrits en dur dans tenants/<slug>/lang/<code>.json.
CREATE TABLE IF NOT EXISTS ml_languages (
  code TEXT PRIMARY KEY,
  label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'draft',           -- draft | published
  source_locale TEXT NOT NULL DEFAULT 'fr',
  strings INTEGER NOT NULL DEFAULT 0,
  chars INTEGER NOT NULL DEFAULT 0,
  cost_usd REAL NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_at TEXT
);
