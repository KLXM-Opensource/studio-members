<?php
/** Konto: Foto, Name, Zugangsdaten (nach Bestätigung), Abmelden. @var bool $confirmed  @var array $m  @var array $errors  @var array $keys  @var array $groups  @var bool $passkeys  @var int $min */
use Klxm\Members\Members;
$f = fn(array $v) => \Core\Theme::capture(__DIR__ . '/_field.php', $v + ['errors' => $errors]);
$mine = array_values(array_filter(array_map(fn($g) => $groups[$g] ?? null, $m['groups'])));
?>
<div class="mb-card">
  <p class="mb-eyebrow"><a href="<?= e(Members::url()) ?>"><?= e(lt('Mitgliederbereich')) ?></a></p>
  <h1 class="mb-title"><?= e(lt('Mein Konto')) ?></h1>
  <?php include __DIR__ . '/_notice.php'; ?>
  <dl class="mb-dl">
    <?php if ($mine): ?><dt><?= e(lt('Gruppen')) ?></dt><dd><?= e(implode(', ', $mine)) ?></dd><?php endif; ?>
  </dl>

  <section class="mb-sec" aria-labelledby="mb-h-photo">
    <h2 id="mb-h-photo"><?= e(lt('Profilfoto')) ?></h2>
    <div class="mb-photo">
      <?= \Klxm\Members\Avatar::html($m, 'mb-avatar mb-avatar--l', 96) ?>
      <div class="mb-photo__forms">
        <form method="post" action="<?= e(Members::url('konto/foto')) ?>" enctype="multipart/form-data" class="mb-form">
          <?= csrf_field() ?>
          <div class="mb-f<?= isset($errors['avatar']) ? ' mb-f--err' : '' ?>">
            <label for="mb-avatar"><?= e(empty($m['avatar']) ? lt('Foto hochladen') : lt('Anderes Foto wählen')) ?></label>
            <input id="mb-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required aria-describedby="mb-avatar-hint<?= isset($errors['avatar']) ? ' mb-avatar-err' : '' ?>">
            <small class="mb-hint" id="mb-avatar-hint"><?= e(lt('JPG, PNG oder WebP. Das Foto wird quadratisch zugeschnitten und ist nur für angemeldete Mitglieder sichtbar.')) ?></small>
            <?php if (isset($errors['avatar'])): ?><small class="mb-err" id="mb-avatar-err"><?= e($errors['avatar']) ?></small><?php endif; ?>
          </div>
          <button class="btn btn--secondary"><?= e(lt('Foto speichern')) ?></button>
        </form>
        <?php if (!empty($m['avatar'])): ?>
        <form method="post" action="<?= e(Members::url('konto/foto')) ?>"><?= csrf_field() ?><input type="hidden" name="remove" value="1"><button class="mb-link"><?= e(lt('Foto entfernen')) ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="mb-sec" aria-labelledby="mb-h-profile">
    <h2 id="mb-h-profile"><?= e(lt('Mein Profil')) ?></h2>
    <p class="mb-hint"><?= e(lt('Sie entscheiden, wer welche Angabe sieht: alle Besucher der Website, nur angemeldete Mitglieder oder nur die Redaktion. Öffentlich erscheint Ihr Profil nur, wenn Ihr Name öffentlich ist.')) ?></p>
    <form method="post" action="<?= e(Members::url('konto')) ?>" class="mb-form mb-profile">
      <?= csrf_field() ?>
      <?php
      $levels = ['public' => lt('Öffentlich'), 'members' => lt('Nur Mitglieder'), 'private' => lt('Nur Redaktion')];
      $visSel = function (string $name) use ($vis, $levels): string {
          $h = '<label class="mb-vis"><span class="mb-sr">' . e(lt('Wer sieht das?')) . '</span><select name="v[' . e($name) . ']">';
          foreach ($levels as $k => $l) $h .= '<option value="' . e($k) . '"' . (($vis[$name] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
          return $h . '</select></label>';
      };
      foreach ($fields as $pf):
          $name = (string) $pf['name']; $id = 'mb-p-' . $name; $val = (string) ($m['profile'][$name] ?? ''); $err = $errors['p_' . $name] ?? null;
          $label = \Core\Data\Tables::label($pf); ?>
      <div class="mb-f mb-pf<?= $err ? ' mb-f--err' : '' ?>">
        <div class="mb-pf__head"><label for="<?= e($id) ?>"><?= e($label) ?></label><?= $visSel($name) ?></div>
        <?php if ($pf['type'] === 'textarea'): ?>
        <textarea id="<?= e($id) ?>" name="p[<?= e($name) ?>]" rows="5" maxlength="4000"><?= e($val) ?></textarea>
        <?php elseif ($pf['type'] === 'select'): ?>
        <select id="<?= e($id) ?>" name="p[<?= e($name) ?>]"><option value=""></option>
          <?php foreach ((array) ($pf['options'] ?? []) as $ok => $ol): ?><option value="<?= e((string) $ok) ?>"<?= (string) $ok === $val ? ' selected' : '' ?>><?= e(\Core\Data\Tables::optionLabel($pf, (string) $ok)) ?></option><?php endforeach; ?></select>
        <?php else: ?>
        <input id="<?= e($id) ?>" name="p[<?= e($name) ?>]" value="<?= e($val) ?>" type="<?= e(['url' => 'url', 'tel' => 'tel', 'email' => 'email', 'date' => 'date'][$pf['type']] ?? 'text') ?>"<?= $name === 'name' ? ' autocomplete="name" required' : '' ?>>
        <?php endif; ?>
        <?php if (!empty($pf['help'])): ?><small class="mb-hint"><?= e($pf['help']) ?></small><?php endif; ?>
        <?php if ($err): ?><small class="mb-err"><?= e($err) ?></small><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="mb-f mb-pf">
        <div class="mb-pf__head"><span class="mb-pf__l"><?= e(lt('E-Mail-Adresse')) ?></span><?= $visSel('email') ?></div>
        <p class="mb-pf__ro"><?= e($m['email']) ?> <small class="mb-hint"><?= e(lt('Ändern über die Betreiber der Website.')) ?></small></p>
      </div>
      <button class="btn btn--primary"><?= e(lt('Profil speichern')) ?></button>
    </form>
  </section>

  <section class="mb-sec" id="zugang" aria-labelledby="mb-h-access">
    <h2 id="mb-h-access"><?= e(lt('Zugangsdaten')) ?></h2>
    <?php if (!$confirmed): ?>
    <div class="mb-lock">
      <p><?= e(lt('Passwort und Passkeys ändern Sie erst, nachdem Sie bestätigt haben, dass Sie es sind. Die Bestätigung gilt 10 Minuten.')) ?></p>
      <?php if (isset($errors['confirm'])): ?><p class="mb-err" role="alert"><?= e($errors['confirm']) ?></p><?php endif; ?>
      <?php if ($keys && $passkeys): ?>
      <div class="mb-pk" data-mb-passkey-login data-options="<?= e(Members::url('passkey/confirm-options')) ?>" data-url="<?= e(Members::url('passkey/confirm')) ?>" data-csrf="<?= e(\Core\Csrf::token()) ?>" hidden>
        <button type="button" class="btn btn--primary"><?= e(lt('Mit Passkey bestätigen')) ?></button>
        <p class="mb-err" role="alert" data-mb-error hidden></p>
      </div>
      <?php endif; ?>
      <?php if (!empty($m['password_hash'])): ?>
      <form method="post" action="<?= e(Members::url('konto/bestaetigen')) ?>" class="mb-form mb-form--row">
        <?= csrf_field() ?>
        <input type="email" name="email" value="<?= e($m['email']) ?>" autocomplete="username" hidden>
        <?= $f(['name' => 'current', 'label' => lt('Mit Ihrem Passwort bestätigen'), 'type' => 'password', 'value' => '', 'auto' => 'current-password', 'required' => true]) ?>
        <button class="btn btn--secondary"><?= e(lt('Bestätigen')) ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= e(Members::url('konto/bestaetigen/mail')) ?>">
        <?= csrf_field() ?>
        <button class="mb-link"><?= e(lt('Stattdessen Bestätigungslink an {email} senden', ['email' => $m['email']])) ?></button>
      </form>
    </div>
    <?php else: ?>
    <p class="mb-note mb-note--ok"><?= e(lt('Bestätigt – Sie können Ihre Zugangsdaten jetzt ändern.')) ?></p>

    <h3><?= e(lt('Passkeys')) ?></h3>
    <p class="mb-hint"><?= e(lt('Anmelden mit Fingerabdruck, Gesicht oder Geräte-PIN – ohne Passwort.')) ?></p>
    <?php if ($keys): ?>
    <ul class="mb-keys" role="list">
      <?php foreach ($keys as $k): ?>
      <li><span><b><?= e($k['name']) ?></b><small><?= e(lt('eingerichtet {date}', ['date' => fmt()->date((string) $k['created_at'])])) ?><?php if ($k['last_used_at']): ?> · <?= e(lt('zuletzt {date}', ['date' => fmt()->date((string) $k['last_used_at'])])) ?><?php endif; ?></small></span>
        <form method="post" action="<?= e(Members::url('passkey/' . (int) $k['id'] . '/entfernen')) ?>"><?= csrf_field() ?><button class="mb-link"><?= e(lt('Entfernen')) ?><span class="mb-sr">: <?= e($k['name']) ?></span></button></form></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($passkeys): ?>
    <div class="mb-pk" data-mb-passkey-add data-options="<?= e(Members::url('passkey/add-options')) ?>" data-url="<?= e(Members::url('passkey/add')) ?>" data-csrf="<?= e(\Core\Csrf::token()) ?>" hidden>
      <button type="button" class="btn btn--secondary"><?= e(lt('Passkey hinzufügen')) ?></button>
      <p class="mb-err" role="alert" data-mb-error hidden></p>
    </div>
    <?php endif; ?>

    <h3><?= e(empty($m['password_hash']) ? lt('Passwort festlegen') : lt('Passwort ändern')) ?></h3>
    <form method="post" action="<?= e(Members::url('konto/passwort')) ?>" class="mb-form">
      <?= csrf_field() ?>
      <input type="email" name="email" value="<?= e($m['email']) ?>" autocomplete="username" hidden>
      <?= $f(['name' => 'password', 'label' => lt('Neues Passwort'), 'type' => 'password', 'value' => '', 'auto' => 'new-password', 'hint' => lt('Mindestens {n} Zeichen.', ['n' => $min])]) ?>
      <?= $f(['name' => 'password2', 'label' => lt('Neues Passwort wiederholen'), 'type' => 'password', 'value' => '', 'auto' => 'new-password']) ?>
      <button class="btn btn--secondary"><?= e(lt('Passwort speichern')) ?></button>
    </form>
    <p class="mb-hint"><?= e(lt('Nach jeder Änderung erhalten Sie eine E-Mail; andere Geräte werden abgemeldet.')) ?></p>
    <?php endif; ?>
  </section>

  <div class="mb-actions">
    <form method="post" action="<?= e(Members::url('abmelden')) ?>"><?= csrf_field() ?><button class="btn btn--secondary"><?= e(lt('Abmelden')) ?></button></form>
  </div>
</div>
<script src="<?= e(Members::asset('js/members.js')) ?>" defer></script>
