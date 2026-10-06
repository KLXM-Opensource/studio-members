<?php
/** Ein Eingabefeld. @var string $name  @var string $label  @var string $type  @var string $value  @var array $errors  @var string $auto  @var string $hint  @var bool $required */
$id = 'mb-' . $name;
$err = $errors[$name] ?? null;
$hintId = ($hint ?? '') !== '' ? $id . '-hint' : '';
$errId = $err ? $id . '-err' : '';
$desc = trim($hintId . ' ' . $errId);
?>
<div class="mb-f<?= $err ? ' mb-f--err' : '' ?>">
  <label for="<?= e($id) ?>"><?= e($label) ?></label>
  <?php if (($type ?? 'text') === 'textarea'): ?>
  <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="5" maxlength="2000"<?= $desc ? ' aria-describedby="' . e($desc) . '"' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>><?= e($value ?? '') ?></textarea>
  <?php else: ?>
  <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type ?? 'text') ?>" value="<?= e($value ?? '') ?>"<?= !empty($required) ? ' required' : '' ?><?= ($auto ?? '') !== '' ? ' autocomplete="' . e($auto) . '"' : '' ?><?= $desc ? ' aria-describedby="' . e($desc) . '"' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>>
  <?php endif; ?>
  <?php if ($hintId): ?><small class="mb-hint" id="<?= e($hintId) ?>"><?= e($hint) ?></small><?php endif; ?>
  <?php if ($err): ?><small class="mb-err" id="<?= e($errId) ?>"><?= e($err) ?></small><?php endif; ?>
</div>
