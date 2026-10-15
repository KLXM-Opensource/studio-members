<?php
/** Kopf und Bereichsnavigation „Mitglieder“. @var string $tab  @var array $flash  @var array $counts */
$items = [
    'members' => ['/admin/mitglieder', __('Mitglieder')],
    'groups' => ['/admin/mitglieder/gruppen', __('Gruppen')],
    'areas' => ['/admin/mitglieder/bereiche', __('Geschützte Seiten')],
    'settings' => ['/admin/mitglieder/einstellungen', __('Einstellungen')],
];
$current = ['member' => 'members', 'area' => 'areas'][$tab] ?? $tab;
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= e(__('Erweiterung')) ?> · <?= e(__('Mitgliederbereich')) ?></p><h1><?= e(__('Mitglieder')) ?></h1><p class="adm-muted"><?= e(__('Mitglieder einladen, Anträge freischalten, Seiten und Dateien schützen.')) ?></p></div>
  <a class="adm-btn adm-btn--ghost" href="<?= e(url('/mitglieder/anmelden')) ?>" target="_blank" rel="noopener"><?= icon('arrow-square-out') ?> <?= e(__('Anmeldeseite öffnen')) ?></a>
</header>
<?php
// Bereichsliste links wie Grundeinstellungen (Core\AdminUi, ältere Kerne: Leiste oben)
$icons = ['members' => 'users', 'groups' => 'users-three', 'areas' => 'lock', 'settings' => 'gear-six'];
if (class_exists(\Core\AdminUi::class)) {
    $navItems = [];
    foreach ($items as $k => [$href, $label]) $navItems[$k] = ['href' => url($href), 'label' => $label, 'icon' => $icons[$k] ?? 'circle', 'badge' => $k === 'members' && ($counts['pending'] ?? 0) ? (int) $counts['pending'] : null];
    echo \Core\AdminUi::open($navItems, $current, __('Mitglieder'), false);
    $GLOBALS['mb_shell'] = true;
} else { ?>
<nav class="mb-adm-nav" aria-label="<?= e(__('Mitglieder')) ?>">
  <?php foreach ($items as $k => [$href, $label]): ?><a href="<?= e(url($href)) ?>"<?= $k === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($k === 'members' && ($counts['pending'] ?? 0)): ?> <span class="adm-badge"><?= (int) $counts['pending'] ?></span><?php endif; ?></a><?php endforeach; ?>
</nav>
<?php } ?>
<?php foreach ($flash as [$type, $msg]): ?>
<p class="adm-flash adm-flash--<?= e($type) ?>" role="status"><?= e($msg) ?></p>
<?php endforeach; ?>
