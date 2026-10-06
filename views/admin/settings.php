<?php
/** Einstellungen. @var array $values  @var array $errors */
use Core\Fields;
use Klxm\Members\AdminController;
?>
<form method="post" action="<?= e(url('/admin/mitglieder/einstellungen')) ?>" novalidate>
  <?= csrf_field() ?>
  <section class="adm-card"><div class="adm-fields"><?= Fields::renderForm(AdminController::settingsFields(), $values, $errors, 'f') ?></div></section>
  <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
</form>
<section class="adm-card">
  <h2><?= e(__('Einbindung')) ?></h2>
  <ul>
    <li><?= e(__('Link zur Anmeldung im Kopfbereich: Design → Kopfbereich → „Anmelden (Mitgliederbereich)“ einschalten und unter Website → Darstellung als Link /mitglieder eintragen.')) ?></li>
    <li><?= e(__('Adressen: /mitglieder (Übersicht), /mitglieder/anmelden, /mitglieder/konto, /mitglieder/zugang (Antrag, wenn eingeschaltet).')) ?></li>
  </ul>
</section>
