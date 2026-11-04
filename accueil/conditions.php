<?php
// Conditions d'utilisation de la plateforme : rôle d'hébergeur, abonnement,
// engagements de disponibilité, crédits de service et geste commercial discrétionnaire.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/platform-terms.php';

$sections = platform_terms_sections();
$identity = platform_terms_identity();
$cfg = saas_config();
saas_page_start('Conditions d\'utilisation — ' . $cfg['name'], 'Conditions d\'utilisation de la plateforme : abonnements, disponibilité, crédits de service et geste commercial discrétionnaire.');
?>
<style>
  .terms-page { max-width: 860px; }
  .terms-page h1 { font-family: var(--display); font-size: clamp(2rem, 5vw, 3rem); margin: 6px 0 12px; }
  .terms-page .lede { max-width: 70ch; }
  .terms-toc { display: flex; flex-wrap: wrap; gap: 6px; margin: 22px 0 30px; padding: 0; list-style: none; }
  .terms-toc a { display: inline-block; padding: 6px 11px; border: 1px solid var(--line); border-radius: 999px; background: var(--surface); color: inherit; text-decoration: none; font-size: .86rem; }
  .terms-toc a:hover { border-color: var(--accent); color: var(--accent); }
  .terms-page section { padding: 18px 0 6px; border-top: 1px solid var(--line); }
  .terms-page h2 { font-family: var(--display); font-size: 1.35rem; margin: 0 0 10px; }
  .terms-page p, .terms-page li { line-height: 1.7; }
  .terms-page ul { padding-left: 1.3rem; }
  .terms-page a { color: var(--accent); }
  .terms-table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; font-size: .92rem; }
  .terms-table th, .terms-table td { padding: 9px 11px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .terms-table thead th { background: var(--surface-2); font-weight: 700; }
  .terms-table tr:last-child td { border-bottom: 0; }
  .terms-note { margin: 26px 0 0; padding: 14px 16px; border: 1px dashed var(--line); border-radius: 10px; background: var(--surface); font-size: .92rem; }
  .terms-draft { margin: 18px 0 0; padding: 13px 16px; border: 1px solid var(--accent); border-radius: 10px; background: var(--surface); font-size: .92rem; }
  .terms-draft strong { display: block; margin-bottom: 4px; }
  @media (max-width: 620px) { .terms-table { font-size: .84rem; } .terms-table th, .terms-table td { padding: 7px 8px; } }
</style>
<section class="block"><div class="wrap terms-page">
  <p class="eyebrow">Conditions de la plateforme</p>
  <h1>Conditions d'utilisation</h1>
  <p class="lede">Ces conditions régissent l'utilisation de la plateforme par les commerçants, les galeries et les marchés. Elles ne régissent pas la vente des pièces : chaque boutique publie ses propres conditions générales de vente et reste seule responsable de ses ventes.</p>
  <p><strong>Version du <?= saas_e(date('d/m/Y', strtotime(platform_terms_version()))) ?></strong><?= $identity['registered'] ? '' : ' — version provisoire' ?> — elles s'appliquent à compter de leur mise en ligne.</p>

  <?php if (!$identity['registered']): ?>
    <div class="terms-draft" role="note">
      <strong>Entreprise en cours d'immatriculation</strong>
      La plateforme est exploitée par une entreprise individuelle dont l'immatriculation est en cours. Le numéro SIRET, l'adresse de l'établissement et le médiateur de la consommation seront publiés sur cette page dès leur attribution ; les autres clauses s'appliquent dès maintenant et ne seront pas modifiées par cette publication.
    </div>
  <?php endif; ?>

  <nav aria-label="Sommaire"><ul class="terms-toc">
    <?php foreach ($sections as [$anchor, $title]): ?>
      <li><a href="#<?= saas_e($anchor) ?>"><?= saas_e($title) ?></a></li>
    <?php endforeach; ?>
  </ul></nav>

  <?php foreach ($sections as [$anchor, $title, $html]): ?>
    <section id="<?= saas_e($anchor) ?>">
      <h2><?= saas_e($title) ?></h2>
      <?= $html ?>
    </section>
  <?php endforeach; ?>

  <?php if ($identity['registered']): ?>
    <p class="terms-note">Ce texte est un document de travail à faire relire par un conseil juridique avant sa diffusion définitive. Les mentions entre crochets doivent être complétées avec l'identité exacte de l'éditeur et du médiateur. Les conditions générales de vente de chaque commerce restent distinctes et relèvent de la responsabilité du commerçant.</p>
  <?php else: ?>
    <p class="terms-note">Version provisoire : l'entreprise qui exploite la plateforme est en cours d'immatriculation. Sa raison sociale, son SIRET, son adresse et le médiateur de la consommation seront publiés sur cette page dès qu'ils seront connus, sans modifier les autres clauses. Ce texte doit être relu par un conseil juridique avant la première facturation. Les conditions générales de vente de chaque commerce restent distinctes et relèvent de la responsabilité du commerçant.</p>
  <?php endif; ?>
</div></section>
<?php saas_page_end(); ?>
