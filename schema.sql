-- SQLite (fichier unique, aucun serveur de base à provisionner — voir
-- db-path.php / config.php). Choisi pour ne dépendre d'aucune base MySQL
-- côté hébergement, comme le fait déjà le projet Louxor.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS content (
  id INTEGER PRIMARY KEY DEFAULT 1,
  site_name TEXT NOT NULL DEFAULT '', -- vide : nom du tenant.php
  site_tagline TEXT NOT NULL DEFAULT '',
  hero_eyebrow TEXT NOT NULL DEFAULT '',
  hero_title TEXT NOT NULL DEFAULT '',
  hero_subtitle TEXT,
  hero_photo TEXT,
  story_eyebrow TEXT NOT NULL DEFAULT '',
  story_title TEXT NOT NULL DEFAULT '',
  story_text TEXT,
  story_photo TEXT,
  story_photo_caption TEXT,
  principle1_title TEXT, principle1_text TEXT,
  principle2_title TEXT, principle2_text TEXT,
  principle3_title TEXT, principle3_text TEXT,
  contact_address TEXT,
  contact_hours TEXT,
  contact_delivery TEXT,
  shipping_fee TEXT NOT NULL DEFAULT '6,90 €',
  pickup_label TEXT NOT NULL DEFAULT 'Retrait à l''atelier',
  shipping_label TEXT NOT NULL DEFAULT 'Envoi postal',
  slideshow_orientation TEXT NOT NULL DEFAULT 'horizontal'
);

CREATE TABLE IF NOT EXISTS products (
  ref TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  cat TEXT NOT NULL,
  photo TEXT,
  promo_price TEXT,
  photo_retouche TEXT,
  photo_detoure TEXT,
  photo_angle TEXT,
  photo_ambiance TEXT,
  icon TEXT,
  description TEXT,
  price TEXT,
  badge TEXT,
  size_text TEXT,
  weight_text TEXT,
  materials TEXT,
  etat TEXT,
  weight_grams INTEGER NOT NULL DEFAULT 0,
  featured INTEGER NOT NULL DEFAULT 0,
  is_hidden INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  stock INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_photos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  path TEXT NOT NULL,
  path_mobile TEXT, -- version smartphone (9:16) des visuels générés, sinon NULL
  mobile_pending TEXT, -- version smartphone à générer (JSON : source, prompt), sinon NULL
  type TEXT NOT NULL DEFAULT 'photo',
  label TEXT NOT NULL DEFAULT 'Photo',
  is_illustration INTEGER NOT NULL DEFAULT 0,
  is_hidden INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_ref) REFERENCES products(ref) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_product_photos_ref ON product_photos(product_ref);

CREATE TABLE IF NOT EXISTS hero_slides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  path TEXT NOT NULL,
  caption TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS slideshow_slides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,
  path TEXT,
  product_ref TEXT,
  caption TEXT NOT NULL DEFAULT '',
  duration_seconds INTEGER NOT NULL DEFAULT 8,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_ref) REFERENCES products(ref) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS page_banners (
  page_key TEXT PRIMARY KEY,
  kind TEXT NOT NULL DEFAULT 'photo',
  path TEXT,
  overlay INTEGER NOT NULL DEFAULT 60, -- opacité du voile sombre, en % (0 = aucun voile)
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS media_library (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL DEFAULT 'photo',
  path TEXT NOT NULL,
  label TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  tags TEXT NOT NULL DEFAULT '',
  origin_ref TEXT,
  origin_name TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shipping_carriers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shipping_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  carrier_id INTEGER NOT NULL,
  service_name TEXT NOT NULL,
  weight_min_g INTEGER NOT NULL DEFAULT 0,
  weight_max_g INTEGER NOT NULL,
  price_cents INTEGER NOT NULL,
  delivery_delay TEXT NOT NULL DEFAULT '',
  zone TEXT NOT NULL DEFAULT 'metropole', -- 'metropole' | 'corse' | 'dom_tom'
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (carrier_id) REFERENCES shipping_carriers(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_shipping_rates_carrier ON shipping_rates(carrier_id);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stripe_session_id TEXT NOT NULL UNIQUE,
  email TEXT,
  amount_total INTEGER NOT NULL DEFAULT 0,
  shipping_cents INTEGER NOT NULL DEFAULT 0,
  shipping_method TEXT,
  shipping_address TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  fulfillment_status TEXT NOT NULL DEFAULT 'a_preparer',
  tracking_number TEXT,
  items TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Réglages divers du commerce (ex. « appearance » : palette et typographie
-- choisies dans Administration → Apparence), au format JSON.
CREATE TABLE IF NOT EXISTS settings (
  name TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Comptes : équipe (admin, community_manager) et contacts (client, prospect).
-- Voir includes/accounts.php (créée aussi automatiquement à l'ouverture d'une base ancienne).
CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  role TEXT NOT NULL DEFAULT 'prospect',
  name TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL,
  username TEXT,
  phone TEXT NOT NULL DEFAULT '',
  password_hash TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  source TEXT NOT NULL DEFAULT 'manuel',
  notes TEXT NOT NULL DEFAULT '',
  last_login_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_email ON accounts(email COLLATE NOCASE);
CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_username ON accounts(username COLLATE NOCASE) WHERE username IS NOT NULL;
