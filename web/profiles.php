<?php
require_once __DIR__ . '/db.php';
$active = 'prof';
$rows = [];
try {
    $rows = db()->query("SELECT * FROM amt_profiles ORDER BY name")->fetchAll();
} catch (Throwable $ex) { $err = $ex->getMessage(); }
require __DIR__ . '/header.php';
?>
<h1>Perfis Intel AMT <span class="count"><?= count($rows) ?></span></h1>
<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Nome</th><th>Descricao</th><th>Modo de Ativacao</th><th>ID</th><th>Atualizado</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name'] ?: $r['profile_id']) ?></td>
        <td><?= e($r['description']) ?></td>
        <td><?= e($r['activation_mode']) ?></td>
        <td><?= e($r['profile_id']) ?></td>
        <td><?= e($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Sem perfis. Rode o coletor.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/footer.php'; ?>
