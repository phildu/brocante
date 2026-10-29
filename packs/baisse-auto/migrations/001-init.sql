-- Baisses de prix automatiques : état par pièce, journal des baisses, réglages.
CREATE TABLE IF NOT EXISTS bp_state (
  product_ref TEXT PRIMARY KEY,
  base_cents INTEGER NOT NULL,          -- prix de départ du cycle de baisses
  step INTEGER NOT NULL DEFAULT 0,      -- nombre de paliers déjà appliqués
  last_cents INTEGER NOT NULL,          -- dernier prix posé par le module (un autre prix = changement manuel : le cycle repart)
  started_at INTEGER NOT NULL           -- début du comptage d'ancienneté
);

CREATE TABLE IF NOT EXISTS bp_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  old_price TEXT NOT NULL,
  new_price TEXT NOT NULL,
  percent REAL NOT NULL,
  step INTEGER NOT NULL,
  mode TEXT NOT NULL,                   -- auto, manuel
  at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS bp_log_ref ON bp_log (product_ref, id);

CREATE TABLE IF NOT EXISTS bp_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
