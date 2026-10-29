<?php
// Tables de correspondance entre les champs de la boutique et ceux de Leboncoin / Vinted.
// Tables créées à la demande (pas de migration : une migration mettrait le plugin « en retard » jusqu'à la mise à jour du portail).
defined('PLUGIN_RUNTIME') || exit;

const AM_MAP_CHANNELS = ['leboncoin' => 'Leboncoin', 'vinted' => 'Vinted'];

/** Champs gérés : clé => [libellé, aide sur la valeur d'origine]. « autre » = champ propre à la place de marché, sans équivalent boutique. */
function am_map_fields(): array
{
    return [
        'etat' => ['État', 'État de la pièce (liste de la boutique)'],
        'categorie' => ['Catégorie / nature', 'Sous-catégorie ou nature de la pièce'],
        'taille' => ['Taille', 'Texte de la taille (ex. M, 38, 40 cm)'],
        'couleur' => ['Couleur', 'Une couleur de la pièce (ex. kaki)'],
        'marque' => ['Marque', 'Marque de la pièce'],
        'matiere' => ['Matière', 'Une matière de la pièce (ex. coton)'],
        'autre' => ['Autre champ', 'Champ du site sans équivalent boutique : valeur par défaut'],
    ];
}

function am_map_ensure(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS am_mappings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      channel TEXT NOT NULL,
      field TEXT NOT NULL,
      source_norm TEXT NOT NULL,
      source_label TEXT NOT NULL DEFAULT '',
      target TEXT NOT NULL,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (channel, field, source_norm)
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS am_unknown_fields (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      channel TEXT NOT NULL,
      field_label TEXT NOT NULL,
      field_type TEXT NOT NULL DEFAULT 'autre',
      source_value TEXT NOT NULL DEFAULT '',
      product_ref TEXT NOT NULL DEFAULT '',
      options TEXT NOT NULL DEFAULT '[]',
      seen INTEGER NOT NULL DEFAULT 1,
      status TEXT NOT NULL DEFAULT 'open',
      last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (channel, field_label, source_value)
    )");
    $done = true;
}

/** Minuscules, sans accents ni ponctuation (même règle que le bot). */
function am_map_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== '') $s = $t;
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Morceaux d'une liste « kaki, beige / noir ». */
function am_map_split(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\/]+/', $s) ?: []), static fn ($x) => $x !== ''));
}

