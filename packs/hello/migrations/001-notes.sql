-- Notes de démonstration, propres à chaque boutique (dans sa base).
CREATE TABLE IF NOT EXISTS hello_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  text TEXT NOT NULL,
  author TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
