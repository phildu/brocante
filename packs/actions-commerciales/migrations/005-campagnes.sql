-- Campagnes de communication : un plan d'envois programmés par opération (annonce, lancement, dernier jour…).
-- Une étape est ancrée au début ou à la fin de l'opération, avec un décalage en minutes (négatif = avant).
CREATE TABLE IF NOT EXISTS aco_campaign_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  operation_id INTEGER NOT NULL,
  title TEXT NOT NULL DEFAULT '',
  anchor TEXT NOT NULL DEFAULT 'start',          -- start, end
  offset_min INTEGER NOT NULL DEFAULT 0,         -- minutes ; négatif = avant l'ancre
  channels TEXT NOT NULL DEFAULT 'mail',         -- mail, inapp, sms, whatsapp, social (séparés par des virgules)
  subject TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'planned',        -- planned, sending, to_publish, done, skipped, failed
  claimed_at INTEGER NOT NULL DEFAULT 0,         -- verrou : une seule exécution à la fois par étape
  done_at INTEGER,
  detail TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aco_campaign_steps_op ON aco_campaign_steps (operation_id, status);

-- Journal par étape, adresse et canal : une personne ne reçoit jamais deux fois la même étape sur le même canal.
CREATE TABLE IF NOT EXISTS aco_campaign_sends (
  step_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  channel TEXT NOT NULL DEFAULT 'mail',
  status TEXT NOT NULL DEFAULT 'sent',           -- sent, failed
  sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (step_id, email, channel)
);
