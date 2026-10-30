<?php
// Onglets des deux parcours de prise de vue : les pièces à vendre (assistant de
// shooting) et le lieu (boutique, stand, atelier). Le menu ne garde qu'un écran
// « Shooting » ; ces onglets font passer de l'un à l'autre. Un photographe n'a
// que le parcours du lieu : les onglets disparaissent alors (un seul choix).

function shooting_tabs_html(string $role, string $current): string
{
    $tabs = [
        'assistant' => ['/admin/shooting-assistant.php', 'Pièces à vendre'],
        'shooting-lieu' => ['/admin/shop-shooting.php', 'Boutique / stand'],
    ];
    $tabs = array_filter($tabs, static fn (array $tab): bool => admin_role_can_access($role, $tab[0]));
    if (count($tabs) < 2) return '';
    $html = '<nav class="shoot-tabs" aria-label="Parcours de prise de vue">';
    foreach ($tabs as $key => [$href, $label]) {
        $html .= '<a href="' . h($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
}
