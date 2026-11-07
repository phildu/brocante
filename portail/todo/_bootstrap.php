<?php

// Liste de tâches du portail : les paramétrages à faire (Stripe, e-mail, IA…).
// Module autonome : sa base SQLite vit dans data/todo/ et ne touche ni aux
// bases des boutiques ni à celle des forces de vente. L'accès reprend la
// connexion du portail (compte .secrets/portail.json en ligne).

require_once __DIR__ . '/../_bootstrap.php';

date_default_timezone_set('Europe/Paris');

const TD_CATEGORIES = [
    'paiement' => 'Paiement',
    'email' => 'E-mail',
    'ia' => 'IA',
    'telephone' => 'Téléphone',
    'boutiques' => 'Boutiques',
    'hebergement' => 'Hébergement',
    'administratif' => 'Administratif',
    'autre' => 'Autre',
];

const TD_CATEGORY_COLORS = [
    'paiement' => '#1f5a86',
    'email' => '#6a4fa3',
    'ia' => '#2e7d4f',
    'telephone' => '#9a5b12',
    'boutiques' => '#8a3b52',
    'hebergement' => '#4a5a63',
    'administratif' => '#6b6b3a',
    'autre' => '#5b6861',
];

const TD_PRIORITIES = [1 => 'Prioritaire', 2 => 'Normale', 3 => 'Plus tard'];

// ── Base ─────────────────────────────────────────────────────────────────────

function td_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = PORTAIL_ROOT . '/data/todo';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        throw new RuntimeException("Impossible de créer data/todo/ : droits d'écriture manquants.");
    }
    $file = "$dir/todo.db";
    $new = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    td_schema($pdo);
    if ($new) @chmod($file, 0600);
    td_seed_if_needed($pdo);
    return $pdo;
}

function td_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'autre',
    priority INTEGER NOT NULL DEFAULT 2,
    shop TEXT NOT NULL DEFAULT '',
    link TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'todo',
    position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    done_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_td_status ON tasks(status, priority, position);
SQL);
}

