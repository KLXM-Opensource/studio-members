<?php
/** Einladung annehmen: Name + Passwort oder Passkey. @var string $token  @var array $m  @var array $errors  @var bool $passkeys  @var int $min */
use Klxm\Members\Members;
$f = fn(array $v) => \Core\Theme::capture(__DIR__ . '/_field.php', $v + ['errors' => $errors]);
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e(lt('Zugang einrichten')) ?></h1>
  <p class="mb-lead"><?= e(lt('Willkommen im Mitgliederbereich von {site}. Ihr Zugang: {email}', ['site' => site_name(), 'email' => $m['email']])) ?></p>
  <?php include __DIR__ . '/_notice.php'; ?>
  <form method="post" action="<?= e(Members::url('einladung/' . $token)) ?>" class="mb-form" data-mb-invite>
    <?= csrf_field() ?>
    <input type="email" name="email" value="<?= e($m['email']) ?>" autocomplete="username" hidden>
    <?= $f(['name' => 'name', 'label' => lt('Ihr Name'), 'value' => $m['name'], 'auto' => 'name', 'required' => true]) ?>
    <?php if ($passkeys): ?>
    <div class="mb-pk" data-mb-passkey-add data-options="<?= e(Members::url('passkey/add-options')) ?>" data-url="<?= e(Members::url('passkey/add')) ?>" data-csrf="<?= e(\Core\Csrf::token()) ?>" data-invite hidden>
      <p class="mb-hint"><?= e(lt('Am einfachsten: mit einem Passkey – Fingerabdruck, Gesicht oder Geräte-PIN, kein Passwort nötig.')) ?></p>
      <button type="button" class="btn btn--primary mb-btn-wide"><?= e(lt('Passkey einrichten')) ?></button>
      <p class="mb-err" role="alert" data-mb-error hidden></p>
      <p class="mb-or"><span><?= e(lt('oder mit Passwort')) ?></span></p>
    </div>
    <?php endif; ?>
    <?= $f(['name' => 'password', 'label' => lt('Passwort'), 'type' => 'password', 'value' => '', 'auto' => 'new-password', 'hint' => lt('Mindestens {n} Zeichen.', ['n' => $min])]) ?>
    <?= $f(['name' => 'password2', 'label' => lt('Passwort wiederholen'), 'type' => 'password', 'value' => '', 'auto' => 'new-password']) ?>
    <button class="btn btn--secondary mb-btn-wide"><?= e(lt('Mit Passwort einrichten')) ?></button>
  </form>
</div>
<script src="<?= e(Members::asset('js/members.js')) ?>" defer></script>
