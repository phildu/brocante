-- Annonces aux visiteurs : jeton de désinscription par adresse et journal des envois par opération.
CREATE TABLE IF NOT EXISTS aco_contacts (
  email TEXT PRIMARY KEY,
  token TEXT NOT NULL UNIQUE,                  -- lien de désinscription envoyé avec chaque annonce
  state TEXT NOT NULL DEFAULT 'active',        -- active, stopped
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stopped_at TEXT
);

CREATE TABLE IF NOT EXISTS aco_notices (
  operation_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT '',             -- visiteur, chineur, prospect (sources cumulées séparées par des virgules)
  status TEXT NOT NULL DEFAULT 'sent',         -- sent, failed
  sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (operation_id, email)
);
CREATE INDEX IF NOT EXISTS aco_notices_status ON aco_notices (operation_id, status);
