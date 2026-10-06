<?php
/** Anmelden: Passkey, Passwort, Anmelde-Link. @var array $errors  @var string $email  @var string $target  @var array $settings  @var bool $passkeys */
use Klxm\Members\Members;
$f = fn(array $v) => \Core\Theme::capture(__DIR__ . '/_field.php', $v + ['errors' => $errors]);
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e(lt('Anmelden')) ?></h1>
  <?php if (trim($settings['intro']) !== ''): ?><p class="mb-lead"><?= nl2br(e($settings['intro']), false) ?></p><?php endif; ?>
  <?php include __DIR__ . '/_notice.php'; ?>

  <?php if ($passkeys): ?>
  <div class="mb-pk" data-mb-passkey-login data-options="<?= e(Members::url('passkey/login-options')) ?>" data-url="<?= e(Members::url('passkey/login')) ?>" data-csrf="<?= e(\Core\Csrf::token()) ?>" data-target="<?= e($target) ?>" hidden>
    <button type="button" class="btn btn--primary mb-btn-wide"><?= e(lt('Mit Passkey anmelden')) ?></button>
    <p class="mb-err" role="alert" data-mb-error hidden></p>
    <p class="mb-or"><span><?= e(lt('oder')) ?></span></p>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= e(Members::url('anmelden')) ?>" class="mb-form">
    <?= csrf_field() ?><input type="hidden" name="ziel" value="<?= e($target) ?>">
    <?= $f(['name' => 'email', 'label' => lt('E-Mail-Adresse'), 'type' => 'email', 'value' => $email, 'auto' => 'username webauthn', 'required' => true]) ?>
    <?= $f(['name' => 'password', 'label' => lt('Passwort'), 'type' => 'password', 'value' => '', 'auto' => 'current-password', 'required' => true]) ?>
    <button class="btn btn--primary mb-btn-wide"><?= e(lt('Anmelden')) ?></button>
  </form>

  <?php if ($settings['magic']): ?>
  <details class="mb-more"<?= isset($errors['link_email']) ? ' open' : '' ?>>
    <summary><?= e(lt('Ohne Passwort: Anmelde-Link per E-Mail')) ?></summary>
    <form method="post" action="<?= e(Members::url('link')) ?>" class="mb-form">
      <?= csrf_field() ?><input type="hidden" name="ziel" value="<?= e($target) ?>">
      <?= \Core\Theme::capture(__DIR__ . '/_field.php', ['name' => 'link_email', 'label' => lt('E-Mail-Adresse'), 'type' => 'email', 'value' => $email, 'auto' => 'email', 'required' => true, 'errors' => $errors]) ?>
      <button class="btn btn--secondary"><?= e(lt('Link senden')) ?></button>
    </form>
  </details>
  <?php endif; ?>

  <?php if ($settings['apply']): ?>
  <p class="mb-foot"><?= e(lt('Noch kein Zugang?')) ?> <a href="<?= e(Members::url('zugang')) ?>"><?= e(lt('Zugang beantragen')) ?></a></p>
  <?php endif; ?>
</div>
<script src="<?= e(Members::asset('js/members.js')) ?>" defer></script>
