-- SQLite (fichier unique, aucun serveur de base à provisionner — voir
-- db-path.php / config.php). Choisi pour ne dépendre d'aucune base MySQL
-- côté hébergement, comme le fait déjà le projet Louxor.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS content (
  id INTEGER PRIMARY KEY DEFAULT 1,
  site_name TEXT NOT NULL DEFAULT 'La Brocante du Petit Chalet',
  site_tagline TEXT NOT NULL DEFAULT 'Trouvailles chinées & pièces uniques',
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
  weight_grams INTEGER NOT NULL DEFAULT 0,
  featured INTEGER NOT NULL DEFAULT 0,
  is_hidden INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  stock INTEGER NOT NULL DEFAULT 1,
  cost_cents INTEGER, -- prix d'achat de la pièce (NULL = non renseigné), sert au calcul de marge du tableau de bord
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_photos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_ref TEXT NOT NULL,
  path TEXT NOT NULL,
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
  source_channel TEXT, -- provenance du client (direct, search, social...) relevée à la commande
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== Module « Invendus & dons » =====
-- Copie de unsold_schema_sql() (includes/invendus.php), qui crée ces tables à la
-- volée : le déploiement n'envoie ni brocante.db ni les *.sql. Garder synchronisé.

CREATE TABLE IF NOT EXISTS unsold_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  event_date TEXT NOT NULL,                       -- AAAA-MM-JJ
  location TEXT NOT NULL DEFAULT '',              -- lieu du vide-grenier
  point_name TEXT NOT NULL DEFAULT 'Point de collecte',
  point_details TEXT NOT NULL DEFAULT '',         -- où se trouve le point, comment le repérer
  collect_start TEXT NOT NULL,                    -- AAAA-MM-JJ HH:MM (heure de Paris)
  collect_end TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',           -- draft | published | closed
  desk_token TEXT NOT NULL UNIQUE,                -- lien secret des bénévoles du point
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS unsold_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  address TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  accepts TEXT NOT NULL DEFAULT '',               -- ce qu'ils acceptent (texte libre)
  hours TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS unsold_tours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  volunteer_name TEXT NOT NULL,
  volunteer_phone TEXT NOT NULL DEFAULT '',
  vehicle TEXT NOT NULL DEFAULT 'Vélo cargo',
  capacity INTEGER NOT NULL DEFAULT 12,           -- en « unités de volume » (petit=1, moyen=2, encombrant=4)
  token TEXT NOT NULL UNIQUE,                     -- lien secret du bénévole
  status TEXT NOT NULL DEFAULT 'planned',         -- planned | in_progress | done
  started_at TEXT,
  finished_at TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_unsold_tours_event ON unsold_tours(event_id);

CREATE TABLE IF NOT EXISTS unsold_donations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  recipient_id INTEGER NOT NULL,
  handed_by TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES unsold_recipients(id)
);
CREATE INDEX IF NOT EXISTS idx_unsold_donations_event ON unsold_donations(event_id);

