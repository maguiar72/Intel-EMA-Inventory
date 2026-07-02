<?php
require_once __DIR__ . '/db.php';
$active = 'grp';
$rows = [];
try {
    $rows = db()->query(
        "SELECT g.*, (SELECT COUNT(*) FROM endpoints e WHERE e.group_id=g.group_id) AS live_count "
      . "FROM endpoint_groups g ORDER BY g.name")->fetchAll();
} catch (Throwable $ex) { $err = $ex->getMessage(); }
require __DIR__ . '/header.php';
?>
<h1>Grupos de Endpoints <span class="count"><?= count($rows) ?></span></h1>
<div class="table-wrap">
<table class="grid">
  <thead><tr>
    <th>Nome</th><th>Descricao</th><th>Perfil AMT</th>
    <th>Dispositivos (EMA)</th><th>Dispositivos (coletados)</th><th>Atualizado</th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="endpoints.php?group_id=<?= urlencode($r['group_id']) ?>"><?= e($r['name'] ?: $r['group_id']) ?></a></td>
        <td><?= e($r['description']) ?></td>
        <td><?= e($r['amt_profile_id']) ?></td>
        <td><?= e($r['endpoint_count']) ?></td>
        <td><?= (int)$r['live_count'] ?></td>
        <td><?= e($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Sem grupos. Rode o coletor.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/footer.php'; ?>
