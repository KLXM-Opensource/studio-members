<?php
/**
 * Mitglieder importieren (CSV): Vorlage, Datei hochladen, Vorschau mit Optionen, Übernehmen.
 * @var array $fields  @var ?array $preview  @var string $file  @var bool $mailOk
 */
use Core\Data\Tables;

$st = ['new' => __('neu'), 'update' => __('vorhanden'), 'invalid' => __('ungültig'), 'duplicate' => __('doppelt')];
$cnt = $preview ? array_count_values(array_column($preview['rows'], 'status')) + ['new' => 0, 'update' => 0, 'invalid' => 0, 'duplicate' => 0] : [];
$known = array_map('mb_strtolower', array_values(\Klxm\Members\Repo::groupNames()));
$newGroups = $preview ? array_values(array_unique(array_filter(array_merge(...array_map(fn($r) => $r['groups'], $preview['rows']) ?: [[]]), fn($g) => !in_array(mb_strtolower($g), $known, true)))) : [];
?>
<p class="adm-eyebrow"><a href="<?= e(url('/admin/mitglieder')) ?>">← <?= e(__('Mitglieder')) ?></a></p>
<h2><?= e(__('Mitglieder importieren')) ?></h2>

<?php if (!$preview): ?>
<section class="adm-card mb-import" aria-labelledby="mb-imp-1">
  <h3 id="mb-imp-1"><?= e(__('1. Vorlage')) ?></h3>
  <p><?= e(__('Eine Zeile je Person. Pflicht ist nur die E-Mail-Adresse; dazu Name (oder Vorname und Nachname), Gruppen (mehrere mit Komma trennen) und auf Wunsch Profilfelder. Eine vorhandene Liste aus Excel oder einem anderen Programm funktioniert auch – die Spalten werden an ihrer Überschrift erkannt.')) ?></p>
  <p><a class="adm-btn" href="<?= e(url('/admin/mitglieder/import/vorlage.csv')) ?>" download><?= icon('download-simple') ?> <?= e(__('Vorlage herunterladen (CSV)')) ?></a></p>
  <p class="adm-muted"><?= e(__('Erkannte Spalten: E-Mail, Name, Vorname, Nachname, Gruppen')) ?><?= $fields ? ', ' . e(implode(', ', array_map(fn($f) => Tables::label($f), $fields))) : '' ?>.</p>
</section>
<form class="adm-card mb-import" method="post" action="<?= e(url('/admin/mitglieder/import')) ?>" enctype="multipart/form-data" aria-labelledby="mb-imp-2">
  <?= csrf_field() ?>
  <h3 id="mb-imp-2"><?= e(__('2. Datei hochladen')) ?></h3>
  <p><label for="mb-csv"><?= e(__('CSV-Datei')) ?></label><br><input id="mb-csv" type="file" name="csv" accept=".csv,text/csv" required></p>
  <p class="adm-muted"><?= e(__('Trennzeichen Semikolon, Komma oder Tabulator; höchstens {n} Zeilen. Noch wird nichts gespeichert – zuerst kommt eine Vorschau.', ['n' => \Klxm\Members\Import::MAX_ROWS])) ?></p>
  <button class="adm-btn adm-btn--primary"><?= e(__('Vorschau anzeigen')) ?></button>
