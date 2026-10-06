<?php
/** Anmelde-Link bestätigen (GET verbraucht nichts – Mail-Scanner). @var string $token  @var string $target */
use Klxm\Members\Members;
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e(lt('Anmelden')) ?></h1>
  <p class="mb-lead"><?= e(lt('Bitte bestätigen Sie die Anmeldung mit einem Klick.')) ?></p>
  <form method="post" action="<?= e(Members::url('link/' . $token)) ?>">
    <?= csrf_field() ?><input type="hidden" name="ziel" value="<?= e($target) ?>">
    <button class="btn btn--primary mb-btn-wide"><?= e(lt('Jetzt anmelden')) ?></button>
  </form>
</div>
