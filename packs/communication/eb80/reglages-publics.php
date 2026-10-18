<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * Réglages exposables au public — règle unique pour tout le site.
 *
 * La table `settings` range, à côté des textes et images du site, des secrets
 * véritables : clé secrète Stripe, mot de passe SMTP, jetons et secrets Meta,
 * TikTok, WhatsApp, Google… Trois routes la recopiaient vers le navigateur de
 * n'importe quel visiteur : /api/settings, /api/pub et le bloc EB80_PREFETCH de
 * l'accueil. /api/pub filtrait déjà, mais par une liste de noms — incomplète,
 * comme toute liste qu'il faut penser à tenir à jour.
 *
 * On exclut donc par MOTIFS : tout nom évoquant un secret est retenu, y compris
 * ceux qui n'existent pas encore. Une liste blanche serait plus stricte encore,
 * mais une dizaine de pages publiques (boutique, calendrier, pro…) lisent les
 * réglages : en recenser toutes les lectures sans en oublier aucune ferait
 * courir le risque de casser le site.
 *
 * L'administration reçoit la table entière, jeton à l'appui (voir api/settings.php).
 */

/** Noms évoquant un secret. Vérifié contre les 319 clés de production. */
const EB80_MOTIF_SECRET =
    '/(secret|token|passw|pwd|_pass$|^smtp_|api_?key|apikey|_key$|private|credential|salt|hash|webhook|^twilio_(sid|token|auth))/i';

/** Publiques par nature, bien qu'elles correspondent au motif : la boutique les lit. */
const EB80_CLES_PUBLIQUES_FORCEES = ['stripe_public_key', 'stripe_publishable_key'];

/** Une clé peut-elle sortir vers le navigateur d'un visiteur ? */
function eb80_reglage_est_public(string $cle): bool {
    if (in_array($cle, EB80_CLES_PUBLIQUES_FORCEES, true)) return true;
    return !preg_match(EB80_MOTIF_SECRET, $cle);
}

/** Ne garde, d'un tableau clé → valeur, que ce qui peut être rendu public. */
function eb80_reglages_publics(array $reglages): array {
    return array_filter($reglages, fn($cle) => eb80_reglage_est_public((string) $cle), ARRAY_FILTER_USE_KEY);
}