</form>
<?php else: ?>
<section class="adm-card mb-import" aria-labelledby="mb-imp-p">
  <h3 id="mb-imp-p"><?= e(__('Vorschau: {file}', ['file' => $file])) ?></h3>
  <?php if ($preview['error']): ?><p class="adm-flash adm-flash--error"><?= e($preview['error']) ?></p><?php endif; ?>
  <p class="mb-import__sum"><b><?= (int) $cnt['new'] ?></b> <?= e(__('neu')) ?> · <b><?= (int) $cnt['update'] ?></b> <?= e(__('schon vorhanden')) ?><?php if ($cnt['invalid'] || $cnt['duplicate']): ?> · <b><?= (int) ($cnt['invalid'] + $cnt['duplicate']) ?></b> <?= e(__('werden übersprungen (ungültig oder doppelt)')) ?><?php endif; ?></p>
  <p class="adm-muted"><?= e(__('Erkannte Spalten:')) ?> <?= e(implode(' · ', array_map(fn($k, $v) => $k . ' → ' . $v, array_keys($preview['columns']), $preview['columns']))) ?><?php if ($preview['unknown']): ?><br><?= e(__('Nicht zugeordnet (wird ignoriert):')) ?> <?= e(implode(', ', $preview['unknown'])) ?><?php endif; ?></p>
  <div class="mb-import__table"><table class="adm-table">
    <thead><tr><th><?= e(__('Zeile')) ?></th><th><?= e(__('E-Mail')) ?></th><th><?= e(__('Name')) ?></th><th><?= e(__('Gruppen')) ?></th><th><?= e(__('Status')) ?></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($preview['rows'], 0, 50) as $row): ?>
      <tr class="mb-import__r--<?= e($row['status']) ?>"><td><?= (int) $row['line'] ?></td><td><?= e($row['email']) ?></td><td><?= e($row['name']) ?></td><td><?= e(implode(', ', $row['groups'])) ?></td>
        <td><?= e($st[$row['status']]) ?><?= $row['note'] !== '' ? ' – ' . e($row['note']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php if (count($preview['rows']) > 50): ?><p class="adm-muted"><?= e(__('… und {n} weitere Zeilen.', ['n' => count($preview['rows']) - 50])) ?></p><?php endif; ?>
</section>
<?php if ($cnt['new'] || $cnt['update']): ?>
<form class="adm-card mb-import" method="post" action="<?= e(url('/admin/mitglieder/import/uebernehmen')) ?>" aria-labelledby="mb-imp-o">
  <?= csrf_field() ?>
  <h3 id="mb-imp-o"><?= e(__('Übernehmen')) ?></h3>
  <?php if ($cnt['update']): ?>
  <fieldset><legend><?= e(__('Schon vorhandene Mitglieder ({n})', ['n' => $cnt['update']])) ?></legend>
    <label class="f-check"><input type="radio" name="existing" value="update" checked> <span><?= e(__('Ergänzen: Name und Felder aus der Datei übernehmen, Gruppen hinzufügen (nichts wird entfernt)')) ?></span></label>
    <label class="f-check"><input type="radio" name="existing" value="skip"> <span><?= e(__('Überspringen')) ?></span></label>
  </fieldset>
  <?php endif; ?>
  <?php if ($newGroups): ?>
  <label class="f-check"><input type="checkbox" name="create_groups" value="1" checked> <span><?= e(__('Unbekannte Gruppen anlegen: {list}', ['list' => implode(', ', $newGroups)])) ?></span></label>
  <?php endif; ?>
  <label class="f-check"><input type="checkbox" name="invite" value="1"<?= $mailOk ? '' : ' disabled' ?>> <span><?= e(__('Neue Mitglieder gleich einladen')) ?> <small class="adm-muted"><?= e($mailOk ? __('(höchstens {n} je Schritt, den Rest über „Alle einladen“)', ['n' => \Klxm\Members\AdminController::INVITE_BATCH]) : __('(E-Mail-Versand nicht eingerichtet)')) ?></small></span></label>
  <p class="adm-muted"><?= e(__('Ohne Einladung entstehen die Mitglieder „ohne Zugang“ – einladen können Sie sie später einzeln oder mit „Alle einladen“.')) ?></p>
  <p><button class="adm-btn adm-btn--primary"><?= e(__('{n} Mitglieder übernehmen', ['n' => $cnt['new'] + $cnt['update']])) ?></button>
    <a class="adm-btn adm-btn--ghost" href="<?= e(url('/admin/mitglieder/import')) ?>"><?= e(__('Andere Datei')) ?></a></p>
</form>
<?php endif; ?>
<?php endif; ?>
