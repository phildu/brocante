-- Tables propres au pack « Vente en ligne ». Les commandes, comptes, transporteurs,
-- tarifs et remises restent dans les tables du cœur : le pack les gère sans les modifier.
CREATE TABLE IF NOT EXISTS vel_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (email)
);

CREATE TABLE IF NOT EXISTS vel_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER,
  type TEXT NOT NULL DEFAULT 'note',
  message TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS vel_events_order ON vel_events (order_id, id);