function td_setting(string $name, string $default = ''): string
{
    $st = td_db()->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $value = $st->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function td_setting_set(string $name, string $value): void
{
    td_db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
        ->execute([$name, $value]);
}

/**
 * La liste de départ n'est écrite qu'une fois : si toutes les tâches sont
 * supprimées, elles ne réapparaissent pas au chargement suivant.
 */
function td_seed_if_needed(PDO $pdo): void
{
    $done = (bool) $pdo->query("SELECT 1 FROM settings WHERE name = 'seed_at'")->fetchColumn();
    if ($done) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('INSERT INTO tasks (title, detail, category, priority, shop, link, status, position, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach (td_seed_tasks() as $i => $task) {
        $st->execute([
            $task['title'],
            $task['detail'],
            $task['category'],
            $task['priority'],
            $task['shop'] ?? '',
            $task['link'] ?? '',
            'todo',
            $i + 1,
            $now,
            $now,
        ]);
    }
    td_setting_set('seed_at', $now);
}

/**
 * Les paramétrages connus à ce jour (suivi des jalons + dernières livraisons).
 * Chaque détail dit où aller et ce qui manque, pour être exécutable seul.
 */
function td_seed_tasks(): array
{
    return [
        [
            'title' => 'Brancher le webhook Stripe de chaque boutique',
            'detail' => "L'URL exacte est affichée dans Administration → Réglages → Stripe de chaque boutique : ‹adresse de la boutique›/stripe-webhook.php (la boutique du portail : bricbrok.com/la-fripe-a-naty/stripe-webhook.php ; le Petit Chalet : brocante.arrimage.com/stripe-webhook.php). Dans Stripe → Développeurs → Webhooks, ajouter cette URL, choisir les événements checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, charge.refunded et charge.dispute.created/updated/closed, puis coller le whsec_… reçu dans les réglages de la boutique. Le bandeau doit passer à « actif ». Sans ce secret, une commande payée n'est pas enregistrée côté boutique.",
            'category' => 'paiement',
            'priority' => 1,
        ],
        [
            'title' => 'Faire un paiement de test de bout en bout',
            'detail' => "Avec les clés de test (sk_test_… / pk_test_…) sur une boutique de test : ajouter une pièce au panier, payer par carte 4242 4242 4242 4242 (date future, CVC quelconque), choisir livraison ou retrait, puis vérifier l'e-mail de confirmation, la commande « payée » dans Administration → Commandes, le stock décrémenté et le webhook reçu. Rembourser ensuite depuis Stripe et vérifier que la commande passe « remboursée ». Le parcours commande est déployé sur bricbrok.com ; la recette réelle reste à faire. Les tests automatiques locaux passent (PHPUnit 12 tests, e2e S2 6/6).",
            'category' => 'paiement',
            'priority' => 1,
        ],
        [
            'title' => 'Décider et brancher Stripe Connect',
            'detail' => "1) Activer Connect dans le tableau de bord Stripe et compléter le profil plateforme (KYC, IBAN, TVA, conditions). 2) Portail → Commissions : cocher « Prélever la commission à l'encaissement (Stripe Connect) ». 3) Créer le compte Connect du commerce ou de la galerie, envoyer le lien d'inscription (KYC du titulaire), puis « Actualiser » jusqu'au statut prêt (informations validées + virements possibles). 4) Essayer en mode test : vente, commission plateforme, part galerie, virement. Échéance Notion : 21/11/2026. Le code (destination charge, application_fee_amount, transfer_data) est en place et couvert par l'e2e S2.",
            'category' => 'paiement',
            'priority' => 1,
        ],
        [
            'title' => 'Charger une clé Gemini avec des crédits',
            'detail' => "Quand les crédits Google AI Studio sont épuisés, la génération de contenu et de descriptions de photos est refusée (erreur 402) alors que la page des clés dit « valide ». Recharger sur ai.studio/projects, puis enregistrer la clé dans le portail.",
            'category' => 'ia',
            'priority' => 1,
        ],
        [
            'title' => 'Déployer le suivi d\'ouverture des e-mails',
            'detail' => "Le pixel 1×1, la table mail_opens, l'écran admin/emails.php et l'indicateur d'ouverture sont construits et testés en local le 01/11/2026, pas encore en production.",
            'category' => 'email',
            'priority' => 2,
        ],
        [
            'title' => 'Vérifier la réception des alertes de surveillance',
            'detail' => "La sonde externe interroge /health.php toutes les 5 minutes sur les deux hébergements. Vérifier qu'une alerte arrive bien à fildeschamps@gmail.com quand un site ne répond plus, et que le retour au vert est notifié.",
            'category' => 'email',
            'priority' => 2,
        ],
        [
            'title' => 'Ajouter les clés SMS (OVH ou Twilio)',
            'detail' => "La couche SMS des notifications est construite mais sans clés. Renseigner le compte OVH ou Twilio, l'expéditeur, puis envoyer un message de test vers son propre numéro.",
            'category' => 'telephone',
            'priority' => 2,
        ],
        [
            'title' => 'Tester les notifications visiteur sur une boutique',
            'detail' => "Le pack actions 1.2.0 est en ligne sur 3 boutiques : vérifier les 4 canaux (dont SMS quand les clés seront là) et le consentement avec un compte client de test sur la-friperie-de-naty.",
            'category' => 'boutiques',
            'priority' => 2,
        ],
        [
            'title' => 'Recette de l\'assistance vendeur',
            'detail' => "Le widget est en ligne sur les 6 boutiques. Se mettre en situation vendeur, poser une question depuis le widget, et suivre la demande jusqu'à la réponse dans l'administration.",
            'category' => 'boutiques',
            'priority' => 2,
        ],
        [
            'title' => 'Remplacer les deux offres de recrutement TEST',
            'detail' => "Le module recrutement est en ligne avec deux annonces de test. Les remplacer par les vraies offres avant diffusion, puis retirer les annonces TEST.",
            'category' => 'boutiques',
            'priority' => 3,
        ],
        [
            'title' => 'Plan de montée en charge OVH',
            'detail' => "Paliers mutualisé → VPS → Public Cloud, seuils de bascule mesurés, coût mensuel par palier. À faire avant la 10e boutique. Échéance Notion : 30/11/2026.",
            'category' => 'hebergement',
            'priority' => 1,
        ],
        [
            'title' => 'Tester une restauration de sauvegarde',
            'detail' => "Restaurer la base d'une boutique copiée dans un dossier de test, pour vérifier que la procédure de retour arrière fonctionne vraiment avant d'en avoir besoin.",
            'category' => 'hebergement',
            'priority' => 2,
        ],
        [
            'title' => 'Réserver le domaine brocs.fr',
            'detail' => "Il est libre à 10,99 €/an ; les variantes courtes broc.* sont déjà prises. Après l'achat : DNS vers l'hébergement, certificat SSL et renouvellement automatique.",
            'category' => 'administratif',
            'priority' => 2,
        ],
    ];
}

// ── Lecture et écriture ──────────────────────────────────────────────────────

function td_text($value, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')));
    return mb_substr($text, 0, $max);
}

function td_paragraph($value, int $max): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($value ?? '')));
    return mb_substr($text, 0, $max);
}

