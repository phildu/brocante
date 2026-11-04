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
  gift_wrap_enabled TEXT NOT NULL DEFAULT '0', -- '1' : proposer l'emballage cadeau au panier
  gift_wrap_price TEXT NOT NULL DEFAULT '',    -- prix de l'emballage, 0 ou vide = offert
  gift_wrap_label TEXT NOT NULL DEFAULT 'Emballage cadeau',
  slideshow_orientation TEXT NOT NULL DEFAULT 'horizontal',
  pickup_only_below TEXT NOT NULL DEFAULT '10', -- € : en dessous (dons à 0 € inclus), retrait seul ; 0 = seuls les dons
  pickup_only_message TEXT NOT NULL DEFAULT 'Retrait à l''adresse du vendeur.'
);

CREATE TABLE IF NOT EXISTS cart_discounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,                 -- 'total' : total du panier ; 'qty' : nombre d'articles
  threshold INTEGER NOT NULL,         -- centimes (total) ou nombre d'articles (qty) à atteindre
  type TEXT NOT NULL,                 -- 'percent' ou 'fixed'
  value INTEGER NOT NULL,             -- pourcentage (1-100) ou centimes
  label TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  price_floor TEXT, -- prix de vente minimum (interne, jamais affiché)
  est_min REAL, -- fourchette de prix estimée (euros)
  est_max REAL,
  est_show INTEGER NOT NULL DEFAULT 0, -- afficher la fourchette sur la fiche
  negotiable INTEGER NOT NULL DEFAULT 0, -- prix négociable (bouton « Faire une offre »)
  new_url TEXT, -- lien vers le prix du neuf
  new_price TEXT, -- prix du neuf constaté
  new_show INTEGER NOT NULL DEFAULT 0, -- afficher le prix et le lien du neuf sur la fiche
  colors TEXT, -- couleurs dominantes, séparées par des virgules (« bleu marine, blanc »)
  brand TEXT, -- marque de l'article (lue sur l'objet ou son étiquette)
  distributor TEXT, -- distributeur, éditeur ou revendeur d'origine
  etat TEXT,
  nature TEXT,
  sous_categorie TEXT,
  barcode TEXT,
  weight_grams INTEGER NOT NULL DEFAULT 0,
  featured INTEGER NOT NULL DEFAULT 0,
  is_hidden INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  stock INTEGER NOT NULL DEFAULT 1,
  channels TEXT, -- canaux de vente (clés séparées par des virgules : boutique, ecommerce, leboncoin, vinted…)
  sold_channel TEXT, -- canal par lequel la pièce a été vendue
  sold_at TEXT, -- date de la dernière vente (stock passé à 0)
  restocked_at TEXT, -- date du dernier réassort
  restock_possible INTEGER NOT NULL DEFAULT 0, -- réassort possible (délai et prix affichés sur la fiche)
  restock_delay TEXT, -- délai de réassort (« 10 jours », « 2 semaines »…)
  restock_price TEXT, -- prix d'un réassort (facultatif)
  keep_when_sold INTEGER NOT NULL DEFAULT 0, -- 1 : la pièce reste au catalogue (badge « Vendue ») une fois vendue
  lot_qty INTEGER NOT NULL DEFAULT 1, -- vente en lot : nombre de pièces vendues ensemble au prix affiché (1 = pièce seule)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_photos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  path TEXT NOT NULL,
  path_mobile TEXT, -- version smartphone (9:16) des visuels générés, sinon NULL
  mobile_pending TEXT, -- version smartphone à générer (JSON : source, prompt), sinon NULL
  cutout_of INTEGER, -- id de la photo de départ dont ce détourage est tiré (recto / verso automatique), sinon NULL
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
  gift_wrap INTEGER NOT NULL DEFAULT 0,        -- 1 : emballage cadeau demandé
  gift_wrap_cents INTEGER NOT NULL DEFAULT 0,  -- montant facturé pour l'emballage
  gift_message TEXT,                           -- petit mot du client pour le destinataire
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Habitudes anonymes des visiteurs pour les suggestions de produits (voir
-- includes/recommendations.php) : vues, recherches, filtres, panier, commandes.
-- visitor est l'empreinte d'un jeton de cookie, jamais une adresse IP.
CREATE TABLE IF NOT EXISTS recommendation_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor TEXT NOT NULL,
  kind TEXT NOT NULL,
  ref TEXT,
  meta TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rec_visitor ON recommendation_events(visitor, created_at);
CREATE INDEX IF NOT EXISTS idx_rec_ref ON recommendation_events(ref, created_at);
CREATE INDEX IF NOT EXISTS idx_rec_kind ON recommendation_events(kind, created_at);

-- Réglages divers du commerce (ex. « appearance » : palette et typographie
-- choisies dans Administration → Apparence), au format JSON.
CREATE TABLE IF NOT EXISTS settings (
  name TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Suivi d'ouverture des e-mails (image invisible de 1 × 1 pixel, voir
-- includes/mail-tracking.php et mail-open.php). Une ligne par message envoyé ;
-- open_count ne compte que les demandes humaines, bot_count celles des robots.
CREATE TABLE IF NOT EXISTS mail_opens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  context TEXT NOT NULL DEFAULT '',
  sent_at INTEGER NOT NULL,
  first_open_at INTEGER,
  last_open_at INTEGER,
  open_count INTEGER NOT NULL DEFAULT 0,
  bot_count INTEGER NOT NULL DEFAULT 0,
  ip TEXT NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_mail_opens_sent ON mail_opens(sent_at);
CREATE INDEX IF NOT EXISTS idx_mail_opens_source ON mail_opens(source);
CREATE INDEX IF NOT EXISTS idx_mail_opens_email ON mail_opens(email COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_mail_opens_context ON mail_opens(context);

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
