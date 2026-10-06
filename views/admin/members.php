<?php
/** Mitglieder: einladen, Anträge, Liste mit Filter. @var array $rows  @var string $status  @var string $q  @var int $group  @var array $groups  @var array $values  @var array $errors  @var array $counts  @var bool $mailOk */
use Core\Fields;
use Klxm\Members\AdminController;
$labels = AdminController::statusLabels();
$pending = array_values(array_filter($rows, fn($m) => $m['status'] === 'pending'));
?>
<?php if (!$mailOk): ?><p class="adm-flash adm-flash--error" role="status"><?= e(__('Der E-Mail-Versand ist nicht eingerichtet (System → E-Mail-Versand) – Einladungen und Anmelde-Links können nicht verschickt werden.')) ?></p><?php endif; ?>

<?php if ($pending && $status !== 'active'): ?>
<section class="adm-card mb-adm-pending" aria-labelledby="mb-pending-h">
  <h2 id="mb-pending-h"><?= e(__('Offene Anträge')) ?> <span class="adm-count"><?= count($pending) ?></span></h2>
  <ul class="mb-adm-list" role="list">
    <?php foreach ($pending as $m): ?>
    <li>
      <div><strong><?= e($m['name'] ?: '–') ?></strong> <span class="adm-muted"><?= e($m['email']) ?> · <?= e(fmt()->relative((string) $m['created_at'])) ?></span>
        <?php if (trim((string) $m['application']) !== ''): ?><p class="mb-adm-msg"><?= nl2br(e($m['application']), false) ?></p><?php endif; ?></div>
      <form method="post" action="<?= e(url('/admin/mitglieder/' . (int) $m['id'] . '/einladung')) ?>" class="mb-adm-approve">
        <?= csrf_field() ?><input type="hidden" name="back" value="1">
        <?php if ($groups): ?><fieldset><legend class="adm-muted"><?= e(__('Gruppen')) ?></legend>
          <?php foreach ($groups as $gid => $gname): ?><label><input type="checkbox" name="groups[]" value="<?= (int) $gid ?>"> <?= e($gname) ?></label><?php endforeach; ?></fieldset><?php endif; ?>
        <button class="adm-btn adm-btn--primary adm-btn--small"><?= e(__('Freischalten und einladen')) ?></button>
      </form>
      <form method="post" action="<?= e(url('/admin/mitglieder/' . (int) $m['id'] . '/ablehnen')) ?>" data-confirm="<?= e(__('Antrag von {email} ablehnen und löschen?', ['email' => $m['email']])) ?>">
        <?= csrf_field() ?><label class="adm-muted"><input type="checkbox" name="notify" value="1"> <?= e(__('Absage per E-Mail')) ?></label>
        <button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Ablehnen')) ?></button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<details class="adm-card mb-adm-invite"<?= $errors ? ' open' : '' ?>>
  <summary><?= icon('plus') ?> <?= e(__('Mitglied einladen')) ?></summary>
  <form method="post" action="<?= e(url('/admin/mitglieder/einladen')) ?>" novalidate>
    <?= csrf_field() ?>
    <p class="adm-muted"><?= e(__('Das Mitglied erhält eine E-Mail mit einem Link (14 Tage gültig) und legt damit Passwort oder Passkey fest.')) ?></p>
    <div class="adm-fields"><?= Fields::renderForm(AdminController::inviteFields(), $values, $errors, 'f') ?></div>
    <button class="adm-btn adm-btn--primary"><?= e(__('Einladung senden')) ?></button>
  </form>
</details>

<form class="mb-adm-filter" method="get" action="<?= e(url('/admin/mitglieder')) ?>" role="search">
  <label class="adm-sr" for="mb-q"><?= e(__('Suchen')) ?></label>
  <input id="mb-q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(__('Name oder E-Mail …')) ?>">
  <label class="adm-sr" for="mb-st"><?= e(__('Status')) ?></label>
  <select id="mb-st" name="status"><option value=""><?= e(__('Alle Status')) ?></option>
    <?php foreach ($labels as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $status ? ' selected' : '' ?>><?= e($l) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</option><?php endforeach; ?></select>
  <?php if ($groups): ?><label class="adm-sr" for="mb-g"><?= e(__('Gruppe')) ?></label>
  <select id="mb-g" name="gruppe"><option value="0"><?= e(__('Alle Gruppen')) ?></option>
    <?php foreach ($groups as $gid => $gname): ?><option value="<?= (int) $gid ?>"<?= (int) $gid === $group ? ' selected' : '' ?>><?= e($gname) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="adm-btn adm-btn--small"><?= e(__('Filtern')) ?></button>
</form>

<?php if ($rows): ?>
<div class="adm-card mb-adm-tablewrap">
<table class="adm-table">
  <thead><tr><th scope="col"><?= e(__('Mitglied')) ?></th><th scope="col"><?= e(__('Status')) ?></th><th scope="col"><?= e(__('Gruppen')) ?></th><th scope="col"><?= e(__('Letzte Anmeldung')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
    <tr>
      <td class="mb-adm-who"><?= \Klxm\Members\Avatar::html($m, 'mb-avatar mb-avatar--s', 36) ?><span><a href="<?= e(url('/admin/mitglieder/' . (int) $m['id'])) ?>"><strong><?= e($m['name'] ?: $m['email']) ?></strong></a><?php if ($m['name']): ?><br><span class="adm-muted"><?= e($m['email']) ?></span><?php endif; ?></span></td>
      <td><span class="adm-badge mb-st mb-st--<?= e($m['status']) ?>"><?= e($labels[$m['status']] ?? $m['status']) ?></span></td>
      <td><?= e(implode(', ', array_filter(array_map(fn($g) => $groups[$g] ?? null, $m['groups']))) ?: '–') ?></td>
      <td class="adm-muted"><?= $m['last_login_at'] ? e(fmt()->datetime((string) $m['last_login_at'])) : e(__('nie')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<p class="adm-muted"><?= e($q !== '' || $status !== '' || $group ? __('Keine Mitglieder für diesen Filter.') : __('Noch keine Mitglieder. Laden Sie das erste Mitglied ein.')) ?></p>
<?php endif; ?>
