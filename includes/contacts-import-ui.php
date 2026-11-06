<?php

// Écrans de l'import de contacts (voir includes/contacts-import.php), communs à l'administration d'une boutique et au portail des galeries :
// 1. choix de la source (CSV, Excel, vCard ou CRM), 2. correspondance des colonnes, 3. aperçu et confirmation
// (accord de l'importateur) et résultat. Chaque page fournit sa cible d'écriture et ses contrôles d'accès (jeton CSRF).

require_once __DIR__ . '/contacts-import.php';

/**
 * @param array $cfg [
 *   'url' => adresse de la page, 'csrf_html' => champs cachés du jeton, 'csrf_check' => callable (lève ou quitte si invalide),
 *   'noun' => « les comptes de la boutique », 'exists' => fn(array $contact): ?array, 'create' => fn(array $contact, array $opts): bool,
 *   'complete' => fn(array $existing, array $contact): bool, 'require_email' => bool, 'roles' => ?array (valeur => libellé) proposés à l'import,
 *   'role_label' => string, 'sample_name' => string ]
 * @return string HTML du contenu (la page l'insère dans sa mise en page)
 */
function ci_page(array $cfg): string
{
    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $url = $cfg['url'];
    $self = static fn (array $q = []): string => $url . ($q ? (str_contains($url, '?') ? '&' : '?') . http_build_query($q) : '');
    $error = '';

    // Modèle de fichier à remplir
    if (($_GET['modele'] ?? '') === '1') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modele-contacts.csv"');
        echo "\xEF\xBB\xBF" . "email;prenom;nom;telephone;societe;note\r\nmarie@exemple.fr;Marie;Dupont;06 12 34 56 78;Brocante Marie;Rencontrée au marché\r\n";
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cfg['csrf_check']();
        $act = (string) ($_POST['act'] ?? '');
        try {
            if ($act === 'file') {
                $f = $_FILES['file'] ?? null;
                if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                    throw new InvalidArgumentException('Choisissez un fichier .csv, .vcf, .xls ou .xlsx.');
                }
                if (($f['size'] ?? 0) > CI_MAX_BYTES) throw new InvalidArgumentException('Fichier trop volumineux (10 Mo au plus).');
                $filename = basename((string) ($f['name'] ?? ''));
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($extension, ['csv', 'txt', 'vcf', 'xls', 'xlsx'], true)) {
                    throw new InvalidArgumentException('Format non pris en charge : choisissez un fichier CSV, VCF, XLS ou XLSX.');
                }
                $text = in_array($extension, ['xls', 'xlsx'], true) ? '' : ci_decode((string) file_get_contents($f['tmp_name']));
                $isVcf = (bool) preg_match('/BEGIN:VCARD/i', $text);
                if ($isVcf) {
                    $contacts = ci_contacts_from_vcf($text);
                    $stash = ['kind' => 'vcf', 'source' => 'Carnet vCard · ' . mb_substr($filename, 0, 60), 'contacts' => $contacts, 'preset' => 'vCard'];
                } else {
                    [$headers, $rows] = in_array($extension, ['xls', 'xlsx'], true)
                        ? ci_parse_spreadsheet((string) $f['tmp_name'])
                        : ci_parse_csv($text);
                    if (!$headers || !$rows) throw new InvalidArgumentException('Fichier vide ou illisible : vérifiez que la première ligne contient les noms des colonnes.');
                    [$map, $preset] = ci_guess_mapping($headers);
                    $stash = ['kind' => 'csv', 'source' => 'Fichier · ' . mb_substr($filename, 0, 60), 'headers' => $headers, 'rows' => array_slice($rows, 0, CI_MAX_ROWS), 'map' => $map, 'preset' => $preset];
                }
                if (empty($stash['contacts']) && $stash['kind'] === 'vcf') throw new InvalidArgumentException('Aucun contact dans ce carnet.');
                header('Location: ' . $self(['i' => ci_stash($stash)]));
                exit;
            }
            if ($act === 'crm') {
                $crm = (string) ($_POST['crm'] ?? '');
                if (!isset(CI_CRMS[$crm])) throw new InvalidArgumentException('Choisissez un CRM.');
                $raw = ci_fetch_crm($crm, (string) ($_POST['secret'] ?? ''), trim((string) ($_POST['extra'] ?? '')));
                if (!$raw) throw new InvalidArgumentException('Aucun contact trouvé dans ce CRM.');
                header('Location: ' . $self(['i' => ci_stash(['kind' => 'crm', 'source' => 'CRM · ' . CI_CRMS[$crm][0], 'contacts' => ci_contacts_from_raw($raw), 'preset' => CI_CRMS[$crm][0]])]));
                exit;
            }
            if ($act === 'remap') {
                $st = ci_unstash((string) ($_POST['i'] ?? ''));
                if (!$st || $st['kind'] !== 'csv') throw new InvalidArgumentException('Import expiré : recommencez.');
                $map = [];
                foreach (CI_FIELDS as $fk => $_) {
                    $v = $_POST['map'][$fk] ?? '-1';
                    $map[$fk] = is_scalar($v) && is_numeric($v) && (int) $v >= 0 && (int) $v < count($st['headers']) ? (int) $v : -1;
                }
                $customInput = is_array($_POST['custom'] ?? null) ? $_POST['custom'] : [];
                $custom = [];
                foreach ($customInput as $column => $field) {
                    if (!is_numeric($column) || (int) $column < 0 || (int) $column >= count($st['headers']) || !is_array($field)) continue;
                    if (empty($field['enabled'])) continue;
                    $label = is_scalar($field['label'] ?? null) ? trim((string) $field['label']) : '';
                    if ($label === '') continue;
                    $custom[(int) $column] = ['label' => mb_substr($label, 0, 60)];
                }
                if (!empty($cfg['allow_custom_fields'])) $map['custom'] = $custom;
                $st['map'] = $map;
                $id = (string) $_POST['i'];
                file_put_contents(ci_dir() . '/' . $id . '.json', json_encode($st, JSON_UNESCAPED_UNICODE));
                header('Location: ' . $self(['i' => $id]));
                exit;
            }
            if ($act === 'cancel') {
                ci_forget((string) ($_POST['i'] ?? ''));
                header('Location: ' . $url);
                exit;
            }
            if ($act === 'import') {
                $id = (string) ($_POST['i'] ?? '');
                $st = ci_unstash($id);
                if (!$st) throw new InvalidArgumentException('Import expiré : recommencez.');
                if (empty($_POST['consent'])) { $error = "Cochez la confirmation : vous devez avoir le droit de contacter ces personnes."; goto preview; }
                $contacts = ci_import_contacts($st, $cfg);
                $role = isset($cfg['roles'][(string) ($_POST['role'] ?? '')]) ? (string) $_POST['role'] : (string) array_key_first($cfg['roles'] ?? ['' => '']);
                $opts = ['role' => $role, 'source' => (string) $st['source']];
                $res = ci_apply($contacts, ['exists' => $cfg['exists'], 'create' => static fn ($c) => $cfg['create']($c, $opts), 'complete' => $cfg['complete']], !empty($_POST['complete']));
                ci_forget($id);
                return ci_result_html($res, $cfg, $h);
            }
        } catch (Throwable $e) {
            $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : "L'import a échoué : " . $e->getMessage();
        }
    }

    preview:
    $id = (string) ($_GET['i'] ?? $_POST['i'] ?? '');
    $st = $id !== '' ? ci_unstash($id) : null;
    if ($st) return ci_preview_html($st, $id, $cfg, $h, $error);

    // Écran 1 : choix de la source
    ob_start();
    ?>
    <style>
      .ci { display: grid; gap: 16px; max-width: 980px; } .ci-card { border: 1px solid var(--line, #ddd); border-radius: 12px; padding: 16px; display: grid; gap: 10px; background: var(--panel, transparent); }
      .ci-card h2 { margin: 0; font-size: 1.05rem; } .ci-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
      .ci label { display: grid; gap: 4px; font-size: .85rem; font-weight: 600; } .ci input[type=text], .ci input[type=password], .ci input[type=file], .ci select { padding: 9px 10px; border: 1px solid var(--line, #ccc); border-radius: 8px; font: inherit; width: 100%; background: transparent; color: inherit; }
      .ci .hint { font-size: .82rem; color: var(--ink-soft, #777); margin: 0; } .ci .err { color: #b3261e; font-weight: 600; margin: 0; } .ci table { width: 100%; border-collapse: collapse; font-size: .88rem; } .ci td, .ci th { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line, #ddd); vertical-align: top; }
      .ci .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 600; background: rgba(0,0,0,.07); } .ci .tag.ok { background: #dff3e6; color: #17653a; } .ci .tag.warn { background: #fdecc8; color: #7a5200; } .ci .tag.no { background: #f8d9d6; color: #8a1c13; }
      .ci .kpis { display: flex; gap: 10px; flex-wrap: wrap; } .ci .kpi { border: 1px solid var(--line, #ddd); border-radius: 10px; padding: 8px 14px; min-width: 120px; } .ci .kpi b { display: block; font-size: 1.4rem; }
      .ci details { border: 1px solid var(--line, #ddd); border-radius: 10px; padding: 8px 12px; } .ci summary { cursor: pointer; font-weight: 600; }
    </style>
    <div class="ci">
      <?php if ($error): ?><p class="err" role="alert"><?= $h($error) ?></p><?php endif; ?>
      <p class="hint">Ajoutez des prospects ou des clients à <?= $h($cfg['noun']) ?> à partir d'un fichier ou de votre CRM. Une fiche par adresse : les contacts déjà présents ne sont jamais dupliqués, les désinscrits des CRM sont ignorés et les numéros français sont mis au format international.</p>
      <div class="ci-cols">
        <form class="ci-card" method="post" enctype="multipart/form-data"><?= $cfg['csrf_html'] ?><input type="hidden" name="act" value="file">
          <h2>1. Un fichier</h2>
          <p class="hint">CSV, carnet d'adresses <strong>.vcf</strong>, ou classeur Excel <strong>.xls/.xlsx</strong>. Les colonnes reconnues sont proposées automatiquement ; vous pouvez les faire correspondre ou créer un champ personnalisé (ex. site web).</p>
          <label>Fichier (10 Mo, 5 000 contacts et 200 colonnes au plus)<input type="file" name="file" accept=".csv,.txt,.vcf,.xls,.xlsx,text/csv,text/vcard,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
          <p><button class="btn btn-primary" type="submit">Analyser le fichier</button> <a href="<?= $h($self(['modele' => '1'])) ?>">Télécharger un modèle CSV</a></p>
        </form>
        <form class="ci-card" method="post" autocomplete="off"><?= $cfg['csrf_html'] ?><input type="hidden" name="act" value="crm">
          <h2>2. Depuis votre CRM</h2>
          <label>CRM<select name="crm" id="ci-crm"><?php foreach (CI_CRMS as $k => [$label]): ?><option value="<?= $h($k) ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label>
          <label>Clé API ou jeton<input type="password" name="secret" required autocomplete="off" placeholder="Collez la clé ici"></label>
          <label>Identifiant d'audience (Mailchimp, facultatif)<input type="text" name="extra" maxlength="20" autocomplete="off"></label>
          <p class="hint" id="ci-crm-help"><?= $h(CI_CRMS['hubspot'][1]) ?></p>
          <p class="hint">La clé sert uniquement à lire vos contacts, le temps de cette étape : elle n'est ni enregistrée ni conservée. Vous pourrez la révoquer ensuite dans votre CRM.</p>
          <p><button class="btn btn-primary" type="submit">Lire les contacts du CRM</button></p>
        </form>
      </div>
      <details><summary>Zoho, Google Contacts, Outlook, Odoo, Salesforce, téléphone…</summary>
        <p class="hint" style="margin-top:8px;">Ces outils exportent leurs contacts en <strong>CSV</strong> (ou <strong>vCard</strong>) : exportez, puis déposez le fichier à l'étape 1. Les en-têtes de ces exports sont reconnus (Zoho CRM : Contacts → Exporter ; Google Contacts : Exporter → Google CSV ; Outlook : Fichier → Exporter ; Odoo : Contacts → Exporter ; Salesforce : Rapport → Exporter ; iPhone/Android : exporter les contacts en .vcf).</p></details>
    </div>
    <script>
    (function(){var s=document.getElementById('ci-crm'),h=document.getElementById('ci-crm-help'),t=<?= json_encode(array_map(static fn ($c) => $c[1], CI_CRMS), JSON_UNESCAPED_UNICODE) ?>;if(s&&h)s.addEventListener('change',function(){h.textContent=t[s.value]||''})})();
    </script>
    <?php
    return (string) ob_get_clean();
}

/** Contacts d'un import en attente, prêts à écrire : colonnes appliquées, e-mail exigé si la cible l'impose. */
function ci_import_contacts(array $st, array $cfg): array
{
    $contacts = $st['kind'] === 'csv' ? ci_contacts_from_rows($st['rows'], $st['map']) : $st['contacts'];
    if (!empty($cfg['require_email'])) foreach ($contacts as $k => $c) if ($c['skip'] === '' && $c['email'] === '') $contacts[$k]['skip'] = 'e-mail requis (une fiche a une adresse e-mail)';
    return $contacts;
}

function ci_preview_html(array $st, string $id, array $cfg, callable $h, string $error): string
{
    $contacts = ci_import_contacts($st, $cfg);
    $newN = 0;
    $existN = 0;
    $skips = [];
    $rowsHtml = '';
    $shown = 0;
    foreach ($contacts as $c) {
        if ($c['skip'] !== '') { $skips[$c['skip']] = ($skips[$c['skip']] ?? 0) + 1; $tag = '<span class="tag no">' . $h($c['skip']) . '</span>'; }
        elseif ($cfg['exists']($c)) { $existN++; $tag = '<span class="tag warn">déjà présent</span>'; }
        else { $newN++; $tag = '<span class="tag ok">nouveau</span>'; }
        if ($shown < 15) { $shown++; $rowsHtml .= '<tr><td>' . $h($c['email'] ?: '—') . '</td><td>' . $h($c['name'] ?: '—') . '</td><td>' . $h($c['phone'] ?: '—') . '</td><td>' . $h($c['company']) . '</td><td>' . $tag . '</td></tr>'; }
    }
    $skipN = array_sum($skips);
    $url = $cfg['url'];
    ob_start();
    ?>
    <div class="ci">
      <?php if ($error): ?><p class="err" role="alert"><?= $h($error) ?></p><?php endif; ?>
      <div class="ci-card">
        <h2>Aperçu : <?= $h($st['source']) ?><?= !empty($st['preset']) ? ' <span class="tag ok">' . $h($st['preset']) . ' reconnu</span>' : '' ?></h2>
        <div class="kpis">
          <div class="kpi"><b><?= (int) $newN ?></b>à créer</div><div class="kpi"><b><?= (int) $existN ?></b>déjà présents</div><div class="kpi"><b><?= (int) $skipN ?></b>écartés</div><div class="kpi"><b><?= count($contacts) ?></b>lignes lues</div>
        </div>
        <?php if ($st['kind'] === 'csv' && ($st['map']['email'] ?? -1) < 0 && ($st['map']['phone'] ?? -1) < 0): ?><p class="err" role="alert">Aucune colonne e-mail ou téléphone reconnue : indiquez-les ci-dessous (« Les colonnes sont-elles bien reconnues ? »).</p><?php endif; ?>
        <?php if ($skips): ?><p class="hint">Écartés : <?php foreach ($skips as $motif => $n): ?><?= (int) $n ?> × <?= $h($motif) ?> · <?php endforeach; ?></p><?php endif; ?>
        <div style="overflow-x:auto;"><table><thead><tr><th>E-mail</th><th>Nom</th><th>Téléphone</th><th>Société</th><th>État</th></tr></thead><tbody><?= $rowsHtml ?></tbody></table></div>
        <?php if (count($contacts) > 15): ?><p class="hint">… et <?= count($contacts) - 15 ?> autres lignes.</p><?php endif; ?>
      </div>

      <?php if ($st['kind'] === 'csv'): ?>
      <details<?= $newN + $existN === 0 ? ' open' : '' ?>><summary>Les colonnes sont-elles bien reconnues ? (modifier)</summary>
        <form method="post" style="display:grid;gap:8px;margin-top:10px;"><?= $cfg['csrf_html'] ?><input type="hidden" name="act" value="remap"><input type="hidden" name="i" value="<?= $h($id) ?>">
          <p class="hint">Associez chaque champ du site à une colonne du fichier. Les colonnes sans champ correspondant peuvent créer un champ personnalisé pour chaque contact.</p>
          <div class="ci-cols" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
            <?php foreach (CI_FIELDS as $fk => $fl): ?>
              <label><?= $h($fl) ?><select name="map[<?= $h($fk) ?>]"><option value="-1">— ignorer —</option>
                <?php foreach ($st['headers'] as $ci => $hd): ?><option value="<?= (int) $ci ?>"<?= (int) ($st['map'][$fk] ?? -1) === $ci ? ' selected' : '' ?>><?= $h($hd !== '' ? $hd : '(colonne ' . ($ci + 1) . ')') ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($cfg['allow_custom_fields'])): ?>
          <h3>Champs personnalisés à créer</h3>
          <div style="overflow-x:auto;"><table><thead><tr><th>Intitulé du fichier</th><th>Créer un champ sur le site</th><th>Nom du champ</th></tr></thead><tbody>
            <?php foreach ($st['headers'] as $ci => $hd):
              $selected = array_filter($st['map'], static fn ($mapped, $key): bool => $key !== 'custom' && is_numeric($mapped) && (int) $mapped === $ci, ARRAY_FILTER_USE_BOTH);
              if ($selected) continue;
              $currentCustom = (array) ($st['map']['custom'][$ci] ?? []);
            ?>
              <tr><td><?= $h($hd !== '' ? $hd : '(colonne ' . ($ci + 1) . ')') ?></td>
                <td><label style="display:flex;align-items:center;gap:6px;font-weight:400;"><input type="checkbox" name="custom[<?= (int) $ci ?>][enabled]" value="1"<?= $currentCustom ? ' checked' : '' ?> style="width:auto"> Créer</label></td>
                <td><input type="text" name="custom[<?= (int) $ci ?>][label]" maxlength="60" value="<?= $h($currentCustom['label'] ?? $hd) ?>" placeholder="ex. Site web"></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
          <p><button class="btn" type="submit">Appliquer ces colonnes</button></p>
        </form>
      </details>
      <?php endif; ?>

      <form class="ci-card" method="post"><?= $cfg['csrf_html'] ?><input type="hidden" name="act" value="import"><input type="hidden" name="i" value="<?= $h($id) ?>">
        <h2>Importer dans <?= $h($cfg['noun']) ?></h2>
        <?php if (!empty($cfg['roles'])): ?>
          <label><?= $h($cfg['role_label'] ?? 'Ajouter comme') ?><select name="role"><?php foreach ($cfg['roles'] as $rv => $rl): ?><option value="<?= $h($rv) ?>"><?= $h($rl) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="complete" value="1" checked style="width:auto;margin-top:3px;"> <span>Compléter les fiches déjà présentes (nom ou téléphone manquants) sans rien écraser</span></label>
        <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="consent" value="1" style="width:auto;margin-top:3px;" required> <span>Je confirme avoir le droit de contacter ces personnes (leur accord ou une relation commerciale existante), et que chacune pourra se désinscrire à tout moment.</span></label>
        <p><button class="btn btn-primary" type="submit"<?= $newN + $existN === 0 ? ' disabled' : '' ?>>Importer <?= (int) $newN ?> contact<?= $newN > 1 ? 's' : '' ?></button>
          <button class="btn" type="submit" name="act" value="cancel" formnovalidate>Annuler</button></p>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function ci_result_html(array $res, array $cfg, callable $h): string
{
    ob_start();
    ?>
    <div class="ci">
      <div class="ci-card" role="status">
        <h2>Import terminé</h2>
        <div class="kpis">
          <div class="kpi"><b><?= (int) $res['created'] ?></b>créés</div><div class="kpi"><b><?= (int) $res['completed'] ?></b>complétés</div>
          <div class="kpi"><b><?= (int) $res['existing'] ?></b>déjà présents</div><div class="kpi"><b><?= (int) $res['skipped'] ?></b>écartés</div>
          <?php if ($res['failed']): ?><div class="kpi"><b><?= (int) $res['failed'] ?></b>en échec</div><?php endif; ?>
        </div>
        <p><a class="btn btn-primary" href="<?= $h($cfg['back_url']) ?>"><?= $h($cfg['back_label'] ?? 'Voir la liste') ?></a> <a class="btn" href="<?= $h($cfg['url']) ?>">Nouvel import</a></p>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
