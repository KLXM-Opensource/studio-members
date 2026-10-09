<?php
/** Geschützte Seiten und Datentabellen. @var array $rows  @var array $groups  @var array $tables  @var array $rules */
$gl = fn(array $ids) => $ids ? implode(', ', array_map(fn($g) => $groups[$g] ?? __('(gelöschte Gruppe)'), $ids)) : __('Alle Mitglieder');
?>
<section class="adm-card">
  <h2><?= e(__('Seite schützen')) ?></h2>
  <form method="post" action="<?= e(url('/admin/mitglieder/bereiche')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="adm-fields"><?= \Core\Fields::renderForm(\Klxm\Members\AdminController::addAreaFields(), ['scope' => 'tree'], [], 'f') ?></div>
    <button class="adm-btn adm-btn--primary"><?= e(__('Schützen')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Geschützte Seiten erscheinen nicht im Menü, in der Sitemap und in der Suche; Besucher werden zur Anmeldung geleitet. Bilder und Dateien im geschützten Bereich der Mediathek sind nur für angemeldete Mitglieder abrufbar.')) ?></p>
</section>
<?php if ($rows): ?>
<div class="adm-card mb-adm-tablewrap">
<table class="adm-table">
  <thead><tr><th scope="col"><?= e(__('Seite')) ?></th><th scope="col"><?= e(__('Freigegeben für')) ?></th><th scope="col"><?= e(__('Gilt für')) ?></th><th scope="col"><span class="adm-sr"><?= e(__('Aktionen')) ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $p = $r['page']; ?>
    <tr>
      <td><strong><?= e($p['title']) ?></strong><br><span class="adm-muted"><?= e(\Core\Pages::url($p)) ?></span><?php if ($p['status'] !== 'published'): ?> <span class="adm-badge"><?= e(__('Entwurf')) ?></span><?php endif; ?></td>
      <td><?= e($gl($r['groups'])) ?></td>
      <td><?= e($r['scope'] === 'page' ? __('nur diese Seite') : __('mit {n} Unterseiten', ['n' => (int) $r['children']])) ?></td>
      <td class="adm-actions"><a class="adm-btn adm-btn--small" href="<?= e(url('/admin/mitglieder/seite/' . (int) $p['id'])) ?>"><?= e(__('Zugriff')) ?><span class="adm-sr">: <?= e($p['title']) ?></span></a>
        <a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(\Core\Pages::url($p)) ?>" target="_blank" rel="noopener"><?= e(__('Ansehen')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<p class="adm-muted"><?= e(__('Noch keine geschützten Seiten.')) ?></p>
<?php endif; ?>

<section class="adm-card" id="daten" aria-labelledby="mb-h-tables">
  <h2 id="mb-h-tables"><?= e(__('Datentabellen')) ?></h2>
  <p class="adm-muted"><?= e(__('Eine geschützte Tabelle zeigt ihre Einträge nur angemeldeten Mitgliedern: Detailseiten verlangen die Anmeldung, und auf öffentlichen Seiten bleiben Listen, Kalender und Feeds dieser Tabelle leer. Listen gehören deshalb auf geschützte Seiten.')) ?></p>
  <?php if (!$tables): ?><p class="adm-muted"><?= e(__('Keine Datentabellen vorhanden.')) ?></p><?php endif; ?>
  <ul class="mb-adm-list" role="list">
    <?php foreach ($tables as $t): $rule = $rules[$t['handle']] ?? null; $sel = $rule !== null ? array_filter(array_map('intval', explode(',', $rule))) : []; ?>
    <li>
      <form method="post" action="<?= e(url('/admin/mitglieder/tabelle/' . rawurlencode($t['handle']))) ?>" class="mb-adm-table">
        <?= csrf_field() ?>
        <strong><?= e($t['name']) ?></strong>
        <label><input type="checkbox" name="protect" value="1"<?= $rule !== null ? ' checked' : '' ?>> <?= e(__('Nur für Mitglieder')) ?></label>
        <?php if ($groups): ?><fieldset><legend class="adm-sr"><?= e(__('Freigegeben für')) ?></legend>
          <?php foreach ($groups as $gid => $gname): ?><label><input type="checkbox" name="groups[]" value="<?= (int) $gid ?>"<?= in_array((int) $gid, $sel, true) ? ' checked' : '' ?>> <?= e($gname) ?></label><?php endforeach; ?></fieldset><?php endif; ?>
        <button class="adm-btn adm-btn--small"><?= e(__('Speichern')) ?><span class="adm-sr">: <?= e($t['name']) ?></span></button>
      </form>
      <?php if ($rule !== null && !empty($exposed[$t['handle']])): // geschützt, aber auf öffentlichen Seiten eingebunden → Besucher sehen dort nichts ?>
      <p class="adm-flash adm-flash--error"><?= e(__('Achtung: Diese Tabelle steht auf öffentlichen Seiten ({pages}) – Besucher sehen dort keine Einträge. Schutz aufheben oder die Seiten schützen.', ['pages' => implode(', ', array_slice($exposed[$t['handle']], 0, 4))])) ?></p>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($groups): ?><p class="adm-muted"><?= e(__('Ohne Gruppenauswahl: alle aktiven Mitglieder.')) ?></p><?php endif; ?>
</section>
