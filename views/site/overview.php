<?php
/** Übersicht nach der Anmeldung: freigegebene Bereiche, Konto. @var array $member  @var array $areas  @var array $groups */
use Klxm\Members\Members;
?>
<div class="mb-card">
  <div class="mb-hello">
    <a href="<?= e(Members::url('konto')) ?>" class="mb-hello__pic" aria-label="<?= e(lt('Mein Konto')) ?>"><?= \Klxm\Members\Avatar::html($member, 'mb-avatar mb-avatar--l', 96) ?></a>
    <div><p class="mb-eyebrow"><?= e(lt('Mitgliederbereich')) ?></p>
      <h1 class="mb-title"><?= e(lt('Willkommen, {name}', ['name' => $member['name'] ?: $member['email']])) ?></h1></div>
  </div>
  <?php include __DIR__ . '/_notice.php'; ?>
  <?php if ($areas): ?>
  <ul class="mb-areas" role="list">
    <?php foreach ($areas as $p): ?>
    <li><a href="<?= e(\Core\Pages::url($p)) ?>"><span class="mb-areas__t"><?= e($p['nav_title'] ?: $p['title']) ?></span>
      <?php if (trim((string) $p['meta_description']) !== ''): ?><span class="mb-areas__d"><?= e($p['meta_description']) ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="mb-lead"><?= e(lt('Für Ihr Konto sind noch keine Bereiche freigegeben.')) ?></p>
  <?php endif; ?>
  <div class="mb-actions">
    <a class="btn btn--secondary" href="<?= e(Members::url('konto')) ?>"><?= e(lt('Mein Konto')) ?></a>
    <form method="post" action="<?= e(Members::url('abmelden')) ?>"><?= csrf_field() ?><button class="btn btn--secondary"><?= e(lt('Abmelden')) ?></button></form>
  </div>
</div>
