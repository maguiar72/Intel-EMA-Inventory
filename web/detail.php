<?php
require_once __DIR__ . '/db.php';
$active = 'ep';

$id = $_GET['id'] ?? '';
$ep = null; $hw = null;
if ($id !== '') {
    $stmt = db()->prepare("SELECT * FROM endpoints WHERE endpoint_id = ?");
    $stmt->execute([$id]);
    $ep = $stmt->fetch();
    $stmt = db()->prepare("SELECT * FROM hardware_inventory WHERE endpoint_id = ?");
    $stmt->execute([$id]);
    $hw = $stmt->fetch();
}

require __DIR__ . '/header.php';

if (!$ep) {
    echo '<div class="panel">Dispositivo nao encontrado. <a href="endpoints.php">Voltar</a></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Renderiza uma tabela chave/valor a partir do JSON bruto achatado. */
function render_raw($json) {
    if (!$json) { echo '<p class="muted">Sem dados brutos.</p>'; return; }
    $data = json_decode($json, true);
    if (!is_array($data)) { echo '<p class="muted">JSON invalido.</p>'; return; }
    $flat = flatten_json($data);
    ksort($flat);
    echo '<table class="kv">';
    foreach ($flat as $k => $v) {
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        echo '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
    }
    echo '</table>';
}
?>
<h1><?= e($ep['name'] ?: $ep['endpoint_id']) ?></h1>
<p><a href="endpoints.php">&laquo; Voltar para a lista</a></p>

<div class="panel">
  <h2>Resumo</h2>
  <table class="kv">
    <?php foreach (endpoint_columns() as $key=>$lbl): ?>
      <tr><th><?= e($lbl) ?></th><td><?= e(friendly_value($key, $ep[$key] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Endpoint ID</th><td><?= e($ep['endpoint_id']) ?></td></tr>
  </table>
</div>

<div class="panel">
  <h2>Dados completos do dispositivo (API EMA)</h2>
  <?php render_raw($ep['raw']); ?>
</div>

<div class="panel">
  <h2>Inventario de Hardware</h2>
  <?php if ($hw): ?>
    <?php render_raw($hw['raw']); ?>
  <?php else: ?>
    <p class="muted">Sem inventario de hardware coletado para este dispositivo.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
