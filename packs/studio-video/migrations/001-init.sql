-- Projets du Studio vidéo : le script, les plans, les titres et le montage choisi.
-- Les prises elles-mêmes ne sont jamais envoyées au serveur : elles restent dans le navigateur (IndexedDB).
CREATE TABLE IF NOT EXISTS studio_projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'piece',
  audience TEXT NOT NULL DEFAULT '',
  tone TEXT NOT NULL DEFAULT 'simple',
  target_seconds INTEGER NOT NULL DEFAULT 45,
  format TEXT NOT NULL DEFAULT 'landscape',
  brief TEXT NOT NULL DEFAULT '',
  script TEXT NOT NULL DEFAULT '',
  shots_json TEXT NOT NULL DEFAULT '[]',
  titles_json TEXT NOT NULL DEFAULT '[]',
  checklist_json TEXT NOT NULL DEFAULT '[]',
  hashtags_json TEXT NOT NULL DEFAULT '[]',
  plan_json TEXT NOT NULL DEFAULT '[]',
  status TEXT NOT NULL DEFAULT 'draft',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS studio_projects_updated ON studio_projects(updated_at);