/** Toutes les correspondances : [canal][champ][source_norm] => cible. */
function am_map_load(): array
{
    am_map_ensure();
    $out = [];
    foreach (db()->query('SELECT channel, field, source_norm, target FROM am_mappings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['channel']][$r['field']][$r['source_norm']] = $r['target'];
    }
    return $out;
}

/** Correspondances d'une pièce pour chaque canal : ['leboncoin' => ['etat' => '…', 'couleur' => ['…'], 'autre' => ['label norm' => '…']]]. */
function am_map_payload(array $p, ?array $all = null): array
{
    $all ??= am_map_load();
    $out = [];
    foreach (array_keys(AM_MAP_CHANNELS) as $ch) {
        $rows = $all[$ch] ?? [];
        if (!$rows) continue;
        $m = [];
        $etat = (string) ($p['etat'] ?? '');
        if ($etat !== '' && isset($rows['etat'][$etat])) $m['etat'] = $rows['etat'][$etat];   // clé d'état telle quelle (bon_age)
        foreach (['subcategory_label', 'nature_label'] as $k) {
            $v = (string) ($p[$k] ?? '');
            if ($v !== '' && isset($rows['categorie'][am_map_norm($v)])) { $m['categorie'] = $rows['categorie'][am_map_norm($v)]; break; }
        }
        foreach (['taille' => 'size_text', 'marque' => 'brand'] as $f => $k) {
            $v = (string) ($p[$k] ?? '');
            if ($v !== '' && isset($rows[$f][am_map_norm($v)])) $m[$f] = $rows[$f][am_map_norm($v)];
        }
        foreach (['couleur' => 'colors', 'matiere' => 'materials'] as $f => $k) {
            $found = [];
            foreach (am_map_split((string) ($p[$k] ?? '')) as $piece) {
                $n = am_map_norm($piece);
                if (isset($rows[$f][$n])) $found[] = $rows[$f][$n];
            }
            if ($found) $m[$f] = array_values(array_unique($found));
        }
        foreach ($rows as $field => $list) {
            if (str_starts_with($field, 'autre:') && isset($list['*'])) $m['autre'][substr($field, 6)] = $list['*'];
        }
        if ($m) $out[$ch] = $m;
    }
    return $out;
}

/** Valeurs d'origine proposées pour chaque champ (d'après le catalogue) : champ => [valeur, …]. */
function am_map_suggestions(): array
{
    $sug = ['etat' => [], 'categorie' => [], 'taille' => [], 'couleur' => [], 'marque' => [], 'matiere' => [], 'autre' => []];
    foreach (function_exists('product_condition_options') ? product_condition_options() : [] as $group) {
        foreach ($group as $key => [$label]) $sug['etat'][$key] = $label;
    }
    $add = static function (string $f, string $v) use (&$sug): void {
        $v = trim($v);
        if ($v !== '') $sug[$f][am_map_norm($v)] = $v;
    };
    foreach (db()->query('SELECT * FROM products WHERE is_hidden = 0')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $labels = function_exists('product_nature_labels') ? product_nature_labels($p['nature'] ?? null, $p['sous_categorie'] ?? null) : null;
        $add('categorie', (string) ($labels[0] ?? ''));
        $add('categorie', (string) ($labels[1] ?? ''));
        $add('taille', (string) ($p['size_text'] ?? ''));
        $add('marque', (string) ($p['brand'] ?? ''));
        foreach (am_map_split((string) ($p['colors'] ?? '')) as $c) $add('couleur', $c);
        foreach (am_map_split((string) ($p['materials'] ?? '')) as $c) $add('matiere', $c);
    }
    foreach ($sug as $f => $list) {
        if ($f === 'etat') continue;
        $vals = array_values($list);
        sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
        $sug[$f] = $vals;
    }
    $sug['etat'] = array_map(static fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($sug['etat']), array_values($sug['etat']));
    return $sug;
}

/** Enregistre (ou met à jour) une correspondance. -> message d'erreur ou null. */
function am_map_save(string $channel, string $field, string $source, string $target, string $label = ''): ?string
{
    am_map_ensure();
    if (!isset(AM_MAP_CHANNELS[$channel])) return 'Canal inconnu.';
    $fields = am_map_fields();
    $base = explode(':', $field, 2)[0];
    if (!isset($fields[$base])) return 'Champ inconnu.';
    $target = mb_substr(trim($target), 0, 160);
    if ($target === '') return 'Valeur du site manquante.';
    if ($base === 'autre') {
        $name = am_map_norm(substr($field, 6));
        if ($name === '') return 'Nom du champ manquant.';
        $field = 'autre:' . $name;
        $sourceNorm = '*';
        $label = '(valeur par défaut)';
    } else {
        $field = $base;
        $sourceNorm = $base === 'etat' ? trim($source) : am_map_norm($source);
        if ($sourceNorm === '') return 'Valeur de la boutique manquante.';
        $label = $label !== '' ? $label : trim($source);
    }
    db()->prepare('INSERT INTO am_mappings (channel, field, source_norm, source_label, target) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(channel, field, source_norm) DO UPDATE SET target = excluded.target, source_label = excluded.source_label, updated_at = CURRENT_TIMESTAMP')
        ->execute([$channel, $field, $sourceNorm, mb_substr($label, 0, 160), $target]);
    // Ce que le bot avait signalé comme inconnu pour cette valeur est réglé.
    $del = db()->prepare('DELETE FROM am_unknown_fields WHERE id = ?');
    foreach (db()->query('SELECT id, field_label, field_type, source_value FROM am_unknown_fields WHERE channel = ' . db()->quote($channel))->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $same = $base === 'autre'
            ? am_map_norm($u['field_label']) === substr($field, 6)
            : $u['field_type'] === $base && am_map_norm($u['source_value']) === am_map_norm($source);
        if ($same) $del->execute([$u['id']]);
    }
    return null;
}

/** Le bot signale des champs / valeurs qu'il n'a pas su remplir. -> nombre enregistré. */
function am_unknown_record(string $channel, array $items): int
{
    am_map_ensure();
    if (!isset(AM_MAP_CHANNELS[$channel])) return 0;
    $n = 0;
    $stmt = db()->prepare("INSERT INTO am_unknown_fields (channel, field_label, field_type, source_value, product_ref, options)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(channel, field_label, source_value) DO UPDATE SET seen = seen + 1, last_seen = CURRENT_TIMESTAMP,
          product_ref = excluded.product_ref, options = excluded.options,
          status = CASE WHEN status = 'ignored' THEN 'ignored' ELSE 'open' END");
    foreach (array_slice($items, 0, 60) as $it) {
        if (!is_array($it)) continue;
        $label = mb_substr(trim((string) ($it['field_label'] ?? '')), 0, 120);
        if ($label === '') continue;
        $type = (string) ($it['field_type'] ?? 'autre');
        if (!isset(am_map_fields()[$type])) $type = 'autre';
        $options = array_slice(array_map(static fn ($o) => mb_substr((string) $o, 0, 80), (array) ($it['options'] ?? [])), 0, 80);
        $stmt->execute([$channel, $label, $type, mb_substr(trim((string) ($it['source_value'] ?? '')), 0, 160),
            mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($it['product_ref'] ?? '')), 0, 40),
            json_encode(array_values($options), JSON_UNESCAPED_UNICODE)]);
        $n++;
    }
    return $n;
}
