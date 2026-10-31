-- Assistance vendeur : réglages, vendeurs prévenus, visiteurs en ligne, alertes, questions, pièces retenues.

CREATE TABLE IF NOT EXISTS av_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

-- Vendeurs prévenus : un accord par canal (l'e-mail suit le compte, le SMS et WhatsApp exigent un numéro et un accord explicite).
CREATE TABLE IF NOT EXISTS av_sellers (
  email TEXT PRIMARY KEY,
  name TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  consent_mail INTEGER NOT NULL DEFAULT 1,
  consent_sms INTEGER NOT NULL DEFAULT 0,
  consent_wa INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  updated_at INTEGER NOT NULL DEFAULT 0
);

-- Présence : un jeton par navigateur (session de la boutique), la dernière page vue et le temps passé sur la pièce.
CREATE TABLE IF NOT EXISTS av_visitors (
  token TEXT PRIMARY KEY,
  first_at INTEGER NOT NULL DEFAULT 0,
  last_at INTEGER NOT NULL DEFAULT 0,
  page TEXT NOT NULL DEFAULT '',
  ref TEXT NOT NULL DEFAULT '',
  dwell INTEGER NOT NULL DEFAULT 0,
  cart TEXT NOT NULL DEFAULT '',
  ip_hash TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS av_visitors_last ON av_visitors (last_at);

-- Alertes du vendeur : question, visite, attente sur une pièce, panier, paiement.
CREATE TABLE IF NOT EXISTS av_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT '',
  src TEXT NOT NULL DEFAULT '',
  ref TEXT NOT NULL DEFAULT '',
  product_name TEXT NOT NULL DEFAULT '',
  detail TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  seen_at INTEGER,
  handled_at INTEGER,
  handled_by TEXT NOT NULL DEFAULT '',
  action TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS av_alerts_created ON av_alerts (created_at);
CREATE INDEX IF NOT EXISTS av_alerts_visitor ON av_alerts (visitor, kind, created_at);

-- Pièce retenue quelques minutes : pour le visiteur qui la regarde (visitor renseigné) ou pour tous.
CREATE TABLE IF NOT EXISTS av_holds (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL,
  visitor TEXT NOT NULL DEFAULT '',
  until INTEGER NOT NULL DEFAULT 0,
  alert_id INTEGER NOT NULL DEFAULT 0,
  created_by TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  released_at INTEGER
);
CREATE INDEX IF NOT EXISTS av_holds_ref ON av_holds (ref, until);

-- Questions posées sur le site (réponse par e-mail, WhatsApp ou rappel).
CREATE TABLE IF NOT EXISTS av_questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  channel TEXT NOT NULL DEFAULT 'site',
  ref TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  answered_at INTEGER
);
CREATE INDEX IF NOT EXISTS av_questions_created ON av_questions (created_at);

-- Journal d'envoi par alerte, vendeur et canal (un seul envoi par triplet).
CREATE TABLE IF NOT EXISTS av_notifications (
  alert_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  channel TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT '',
  detail TEXT NOT NULL DEFAULT '',
  sent_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (alert_id, email, channel)
);

-- Limitation des questions par adresse IP (hachée).
CREATE TABLE IF NOT EXISTS av_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS av_hits_at ON av_hits (at);