<?php
/** Gruppen. @var array $groups */
?>
<section class="adm-card">
  <h2><?= e(__('Neue Gruppe')) ?></h2>
  <form method="post" action="<?= e(url('/admin/mitglieder/gruppen')) ?>" class="mb-adm-row">
    <?= csrf_field() ?>
    <div class="f"><label for="mb-gn"><?= e(__('Name')) ?></label><input id="mb-gn" name="name" maxlength="80" required placeholder="<?= e(__('z. B. Vorstand')) ?>"></div>
    <div class="f"><label for="mb-gd"><?= e(__('Beschreibung (optional)')) ?></label><input id="mb-gd" name="description" maxlength="191"></div>
    <button class="adm-btn adm-btn--primary"><?= e(__('Anlegen')) ?></button>
  </form>
</section>
<?php if ($groups): ?>
<ul class="mb-adm-groups" role="list">
  <?php foreach ($groups as $g): ?>
  <li class="adm-card">
    <form method="post" action="<?= e(url('/admin/mitglieder/gruppen')) ?>" class="mb-adm-row">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
      <div class="f"><label for="mb-gn<?= (int) $g['id'] ?>"><?= e(__('Name')) ?></label><input id="mb-gn<?= (int) $g['id'] ?>" name="name" value="<?= e($g['name']) ?>" maxlength="80" required></div>
      <div class="f"><label for="mb-gd<?= (int) $g['id'] ?>"><?= e(__('Beschreibung')) ?></label><input id="mb-gd<?= (int) $g['id'] ?>" name="description" value="<?= e($g['description']) ?>" maxlength="191"></div>
      <button class="adm-btn adm-btn--small"><?= e(__('Speichern')) ?></button>
    </form>
    <div class="mb-adm-groupfoot">
      <a href="<?= e(url('/admin/mitglieder?gruppe=' . (int) $g['id'])) ?>"><?= e(__('{n} Mitglieder', ['n' => $g['count']])) ?></a>
      <form method="post" action="<?= e(url('/admin/mitglieder/gruppen/' . (int) $g['id'] . '/loeschen')) ?>" data-confirm="<?= e(__('Gruppe „{name}“ löschen? Bereiche, die nur für diese Gruppe freigegeben sind, sind danach für niemanden mehr zugänglich.', ['name' => $g['name']])) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Löschen')) ?><span class="adm-sr">: <?= e($g['name']) ?></span></button></form>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
<p class="adm-muted"><?= e(__('Noch keine Gruppen. Ohne Gruppen gelten geschützte Bereiche für alle aktiven Mitglieder.')) ?></p>
<?php endif; ?>