CREATE TABLE IF NOT EXISTS unsold_offers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  token TEXT NOT NULL,                            -- lien de suivi du donateur (partagé par ses propositions)
  donor_name TEXT NOT NULL,
  donor_email TEXT NOT NULL DEFAULT '',
  donor_phone TEXT NOT NULL DEFAULT '',
  stand_ref TEXT NOT NULL DEFAULT '',             -- emplacement dans le vide-grenier
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'autre',
  quantity INTEGER NOT NULL DEFAULT 1,
  size TEXT NOT NULL DEFAULT 'M',                 -- S | M | L : volume de l'ensemble
  photo TEXT,
  handover TEXT NOT NULL DEFAULT 'self',          -- self (j'apporte) | pickup (à récupérer à mon stand)
  pickup_note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'proposed',        -- proposed | picked | at_point | taken | donated | withdrawn
  confirmed_at TEXT,                              -- le donateur a confirmé « c'est bien un invendu »
  tour_id INTEGER,
  donation_id INTEGER,
  admin_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE,
  FOREIGN KEY (tour_id) REFERENCES unsold_tours(id) ON DELETE SET NULL,
  FOREIGN KEY (donation_id) REFERENCES unsold_donations(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_event_status ON unsold_offers(event_id, status);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_token ON unsold_offers(token);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_tour ON unsold_offers(tour_id);

CREATE TABLE IF NOT EXISTS unsold_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  offer_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  at TEXT NOT NULL,
  actor TEXT NOT NULL,                            -- admin | tour | desk | donor
  from_status TEXT,
  to_status TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (offer_id) REFERENCES unsold_offers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_unsold_log_offer ON unsold_log(offer_id);

-- ---------------------------------------------------------------------------
-- Tableau de bord (admin/dashboard.php). Ces tables sont aussi créées à la
-- volée par analytics_ensure_schema() (includes/analytics.php) : une base
-- existante n'a donc aucune migration à lancer à la main.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS visits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL,                       -- UTC
  vid TEXT NOT NULL,                      -- empreinte salée (IP+navigateur+jour), jamais l'IP elle-même
  event TEXT NOT NULL DEFAULT 'pageview', -- pageview | add_to_cart | checkout
  path TEXT NOT NULL DEFAULT '',
  product_ref TEXT,
  channel TEXT NOT NULL DEFAULT 'direct',
  ref_host TEXT,
  utm_source TEXT,
  utm_campaign TEXT,
  device TEXT NOT NULL DEFAULT 'ordinateur',
  country TEXT
);
CREATE INDEX IF NOT EXISTS idx_visits_ts ON visits(ts);
CREATE INDEX IF NOT EXISTS idx_visits_vid ON visits(vid, ts);

CREATE TABLE IF NOT EXISTS physical_visits (
  day TEXT PRIMARY KEY,                   -- AAAA-MM-JJ (heure locale)
  visitors INTEGER NOT NULL DEFAULT 0,
  sales_count INTEGER NOT NULL DEFAULT 0,
  sales_cents INTEGER NOT NULL DEFAULT 0,
  cogs_cents INTEGER,                     -- prix d'achat des pièces vendues, si connu
  note TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS shop_costs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'autre',
  kind TEXT NOT NULL DEFAULT 'fixed',     -- fixed | per_order | per_order_pct | per_unit
  amount REAL NOT NULL DEFAULT 0,         -- euros (ou % pour per_order_pct)
  period TEXT NOT NULL DEFAULT 'monthly', -- fixed : monthly | yearly | once
  unit TEXT,                              -- per_unit : gemini_image | gemini_text | fal_cutout
  status TEXT NOT NULL DEFAULT 'actif',   -- actif = réellement payé | test = gratuit/essai aujourd'hui, simulé payant
  start_date TEXT,
  end_date TEXT,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_usage (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  provider TEXT NOT NULL,
  kind TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_api_usage_ts ON api_usage(ts);

CREATE TABLE IF NOT EXISTS dash_settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

-- Module Buvette (gestion de la buvette d'un vide-grenier) : ses tables
-- (préfixe bv_) sont créées automatiquement au premier accès par
-- includes/buvette.php (bv_boot, constante BV_SCHEMA) — rien à lancer ici,
-- ni côté serveur ni après un déploiement.

-- ---------------------------------------------------------------------------
-- Boutiques connectées (admin/connectors.php, api/catalog-push.php). Tables et
-- colonnes créées à la volée par connectors_ensure_schema()
-- (includes/connectors.php) : rien à lancer à la main. Garder synchronisé.
-- Les identifiants (clés API) ne sont PAS en base : .secrets/connector-<id>.json.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS import_sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  platform TEXT NOT NULL,                 -- shopify | woocommerce | prestashop | api
  base_url TEXT NOT NULL DEFAULT '',
  settings TEXT NOT NULL DEFAULT '{}',    -- JSON : univers par défaut, publication, stock, TVA…
  is_active INTEGER NOT NULL DEFAULT 1,
  last_sync_at TEXT,                      -- UTC
  last_sync_status TEXT,                  -- ok | partial | error
  last_sync_summary TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Colonnes ajoutées à products : source_id INTEGER, external_id TEXT,
-- external_url TEXT, synced_at TEXT, plus l'index unique (source_id, external_id)
-- idx_products_source_ext. Elles sont absentes du CREATE TABLE products plus haut :
-- connectors_ensure_schema() les ajoute par ALTER TABLE (base neuve comme existante).

-- Module Impression (commande d'affiches, cartes de visite, flyers, plans et
-- goodies auprès de fournisseurs, par API) : ses tables (préfixe pr_) sont
-- créées automatiquement au premier accès par includes/impression.php
-- (pr_boot, constante PR_SCHEMA) — rien à lancer ici, ni côté serveur ni
-- après un déploiement.
