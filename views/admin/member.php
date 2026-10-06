<?php
/** Ein Mitglied bearbeiten. @var array $m  @var array $values  @var array $errors  @var array $keys */
use Core\Fields;
use Klxm\Members\AdminController;
$labels = AdminController::statusLabels();
$base = '/admin/mitglieder/' . (int) $m['id'];
?>
<p><a href="<?= e(url('/admin/mitglieder')) ?>">← <?= e(__('Alle Mitglieder')) ?></a></p>
<div class="adm-grid mb-adm-member">
  <form method="post" action="<?= e(url($base)) ?>" class="adm-card" novalidate>
    <?= csrf_field() ?>
    <h2><?= e($m['name'] ?: $m['email']) ?></h2>
    <div class="adm-fields"><?= Fields::renderForm(AdminController::memberFields(), $values, $errors, 'f') ?></div>
    <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
  </form>
  <aside class="adm-card">
    <div class="mb-adm-photo"><?= \Klxm\Members\Avatar::html($m, 'mb-avatar mb-avatar--l', 96) ?>
      <?php if (!empty($m['avatar'])): ?><form method="post" action="<?= e(url($base . '/foto-entfernen')) ?>" data-confirm="<?= e(__('Foto von {email} entfernen?', ['email' => $m['email']])) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Foto entfernen')) ?></button></form><?php endif; ?></div>
    <h2><?= e(__('Zugang')) ?></h2>
    <dl class="adm-dl">
      <dt><?= e(__('Status')) ?></dt><dd><span class="adm-badge mb-st mb-st--<?= e($m['status']) ?>"><?= e($labels[$m['status']] ?? $m['status']) ?></span></dd>
      <dt><?= e(__('Passwort')) ?></dt><dd><?= e(empty($m['password_hash']) ? __('nicht festgelegt') : __('festgelegt')) ?></dd>
      <dt><?= e(__('Passkeys')) ?></dt><dd><?= $keys ? e(implode(', ', array_column($keys, 'name'))) : e(__('keine')) ?></dd>
      <dt><?= e(__('Angelegt')) ?></dt><dd><?= e(fmt()->datetime((string) $m['created_at'])) ?></dd>
      <?php if ($m['invited_at']): ?><dt><?= e(__('Eingeladen')) ?></dt><dd><?= e(fmt()->datetime((string) $m['invited_at'])) ?></dd><?php endif; ?>
      <dt><?= e(__('Letzte Anmeldung')) ?></dt><dd><?= $m['last_login_at'] ? e(fmt()->datetime((string) $m['last_login_at'])) : e(__('nie')) ?></dd>
    </dl>
    <?php if (trim((string) $m['application']) !== ''): ?><h3><?= e(__('Antrag')) ?></h3><p class="mb-adm-msg"><?= nl2br(e($m['application']), false) ?></p><?php endif; ?>
    <div class="mb-adm-actions">
      <?php if ($m['status'] !== 'blocked' && $m['status'] !== 'active'): ?>
      <form method="post" action="<?= e(url($base . '/einladung')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--primary"><?= e($m['status'] === 'pending' ? __('Freischalten und einladen') : __('Einladung erneut senden')) ?></button></form>
      <?php endif; ?>
      <?php if ($m['status'] === 'active'): ?>
      <form method="post" action="<?= e(url($base . '/zugang-zuruecksetzen')) ?>" data-confirm="<?= e(__('Passwort und Passkeys von {email} entfernen und alle Sitzungen beenden?', ['email' => $m['email']])) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small"><?= e(__('Zugang zurücksetzen')) ?></button></form>
      <form method="post" action="<?= e(url($base . '/einladung')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Neuen Einrichtungs-Link senden')) ?></button></form>
      <?php endif; ?>
      <form method="post" action="<?= e(url($base . '/loeschen')) ?>" data-confirm="<?= e(__('{email} endgültig löschen?', ['email' => $m['email']])) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--danger"><?= e(__('Löschen')) ?></button></form>
    </div>
  </aside>
</div>
