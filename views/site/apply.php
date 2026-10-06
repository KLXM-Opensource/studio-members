<?php
/** Zugang beantragen. @var array $errors  @var array $values  @var string $text */
use Klxm\Members\Members;
$f = fn(array $v) => \Core\Theme::capture(__DIR__ . '/_field.php', $v + ['errors' => $errors]);
$privacy = privacy_url();
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e(lt('Zugang beantragen')) ?></h1>
  <p class="mb-lead"><?= trim($text) !== '' ? nl2br(e($text), false) : e(lt('Senden Sie uns Ihre Angaben. Nach der Prüfung erhalten Sie eine E-Mail mit dem Link zum Einrichten Ihres Zugangs.')) ?></p>
  <?php include __DIR__ . '/_notice.php'; ?>
  <form method="post" action="<?= e(Members::url('zugang')) ?>" class="mb-form" novalidate>
    <?= csrf_field() ?>
    <?= $f(['name' => 'name', 'label' => lt('Ihr Name'), 'value' => $values['name'] ?? '', 'auto' => 'name', 'required' => true]) ?>
    <?= $f(['name' => 'email', 'label' => lt('E-Mail-Adresse'), 'type' => 'email', 'value' => $values['email'] ?? '', 'auto' => 'email', 'required' => true]) ?>
    <?= $f(['name' => 'message', 'label' => lt('Nachricht (optional)'), 'type' => 'textarea', 'value' => $values['message'] ?? '', 'hint' => lt('z. B. Mitgliedsnummer, Verein oder Funktion')]) ?>
    <div class="mb-trap" aria-hidden="true"><label for="mb-website">Website</label><input id="mb-website" name="website" tabindex="-1" autocomplete="off"></div>
    <div class="mb-f mb-f--check<?= isset($errors['consent']) ? ' mb-f--err' : '' ?>">
      <label><input type="checkbox" name="consent" value="1"<?= isset($errors['consent']) ? ' aria-invalid="true" aria-describedby="mb-consent-err"' : '' ?>>
        <span><?= e(lt('Ich bin einverstanden, dass meine Angaben zur Prüfung des Antrags gespeichert werden.')) ?><?php if ($privacy !== ''): ?> <a href="<?= e($privacy) ?>"><?= e(lt('Datenschutz')) ?></a><?php endif; ?></span></label>
      <?php if (isset($errors['consent'])): ?><small class="mb-err" id="mb-consent-err"><?= e($errors['consent']) ?></small><?php endif; ?>
    </div>
    <button class="btn btn--primary"><?= e(lt('Antrag senden')) ?></button>
  </form>
  <p class="mb-foot"><a href="<?= e(Members::url('anmelden')) ?>"><?= e(lt('Zur Anmeldung')) ?></a></p>
</div>
