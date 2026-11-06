-- Un envoi par canal et par adresse (e-mail, annonce dans l'app, SMS, WhatsApp), avec le journal de l'opération.
CREATE TABLE IF NOT EXISTS aco_messages (
  operation_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  channel TEXT NOT NULL DEFAULT 'mail',       -- mail, inapp, sms, whatsapp
  status TEXT NOT NULL DEFAULT 'sent',
  sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detail TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (operation_id, email, channel)
);
CREATE INDEX IF NOT EXISTS aco_messages_channel ON aco_messages (channel, operation_id);

INSERT OR IGNORE INTO aco_messages (operation_id, email, channel, status, sent_at)
  SELECT operation_id, email, 'mail', status, sent_at FROM aco_notices;

-- Accord par canal : l'e-mail suit l'inscription aux listes ; l'in-app est ouvert aux personnes reconnues ;
-- le SMS et WhatsApp exigent un numéro et un accord explicite recueilli par la boutique.
ALTER TABLE aco_contacts ADD COLUMN phone TEXT NOT NULL DEFAULT '';
ALTER TABLE aco_contacts ADD COLUMN consent_inapp INTEGER NOT NULL DEFAULT 1;
ALTER TABLE aco_contacts ADD COLUMN consent_sms INTEGER NOT NULL DEFAULT 0;
ALTER TABLE aco_contacts ADD COLUMN consent_wa INTEGER NOT NULL DEFAULT 0;
ALTER TABLE aco_contacts ADD COLUMN consent_at TEXT;
