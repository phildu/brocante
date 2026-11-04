-- Rendez-vous (consultation de disponibilités type Doodle / Framadate) : une consultation, ses créneaux,
-- les réponses des visiteurs et les disponibilités cochées pour chaque créneau.
CREATE TABLE IF NOT EXISTS rdv_polls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  place TEXT NOT NULL DEFAULT '',
  notify_email TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open',
  results_public INTEGER NOT NULL DEFAULT 1,
  names_public INTEGER NOT NULL DEFAULT 0,
  allow_maybe INTEGER NOT NULL DEFAULT 1,
  closes_on TEXT NOT NULL DEFAULT '',
  confirmed_slot_id INTEGER,
  confirmed_at TEXT NOT NULL DEFAULT '',
  notified_at TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS rdv_polls_token ON rdv_polls(token);
CREATE INDEX IF NOT EXISTS rdv_polls_status ON rdv_polls(status);

CREATE TABLE IF NOT EXISTS rdv_slots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  poll_id INTEGER NOT NULL,
  slot_date TEXT NOT NULL,
  start_time TEXT NOT NULL DEFAULT '',
  end_time TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL DEFAULT '',
  position INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS rdv_slots_poll ON rdv_slots(poll_id, slot_date, start_time);

CREATE TABLE IF NOT EXISTS rdv_answers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  poll_id INTEGER NOT NULL,
  token TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  comment TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS rdv_answers_poll_email ON rdv_answers(poll_id, email);
CREATE INDEX IF NOT EXISTS rdv_answers_poll ON rdv_answers(poll_id);

CREATE TABLE IF NOT EXISTS rdv_choices (
  answer_id INTEGER NOT NULL,
  slot_id INTEGER NOT NULL,
  value INTEGER NOT NULL DEFAULT 2,
  PRIMARY KEY (answer_id, slot_id)
);
CREATE INDEX IF NOT EXISTS rdv_choices_slot ON rdv_choices(slot_id);

-- Limite les envois publics par connexion (adresse IP hachée, jamais conservée en clair).
CREATE TABLE IF NOT EXISTS rdv_hits (
  ip_hash TEXT NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS rdv_hits_at ON rdv_hits(at);
