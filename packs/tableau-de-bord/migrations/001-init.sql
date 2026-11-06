-- Pages vues, sans cookie ni donnée personnelle : le visiteur n'est qu'une empreinte qui change chaque jour (adresse IP et navigateur ne sont jamais stockés).
CREATE TABLE IF NOT EXISTS td_views (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  day TEXT NOT NULL,
  vid TEXT NOT NULL,
  path TEXT NOT NULL,
  product_ref TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'direct',
  medium TEXT NOT NULL DEFAULT '',
  campaign TEXT NOT NULL DEFAULT '',
  device TEXT NOT NULL DEFAULT 'ordinateur'
);
CREATE INDEX IF NOT EXISTS td_views_day ON td_views (day);
CREATE INDEX IF NOT EXISTS td_views_ref ON td_views (product_ref, day);

CREATE TABLE IF NOT EXISTS td_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  day TEXT NOT NULL,
  vid TEXT NOT NULL,
  kind TEXT NOT NULL,
  product_ref TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'direct',
  campaign TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS td_events_day ON td_events (day, kind);

CREATE TABLE IF NOT EXISTS td_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
