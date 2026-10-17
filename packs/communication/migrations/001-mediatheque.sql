-- Médiathèque du pack Communication : informations en plus de la médiathèque de la plateforme (media_library et photos des
-- fiches), rangées par chemin de fichier, et vidéos externes (YouTube, Vimeo) gardées sous forme de liens.
CREATE TABLE IF NOT EXISTS com_media_meta (
  path TEXT PRIMARY KEY,
  title TEXT NOT NULL DEFAULT '',
  alt TEXT NOT NULL DEFAULT '',
  credit TEXT NOT NULL DEFAULT '',
  tags TEXT NOT NULL DEFAULT '',
  folder TEXT NOT NULL DEFAULT '',
  in_gallery INTEGER NOT NULL DEFAULT 0,
  web_path TEXT,
  width INTEGER,
  height INTEGER,
  bytes INTEGER,
  mime TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS com_media_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  url TEXT NOT NULL,
  platform TEXT NOT NULL,
  video_id TEXT NOT NULL,
  title TEXT NOT NULL DEFAULT '',
  folder TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS com_media_links_video ON com_media_links(platform, video_id);
