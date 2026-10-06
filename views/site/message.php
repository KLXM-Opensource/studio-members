<?php
/** Hinweisseite. @var string $title  @var string $text  @var array $links [[label, href], …] */
?>
<div class="mb-card mb-card--narrow">
  <h1 class="mb-title"><?= e($title) ?></h1>
  <?php include __DIR__ . '/_notice.php'; ?>
  <p class="mb-lead"><?= e($text) ?></p>
  <?php if ($links): ?><div class="mb-actions"><?php foreach ($links as [$label, $href]): ?><a class="btn btn--secondary" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></div><?php endif; ?>
</div>
