<?php
/** Zugriff einer Seite. @var array $p  @var ?array $from  @var array $values  @var array $groups */
use Core\Fields;
use Klxm\Members\AdminController;
?>
<p><a href="<?= e(url('/admin/mitglieder/bereiche')) ?>">← <?= e(__('Geschützte Seiten')) ?></a> · <a href="<?= e(url('/admin/pages/' . (int) $p['id'])) ?>"><?= e(__('Seiteneinstellungen')) ?></a></p>
<form method="post" action="<?= e(url('/admin/mitglieder/seite/' . (int) $p['id'])) ?>" class="adm-card" novalidate>
  <?= csrf_field() ?>
  <h2><?= e(__('Zugriff: {title}', ['title' => $p['title']])) ?></h2>
  <?php if ($from): ?>
  <p class="adm-flash" role="status"><?= e(__('Diese Seite ist bereits geschützt, weil sie unter „{title}“ liegt. Eigene Einstellungen hier gelten statt der übergeordneten.', ['title' => $from['title']])) ?> <a href="<?= e(url('/admin/mitglieder/seite/' . (int) $from['id'])) ?>"><?= e(__('Übergeordnete Einstellung')) ?></a></p>
  <?php endif; ?>
  <?php if (!$groups): ?><p class="adm-muted"><?= e(__('Tipp: Mit Gruppen (z. B. Vorstand) geben Sie Bereiche nur für einen Teil der Mitglieder frei.')) ?> <a href="<?= e(url('/admin/mitglieder/gruppen')) ?>"><?= e(__('Gruppen anlegen')) ?></a></p><?php endif; ?>
  <div class="adm-fields"><?= Fields::renderForm(AdminController::areaFields(), $values, [], 'f') ?></div>
  <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
</form>
