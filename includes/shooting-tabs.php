<?php
// Onglets des deux parcours de prise de vue : l'article à vendre (assistant
// de shooting, parcours par défaut) et le reportage boutique (lieu, stand,
// atelier). Le menu ne garde qu'un écran « Shooting » ; ces onglets font
// passer de l'un à l'autre. Un photographe n'a que le reportage boutique :
// les onglets disparaissent alors (un seul choix).

function shooting_tabs_html(string $role, string $current): string
{
    $tabs = [
        'assistant' => ['/admin/shooting-assistant.php', 'Article'],
        'shooting-lieu' => ['/admin/shop-shooting.php', 'Reportage'],
    ];
    $tabs = array_filter($tabs, static fn (array $tab): bool => admin_role_can_access($role, $tab[0]));
    if (count($tabs) < 2) return '';
    $html = '<nav class="shoot-tabs" aria-label="Parcours de prise de vue">';
    foreach ($tabs as $key => [$href, $label]) {
        $html .= '<a href="' . h($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
}
