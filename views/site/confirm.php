<?php
/** Bestätigungslink aus der E-Mail (GET verbraucht nichts). @var string $token */
use Klxm\Members\Members;
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e(lt('Änderung bestätigen')) ?></h1>
  <p class="mb-lead"><?= e(lt('Bestätigen Sie, dass Sie Passwort oder Passkeys Ihres Zugangs ändern möchten.')) ?></p>
  <form method="post" action="<?= e(Members::url('bestaetigen/' . $token)) ?>">
    <?= csrf_field() ?>
    <button class="btn btn--primary mb-btn-wide"><?= e(lt('Ja, ich bin es')) ?></button>
  </form>
</div>