/** Nettoie une saisie de tâche ; lève InvalidArgumentException avec un message affichable. */
function td_clean(array $in): array
{
    $title = td_text($in['title'] ?? '', 160);
    if ($title === '') {
        throw new InvalidArgumentException('Donnez un titre à la tâche.');
    }
    $category = (string) ($in['category'] ?? 'autre');
    if (!isset(TD_CATEGORIES[$category])) {
        $category = 'autre';
    }
    $priority = (int) ($in['priority'] ?? 2);
    if (!isset(TD_PRIORITIES[$priority])) {
        $priority = 2;
    }
    $link = trim((string) ($in['link'] ?? ''));
    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        throw new InvalidArgumentException("Le lien doit commencer par http:// ou https://.");
    }
    $shop = td_text($in['shop'] ?? '', 60);
    if ($shop !== '' && !isset(tenant_list()[$shop])) {
        $shop = '';
    }
    return [
        'title' => $title,
        'detail' => td_paragraph($in['detail'] ?? '', 1200),
        'category' => $category,
        'priority' => $priority,
        'shop' => $shop,
        'link' => mb_substr($link, 0, 500),
    ];
}

function td_add(array $in): int
{
    $data = td_clean($in);
    $now = date('Y-m-d H:i:s');
    $position = (int) td_db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM tasks')->fetchColumn();
    td_db()->prepare('INSERT INTO tasks (title, detail, category, priority, shop, link, status, position, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$data['title'], $data['detail'], $data['category'], $data['priority'], $data['shop'], $data['link'], 'todo', $position, $now, $now]);
    return (int) td_db()->lastInsertId();
}

function td_update(int $id, array $in): void
{
    $data = td_clean($in);
    td_db()->prepare('UPDATE tasks SET title = ?, detail = ?, category = ?, priority = ?, shop = ?, link = ?, updated_at = ? WHERE id = ?')
        ->execute([$data['title'], $data['detail'], $data['category'], $data['priority'], $data['shop'], $data['link'], date('Y-m-d H:i:s'), $id]);
}

function td_toggle(int $id): void
{
    $st = td_db()->prepare('SELECT status FROM tasks WHERE id = ?');
    $st->execute([$id]);
    $status = $st->fetchColumn();
    if ($status === false) {
        return;
    }
    $done = $status === 'todo';
    td_db()->prepare('UPDATE tasks SET status = ?, done_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$done ? 'done' : 'todo', $done ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $id]);
}

function td_delete(int $id): void
{
    td_db()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
}

function td_find(int $id): ?array
{
    $st = td_db()->prepare('SELECT * FROM tasks WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Tâches à faire ou faites, triées pour l'affichage. */
function td_tasks(string $status = 'todo', string $category = '', int $limit = 0): array
{
    $sql = 'SELECT * FROM tasks WHERE status = ?';
    $args = [$status];
    if ($category !== '' && isset(TD_CATEGORIES[$category])) {
        $sql .= ' AND category = ?';
        $args[] = $category;
    }
    $sql .= $status === 'done' ? ' ORDER BY done_at DESC, id DESC' : ' ORDER BY priority ASC, position ASC, id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $st = td_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Nombre de tâches par état : ['open' => n, 'urgent' => n, 'done' => n]. */
function td_count(): array
{
    $row = td_db()->query("SELECT
        SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) AS open,
        SUM(CASE WHEN status = 'todo' AND priority = 1 THEN 1 ELSE 0 END) AS urgent,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
        FROM tasks")->fetch();
    return [
        'open' => (int) ($row['open'] ?? 0),
        'urgent' => (int) ($row['urgent'] ?? 0),
        'done' => (int) ($row['done'] ?? 0),
    ];
}
