-- Messages des visiteurs (questions sur une pièce) et réponses envoyées.
CREATE TABLE IF NOT EXISTS ms_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  direction TEXT NOT NULL,                 -- in (visiteur) ou out (réponse)
  email TEXT NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  product_ref TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new',      -- new, read, answered (messages reçus)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ms_messages_email ON ms_messages (email, id);

CREATE TABLE IF NOT EXISTS ms_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ms_hits_ip ON ms_hits (ip_hash, at);

CREATE TABLE IF NOT EXISTS ms_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
