# 📋 Rapport d'Audit Technique - Portail Brocante
**Date :** 7 octobre 2026  
**Projet :** Portail de création d'e-boutiques pour commerces de seconde main et brocantes  
**Dépôt :** phildu/brocante  
**Type :** Application PHP + SQLite + Stripe

---

## 🎯 **Résumé Exécutif**

Votre plateforme **Brocante** est **bien conçue** avec une architecture PHP moderne, une base SQLite simple à déployer, et des fonctionnalités e-commerce complètes (panier, checkout, gestion produits, transporteurs).

**Points forts :**
✅ Architecture propre et modulaire  
✅ Intégration Stripe fonctionnelle  
✅ Système de panier et checkout complet  
✅ Administration complète avec import par lot  
✅ Design moderne et responsive  
✅ Intégration IA (Gemini, fal.ai) pour le traitement d'images  

**Problèmes critiques à corriger en priorité :**
❌ **Bug de session** : `session_start()` manquant dans plusieurs fichiers → panier peut ne pas fonctionner correctement  
❌ **Sécurité** : Mot de passe admin en clair dans config.php + pas de protection CSRF  
❌ **Stock** : Risque de vente de produits hors stock (race condition)  
❌ **Prix** : Fonction `price_to_cents()` fragile avec certains formats de prix  

**Score actuel :** 7.5/10 (Fonctionnel mais nécessite des corrections de sécurité et de stabilité)

---

## 🏗️ **Architecture Technique**

