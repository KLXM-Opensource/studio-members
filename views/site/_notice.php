<?php
/** Hinweise und Fehler oben im Formular. @var array $errors  @var array $notice */
foreach ($notice ?? [] as [$type, $msg]): ?>
<p class="mb-note mb-note--<?= e($type === 'error' ? 'error' : 'ok') ?>" role="status"><?= e($msg) ?></p>
<?php endforeach;
if (!empty($errors['_'])): ?>
<p class="mb-note mb-note--error" role="alert"><?= e($errors['_']) ?></p>
<?php endif; ?>
