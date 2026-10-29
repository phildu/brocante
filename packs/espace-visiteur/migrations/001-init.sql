-- Espace visiteur & fidélité : membres (inscrits avec leur accord), journal de points, récompenses.
CREATE TABLE IF NOT EXISTS ev_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS ev_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL DEFAULT '',
  token TEXT NOT NULL UNIQUE,                 -- clé du lien personnel envoyé par e-mail
  consent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  welcomed INTEGER NOT NULL DEFAULT 0,        -- points de bienvenue déjà crédités (après le premier clic sur le lien)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TEXT
);

CREATE TABLE IF NOT EXISTS ev_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id INTEGER NOT NULL,
  kind TEXT NOT NULL,                         -- earn (achat), welcome, redeem (récompense), adjust (ajustement)
  points INTEGER NOT NULL,                    -- positif ou négatif
  order_id INTEGER,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ev_ledger_member ON ev_ledger (member_id);
CREATE UNIQUE INDEX IF NOT EXISTS ev_ledger_order ON ev_ledger (order_id, kind) WHERE order_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS ev_rewards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id INTEGER NOT NULL,
  code TEXT NOT NULL UNIQUE,
  cents INTEGER NOT NULL,
  points INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',      -- active, used
  order_id INTEGER,                           -- commande qui l'utilise (réservée à l'ouverture du paiement, soldée au paiement)
  reserved_until INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at TEXT
);
CREATE INDEX IF NOT EXISTS ev_rewards_member ON ev_rewards (member_id);

CREATE TABLE IF NOT EXISTS ev_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);