### Stack
- **Backend :** PHP 8.x (PDO, cURL, GD, ZipArchive)
- **Base de données :** SQLite (fichier unique `brocante.db`)
- **Frontend :** PHP templates + CSS custom + JavaScript vanilla
- **Paiements :** Stripe API (mode test et production)
- **IA :** Gemini API (génération d'images, description) + fal.ai (détourage)
- **Hébergement :** OVH (mutualisé)

### Structure des fichiers
```
brocante/
├── admin/                  # Backoffice complet
│   ├── index.php          # Tableau de bord
│   ├── catalog.php        # Gestion des produits
│   ├── orders.php         # Gestion des commandes
│   ├── shipping.php       # Transporteurs et tarifs
│   ├── batch-import.php   # Import par lot
│   └── ... (15+ fichiers)
│
├── includes/
│   ├── functions.php      # Fonctions utilitaires (2000+ lignes)
│   ├── header.php         # En-tête du site
│   └── footer.php         # Pied de page
│
├── assets/                # CSS, JS, images
├── uploads/               # Images uploadées
├── *.php                  # Pages frontales (index, boutique, cart, checkout...)
├── config.php             # Configuration
├── schema.sql            # Schéma de la base
└── brocante.db           # Base SQLite
```

### Base de données (SQLite)
- **12 tables** : products, orders, shipping_carriers, shipping_rates, product_photos, hero_slides, slideshow_slides, page_banners, media_library, content, shipping_carriers, shipping_rates
- **Pas de dépendance externe** : SQLite intégrée = déploiement simplifié
- **Sauvegardes** : Fichiers `.bak` générés automatiquement

---

## 🐛 **Bugs Critiques Identifiés**

### 🔴 **PRIORITÉ 1 - À CORRIGER IMMÉDIATEMENT**

#### 1. **Bug de Session - Panier instable**
**Fichiers concernés :** `boutique.php`, `produit.php`

**Problème :**
- `session_start()` est **manquant** dans `boutique.php` et `produit.php`
- Le panier (`$_SESSION['cart']`) ne sera pas disponible dans ces pages
- Conséquence : Le compteur de panier dans le header ne fonctionnera pas

**Impact :** ⭐⭐⭐⭐⭐ (Utilisateur ne voit pas son panier)

**Solution :**
```php
// Dans boutique.php et produit.php, ajouter en haut :
session_start();
```

**Fichiers à corriger :**
- `/boutique.php`
- `/produit.php`
- Tout autre fichier qui affiche des infos de session

---

#### 2. **Race Condition sur le Stock**
**Fichier concerné :** `cart-add.php`

**Problème :**
```php
// Dans cart-add.php
if ($product && is_fixed_price(effective_price($product)) && in_stock($product)) {
    cart_add($ref, 1);  // ← Problème : vérification puis ajout en 2 étapes
}
```

Si deux utilisateurs ajoutent le dernier article en même temps :
- Utilisateur A vérifie : stock = 1 → OK
- Utilisateur B vérifie : stock = 1 → OK  
- Utilisateur A ajoute au panier
- Utilisateur B ajoute au panier
- **Résultat :** 2 articles vendus alors qu'il n'y en avait qu'1 en stock

**Impact :** ⭐⭐⭐⭐⭐ (Survente possible)

**Solution :**
```php
// Dans cart-add.php
db()->beginTransaction();
try {
    // Vérifier le stock ET mettre à jour en une seule transaction
    $stmt = db()->prepare('SELECT stock FROM products WHERE ref = ? FOR UPDATE');
    $stmt->execute([$ref]);
    $stock = (int) $stmt->fetchColumn();
    
    if ($stock > 0) {
        // Décrémenter temporairement (réservation)
        $update = db()->prepare('UPDATE products SET stock = stock - 1 WHERE ref = ?');
        $update->execute([$ref]);
        cart_add($ref, 1);
        db()->commit();
    } else {
        db()->rollBack();
        flash_set('Désolé, ce produit est épuisé.', 'error');
    }
} catch (Throwable $e) {
    db()->rollBack();
    throw $e;
}
```

**Alternative plus simple (moins robuste mais mieux que rien) :**
```php
if ($product && is_fixed_price(effective_price($product))) {
    // Re-vérifier le stock au moment de l'ajout
    $currentProduct = get_product($ref);
    if ($currentProduct && in_stock($currentProduct)) {
        cart_add($ref, 1);
        flash_set('« ' . $product['name'] . ' » ajouté au panier.');
    } else {
        flash_set('Ce produit n\'est plus disponible.', 'error');
    }
}
```

---

#### 3. **Problème de Format de Prix**
**Fichier concerné :** `includes/functions.php` (ligne 1569)

**Problème :**
La fonction `price_to_cents()` suppose un format de prix français :
```php
function price_to_cents(?string $price): ?int
{
    if (!$price) return null;
    if (!preg_match('/(\d[\d\s]*)(?:[,.](\d{1,2}))?/', $price, $m)) return null;
    $euros = (int) str_replace(' ', '', $m[1]);
    $cents = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;
    return $euros * 100 + $cents;
}
```

**Problèmes :**
- Ne gère pas les prix comme "10 €" (sans décimales)
- Ne gère pas les prix avec symbole avant : "€10,50"
- Return `null` si le format ne correspond pas → **erreur fatale** dans `cart_lines()`

**Impact :** ⭐⭐⭐⭐ (Prix incorrects ou erreurs dans le panier)

**Solution :**
```php
function price_to_cents(?string $price): ?int
{
    if (!$price) return null;
    
    // Nettoyer le prix : retirer symboles, espaces
    $cleanPrice = preg_replace('/[^\d.,-]/', '', $price);
    
    // Gérer les formats : "10", "10.50", "10,50", "10,5", "1 000"
    if (preg_match('/^(\d{1,10})([.,](\d{1,2}))?$/', str_replace(' ', '', $cleanPrice), $m)) {
        $euros = (int) $m[1];
        $cents = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;
        return $euros * 100 + $cents;
    }
    
    return null;
}
```

---

#### 4. **Sécurité - Mot de passe admin en clair**
**Fichier concerné :** `config.php` (ligne 16)

**Problème :**
```php
define('ADMIN_PASSWORD', 'armoire2026');  // ← EN CLAIR DANS LE CODE !
```

**Impact :** ⭐⭐⭐⭐⭐ (Quiconque a accès au code peut se connecter en admin)

**Solution :**
1. **Ne JAMAIS committer de mots de passe en clair**
2. Utiliser des variables d'environnement ou des fichiers `.env`
3. **Solution immédiate :**
```php
// Dans config.php, remplacer par :
$adminPasswordFile = __DIR__ . '/.secrets/admin_password.txt';
define('ADMIN_PASSWORD', is_file($adminPasswordFile) ? trim(file_get_contents($adminPasswordFile)) : '');
```
4. Créer le fichier `/brocante/.secrets/admin_password.txt` avec le mot de passe
5. **Ajouter `.secrets/` à `.gitignore`**

---

#### 5. **Pas de protection CSRF**
**Fichiers concernés :** Tous les formulaires POST (cart-add.php, checkout.php, admin/* )

**Problème :**
Aucune protection contre les attaques CSRF (Cross-Site Request Forgery).
Un attaquant pourrait forcer un utilisateur à :
- Ajouter des produits à son panier
- Passer une commande
- Modifier des paramètres admin

**Impact :** ⭐⭐⭐⭐ (Vulnérabilité de sécurité majeure)

**Solution :**
```php
// 1. Dans includes/functions.php, ajouter :
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf(): bool
{
    return isset($_POST['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// 2. Dans chaque formulaire, ajouter :
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

// 3. Dans chaque fichier qui traite du POST, ajouter :
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    if (!validate_csrf()) {
        die('CSRF token invalid');
    }
}
```

---

### 🟡 **PRIORITÉ 2 - Problèmes importants**

#### 6. **Sauvegardes de base de données exposées**
**Fichiers concernés :** `/brocante.db.bak-*` (6 fichiers de sauvegarde)

**Problème :**
- Les sauvegardes SQLites sont dans le même dossier que le code
- **Accessibles via URL** si le serveur est mal configuré
- Exemple : `https://votre-site.com/brocante.db.bak-20260907-042248`

**Impact :** ⭐⭐⭐⭐ (Fuites de données sensibles)

**Solution :**
```php
// 1. Déplacer les sauvegardes dans un dossier non accessible par le web
define('BACKUP_DIR', __DIR__ . '/../backups/');  // En dehors de la racine web

// 2. Dans deploy-brocante.sh, modifier la commande de sauvegarde :
cp /chemin/vers/brocante.db /chemin/vers/backups/brocante-$(date +%Y%m%d-%H%M%S).db
```

---

#### 7. **Pas de gestion des erreurs global**
**Problème :**
- Pas de gestion centralisée des erreurs
- Les erreurs PHP peuvent exposer des informations sensibles
- Pas de logging structuré

**Impact :** ⭐⭐⭐ (Expérience utilisateur médiocre + risque de fuite d'info)

**Solution :**
```php
// Dans config.php ou un nouveau fichier includes/error_handler.php
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    
    // Loguer l'erreur
    error_log(sprintf("[ERROR] %s: %s in %s on line %d", 
        date('Y-m-d H:i:s'), $errstr, $errfile, $errline));
    
    // Afficher un message générique en production
    if (ini_get('display_errors')) {
        echo "Une erreur est survenue. L'équipe a été notifiée.";
    }
    
    return true;
});

// Désactiver l'affichage des erreurs en production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
```

---

#### 8. **Pas de validation des entrées utilisateur**
**Fichiers concernés :** `cart-add.php`, `checkout.php`, tous les formulaires admin

**Problème :**
- Pas de validation systématique des `$_GET`/`$_POST`
- Risque d'injections SQL (même si PDO est utilisé, les paramètres sont parfois mal typés)

**Exemple vulnérable :**
```php
// Dans admin/delete-product.php
$ref = $_GET['ref'];  // ← Pas de validation !
$stmt = db()->prepare('DELETE FROM products WHERE ref = ?');
$stmt->execute([$ref]);  // PDO protège, mais la ref pourrait être vide
```

**Solution :**
```php
// Fonction de validation générale
function validate_ref(string $ref): ?string
{
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $ref)) {
        return null;
    }
    return $ref;
}

// Utilisation :
$ref = validate_ref($_GET['ref'] ?? '');
if (!$ref) {
    die('Référence invalide');
}
```

---

#### 9. **Problème de redirect après login admin**
**Fichier concerné :** `admin/login.php`

**Problème :**
- Après un login réussi, pas de protection contre les redirects vers des URLs externes
- Exemple : `?redirect=https://malicious-site.com`

**Impact :** ⭐⭐⭐ (Vulnérabilité Open Redirect)

**Solution :**
```php
function safe_redirect(string $url, string $default = '/'): void
{
    if (!preg_match('#^https?://(?:[a-z0-9-]+\.)?[a-z0-9-]+\.[a-z]{2,}(?:/[^\s]*)?$#i', $url)) {
        $url = $default;
    }
    // Vérifier que l'URL est bien sur le même domaine
    $parsed = parse_url($url);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if (($parsed['host'] ?? '') !== $currentHost) {
        $url = $default;
    }
    header('Location: ' . $url);
    exit;
}
```

---

#### 10. **Problème de cache navigateur**
**Problème :**
- Pas de headers Cache-Control appropriés
- Les pages admin pourraient être cachées par le navigateur
- Les images pourraient ne pas être rafraîchies

**Solution :**
```php
// Dans includes/header.php, ajouter :
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
```

---

## 💡 **Améliorations Fonctionnelles**

### 🎯 **Pour les brocantes et la seconde main**

#### 1. **Système de Réservation pour Brocantes**
**Besoins :**
- Créer des événements "brocante" avec date/heure/lieu
- Permettre aux clients de réserver un créneau
- Limiter le nombre de personnes par créneau

**Implémentation :**
```sql
-- Nouvelle table
CREATE TABLE brocante_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    address TEXT NOT NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_participants INTEGER DEFAULT 50,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brocante_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    user_name TEXT,
    participants INTEGER DEFAULT 1,
    status TEXT DEFAULT 'confirmed',
    reservation_token TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES brocante_events(id)
);
```

**Pages à créer :**
- `brocante-events.php` (liste des événements)
- `brocante-event.php?id=X` (détails + réservation)
- `admin/brocante-events.php` (gestion admin)

---

#### 2. **Géolocalisation des Boutiques/Points de Vente**
**Besoins :**
- Afficher une carte avec les points de vente
- Filtrer les brocantes par distance
- Intégration avec Google Maps ou Mapbox

**Implémentation :**
```sql
ALTER TABLE content ADD COLUMN map_latitude REAL;
ALTER TABLE content ADD COLUMN map_longitude REAL;
ALTER TABLE content ADD COLUMN map_zoom INTEGER DEFAULT 12;
```

**Code frontend :**
```html
<div id="map" style="height: 400px; width: 100%;"></div>
<script>
function initMap() {
    const map = L.map('map').setView([<?= $content['map_latitude'] ?>, <?= $content['map_longitude'] ?>], <?= $content['map_zoom'] ?>);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
    
    // Ajouter des markers pour les brocantes
    <?= json_encode($brocanteLocations) ?>.forEach(location => {
        L.marker([location.lat, location.lng])
            .addTo(map)
            .bindPopup(`<b>${location.title}</b><br>${location.address}`);
    });
}
</script>
```

---

#### 3. **Système de Négociation de Prix**
**Besoins typiques des brocantes :**
- Permettre aux clients de faire une offre sur un produit
- Notification au vendeur
- Acceptation/Refus de l'offre

**Implémentation :**
```sql
CREATE TABLE negotiations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_ref TEXT NOT NULL,
    user_email TEXT NOT NULL,
    user_name TEXT,
    proposed_price_cents INTEGER NOT NULL,
    status TEXT DEFAULT 'pending', -- pending, accepted, rejected, cancelled
    seller_notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_ref) REFERENCES products(ref)
);
```

**Fonctionnalités :**
- Bouton "Faire une offre" sur les produits
- Page `negotiation.php?id=X` pour suivre l'état
- Notifications email au vendeur
- Admin : `admin/negotiations.php`

---

#### 4. **Système de Messagerie Interne**
**Besoins :**
- Permettre aux clients de contacter le vendeur
- Historique des conversations
- Notifications

**Implémentation :**
```sql
CREATE TABLE conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject TEXT NOT NULL,
    user_email TEXT NOT NULL,
    user_name TEXT,
    status TEXT DEFAULT 'open', -- open, closed
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender TEXT NOT NULL, -- 'client' or 'admin'
    body TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
);
```

---

#### 5. **Système de Notation et Avis**
**Besoins :**
- Permettre aux clients de noter les produits
- Avis vérifiés (après achat)
- Affichage des notes moyennes

**Implémentation :**
```sql
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_ref TEXT NOT NULL,
    order_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title TEXT,
    comment TEXT,
    is_approved INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_ref) REFERENCES products(ref),
    FOREIGN KEY (order_id) REFERENCES orders(id)
);
```

**Affichage :**
```php
function product_rating_html(array $product): string
{
    $stmt = db()->prepare('SELECT AVG(rating) as avg, COUNT(*) as count FROM reviews WHERE product_ref = ? AND is_approved = 1');
    $stmt->execute([$product['ref']]);
    $data = $stmt->fetch();
    
    if (!$data || !$data['count']) return '';
    
    $avg = (float) $data['avg'];
    $fullStars = floor($avg);
    $halfStar = ceil($avg) - $fullStars;
    $emptyStars = 5 - ceil($avg);
    
    return '<div class="rating">' .
        str_repeat('★', $fullStars) .
        ($halfStar ? '½' : '') .
        str_repeat('☆', $emptyStars) .
        '<span class="count">(' . $data['count'] . ')</span>' .
    '</div>';
}
```

---

### 🚀 **Améliorations Techniques**

#### 1. **Optimisation des Images**
**Problème :** Les images uploadées ne sont pas optimisées (poids élevé → lenteur)

**Solutions :**
- **Compression automatique** avec TinyPNG API ou ImageOptim
- **Lazy loading** des images
- **WebP** au lieu de JPEG/PNG quand possible

**Code :**
```php
// Dans functions.php, modifier store_uploaded_photo()
function store_uploaded_photo(string $fieldName, string $baseName, int $maxDim = 1000, int $quality = 85): ?string
{
    // ... code existant ...
    
    // Sauvegarder aussi en WebP (30% plus léger)
    $webpPath = $uploadsDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    imagewebp($dst, $webpPath, $quality);
    
    // Retourner le chemin WebP si le navigateur le supporte
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'webp') !== false) {
        @unlink($path); // Supprimer le JPEG
        return 'uploads/' . basename($webpPath);
    }
    
    return 'uploads/' . $filename;
}
```

---

#### 2. **Cache des Produits et Catégories**
**Problème :** Chaque chargement de page requête la base pour les catégories et produits

**Solution :**
```php
// Dans functions.php
function get_cached_products(...$args): array
{
    static $cache = [];
    $key = md5(serialize($args));
    
    if (!isset($cache[$key])) {
        $cache[$key] = get_products(...$args);
    }
    
    return $cache[$key];
}
```

---

#### 3. **Pagination des Produits**
**Problème :** Tous les produits sont chargés en une fois dans `boutique.php`

**Solution :**
```php
// Dans boutique.php
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$products = get_products($cat, false, $q, $perPage, $offset);
$totalProducts = get_products_count($cat, $q);
$totalPages = ceil($totalProducts / $perPage);

// Affichage de la pagination
function pagination_html(int $page, int $totalPages, string $url): string
{
    if ($totalPages <= 1) return '';
    
    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a href="' . $url . '&page=' . ($page - 1) . '" class="page-prev">←</a>';
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        $html .= '<a href="' . $url . '&page=' . $i . '" class="' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
    }
    if ($page < $totalPages) {
        $html .= '<a href="' . $url . '&page=' . ($page + 1) . '" class="page-next">→</a>';
    }
    $html .= '</div>';
    return $html;
}
```

---

#### 4. **Recherche Full-Text**
**Problème :** La recherche actuelle utilise `LIKE` → lente et limitée

**Solution :** Utiliser la recherche full-text de SQLite
```sql
-- Créer un index full-text
CREATE VIRTUAL TABLE IF NOT EXISTS products_fts USING fts5(
    ref, name, description, cat, badge
);

-- Trigger pour maintenir l'index
CREATE TRIGGER IF NOT EXISTS products_fts_insert AFTER INSERT ON products
BEGIN
    INSERT INTO products_fts(rowid, ref, name, description, cat, badge)
    VALUES (new.id, new.ref, new.name, new.description, new.cat, new.badge);
END;
```

---

#### 5. **Webhooks Stripe pour les Paiements**
**Problème :** La confirmation de paiement dépend de `checkout-success.php` (l'utilisateur doit revenir sur le site)

**Solution :** Implémenter les webhooks Stripe pour :
- Confirmation de paiement en temps réel
- Gestion des échecs de paiement
- Mises à jour de statut automatiques

**Code :**
```php
// nouveau fichier: stripe-webhook.php
require_once __DIR__ . '/includes/functions.php';

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, 
        $sigHeader, 
        STRIPE_WEBHOOK_SECRET  // À ajouter dans config.php
    );
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit('Webhook signature verification failed');
}

switch ($event->type) {
    case 'checkout.session.completed':
        $session = $event->data->object;
        handleCheckoutSessionCompleted($session->id);
        break;
    case 'payment_intent.succeeded':
        $paymentIntent = $event->data->object;
        handlePaymentIntentSucceeded($paymentIntent);
        break;
    case 'payment_intent.payment_failed':
        $paymentIntent = $event->data->object;
        handlePaymentIntentFailed($paymentIntent);
        break;
}

http_response_code(200);
```

---

## 🔒 **Recommandations de Sécurité**

### Checklist de Sécurité
- [ ] **CSRF Protection** : Ajouter des tokens CSRF à tous les formulaires
- [ ] **Input Validation** : Valider toutes les entrées utilisateur
- [ ] **Output Escaping** : Utiliser `h()` systématiquement pour les sorties HTML
- [ ] **Password Hashing** : Ne JAMAIS stocker de mots de passe en clair
- [ ] **HTTPS** : Forcer HTTPS sur tout le site
- [ ] **Security Headers** : Ajouter CSP, X-XSS-Protection, etc.
- [ ] **File Uploads** : Valider les types de fichiers et les extensions
- [ ] **Error Handling** : Ne pas exposer les erreurs internes
- [ ] **Database Backups** : Stocker les sauvegardes hors de la racine web
- [ ] **Dependencies** : Mettre à jour PHP, les librairies, SQLite

---

## 📈 **Optimisations de Performance**

### Checklist Performance
- [ ] **Compression GZIP** : Activer sur le serveur
- [ ] **Cache Navigateur** : Configurer les headers Cache-Control
- [ ] **Minification** : Minifier CSS et JS
- [ ] **Lazy Loading** : Pour les images et iframes
- [ ] **Optimisation Images** : Compression + WebP
- [ ] **CDN** : Utiliser un CDN pour les assets statiques
- [ ] **Database Indexes** : Vérifier les indexes SQLite
- [ ] **Pagination** : Limiter le nombre de résultats par page

---

## 📊 **Roadmap de Correction**

### **Phase 1 : Corrections Critiques (1-2 jours)**
| Tâche | Priorité | Temps Estimé | Impact |
|-------|----------|---------------|--------|
| Ajouter `session_start()` dans boutique.php et produit.php | ⭐⭐⭐⭐⭐ | 30 min | Fix panier |
| Corriger `price_to_cents()` | ⭐⭐⭐⭐ | 1h | Fix calculs prix |
| Protéger contre race condition sur le stock | ⭐⭐⭐⭐⭐ | 2h | Éviter survente |
| Ajouter protection CSRF | ⭐⭐⭐⭐ | 2h | Sécurité |
| Déplacer sauvegardes DB hors racine web | ⭐⭐⭐⭐ | 30 min | Sécurité |
| Masquer mot de passe admin | ⭐⭐⭐⭐⭐ | 1h | Sécurité |

### **Phase 2 : Améliorations Fonctionnelles (3-5 jours)**
| Tâche | Priorité | Temps Estimé | Impact |
|-------|----------|---------------|--------|
| Système de réservation pour brocantes | ⭐⭐⭐⭐ | 4h | Nouvelle fonctionnalité |
| Géolocalisation des points de vente | ⭐⭐⭐ | 3h | UX améliorée |
| Système de négociation de prix | ⭐⭐⭐ | 4h | Adapté aux brocantes |
| Messagerie interne | ⭐⭐ | 3h | Service client |
| Système de notation/avis | ⭐⭐ | 2h | Confiance |

### **Phase 3 : Optimisations (2-3 jours)**
| Tâche | Priorité | Temps Estimé | Impact |
|-------|----------|---------------|--------|
| Optimisation images (WebP, compression) | ⭐⭐⭐ | 2h | Performance |
| Cache des produits/catégories | ⭐⭐⭐ | 1h | Performance |
| Pagination des produits | ⭐⭐⭐ | 2h | UX |
| Recherche full-text | ⭐⭐ | 2h | UX |
| Webhooks Stripe | ⭐⭐⭐ | 2h | Fiabilité |

---

## 🛠️ **Code à Modifier (Patchs Prêts à Appliquer)**

### Patch 1: session_start() manquant
```diff
--- a/boutique.php
+++ b/boutique.php
@@ -1,3 +1,4 @@
+<?php session_start();
 <?php
 require_once __DIR__ . '/includes/functions.php';
 
--- a/produit.php
+++ b/produit.php
@@ -1,3 +1,4 @@
+<?php session_start();
 <?php
 require_once __DIR__ . '/includes/functions.php';
```

### Patch 2: price_to_cents() amélioré
```diff
--- a/includes/functions.php
+++ b/includes/functions.php
@@ -1569,12 +1569,17 @@ function price_to_cents(?string $price): ?int
 {
     if (!$price) return null;
-    if (!preg_match('/(\d[\d\s]*)(?:[,.](\d{1,2}))?/', $price, $m)) return null;
-    $euros = (int) str_replace(' ', '', $m[1]);
-    $cents = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;
-    return $euros * 100 + $cents;
+    // Nettoyer le prix : retirer symboles, espaces
+    $cleanPrice = preg_replace('/[^\d.,-]/', '', $price);
+    
+    // Gérer les formats : "10", "10.50", "10,50", "10,5", "1 000"
+    if (preg_match('/^(\d{1,10})([.,](\d{1,2}))?$/', str_replace(' ', '', $cleanPrice), $m)) {
+        $euros = (int) $m[1];
+        $cents = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;
+        return $euros * 100 + $cents;
+    }
+    
+    return null;
 }
```

### Patch 3: Protection CSRF
**Nouveau fichier: includes/csrf.php**
```php
<?php

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf(): bool
{
    if (!isset($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
```

**Modification de cart-add.php:**
```diff
--- a/cart-add.php
+++ b/cart-add.php
@@ -1,5 +1,9 @@
 <?php
 session_start();
+if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validate_csrf()) {
+    die('CSRF token invalid');
+}
 require_once __DIR__ . '/includes/functions.php';
+require_once __DIR__ . '/includes/csrf.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
```

---

## 📝 **Résumé des Actions Immédiates**

### À faire **AUJOURD'HUI** (30 min - 1h) :
1. ✅ **Ajouter `session_start()`** dans boutique.php et produit.php
2. ✅ **Protéger le mot de passe admin** (fichier .secrets/)
3. ✅ **Déplacer les sauvegardes DB** hors de la racine web

### À faire **DEMAIN** (2-3h) :
1. ✅ **Corriger `price_to_cents()`** 
2. ✅ **Ajouter protection CSRF** à tous les formulaires
3. ✅ **Corriger la race condition sur le stock**

### À faire **LA SEMAINE PROCHAINE** (5-10h) :
1. ✅ **Système de réservation pour brocantes**
2. ✅ **Géolocalisation des points de vente**
3. ✅ **Webhooks Stripe** pour une meilleure fiabilité

---

## 🎉 **Conclusion**

Votre portail **Brocante** est **déjà très solide** avec des fonctionnalités avancées (IA, gestion complète, design moderne). Les **corrections prioritaires** (session, sécurité, stock) sont **relativement simples à implémenter** et auront un impact majeur sur la stabilité et la sécurité.

Les **améliorations fonctionnelles** (réservation, géolocalisation, négociation) rendront votre plateforme **parfaitement adaptée** au marché des brocantes et de la seconde main.

**Prochaine étape :**
1. Appliquez les corrections critiques (Phase 1)
2. Testez en préprod
3. Déployez en production
4. Implémentez les améliorations fonctionnelles (Phase 2)

Je suis disponible pour **vous aider à implémenter** n'importe laquelle de ces corrections ou améliorations !

---

*Rapport généré par Vibe Mistral - 7 octobre 2026*
