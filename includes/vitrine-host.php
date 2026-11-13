<?php

// Vitrines par nom de domaine : certains domaines (aux-puces.com…) ouvrent à leur racine une page de présentation dédiée plutôt que
// l'accueil de la plateforme. La correspondance est ici, au même endroit que celle des domaines de galerie (gallery_from_host).
// Ne concerne que la racine : les autres adresses du domaine (redirections de galeries ou de boutiques) restent gérées en amont
// (Cloudflare) ou par le routage habituel.

/** Domaine (sans « www. », en minuscules) => page de la vitrine, relative à la racine du projet. */
const VITRINE_HOSTS = [
    'aux-puces.com' => 'aux-puces/index.php',
];

/** Domaine d'une requête : en minuscules, sans port ni « www. ». */
function vitrine_host_normalize(string $host): string
{
    $host = strtolower(trim($host));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return (string) preg_replace('/^www\./', '', $host);
}

/** Page de vitrine d'un domaine (chemin relatif à la racine), ou null si le domaine n'en a pas. */
function vitrine_page_for_host(string $host): ?string
{
    return VITRINE_HOSTS[vitrine_host_normalize($host)] ?? null;
}
