<?php
require_once __DIR__ . '/db.php';
$active = 'dash';

function scalar($sql) {
    try { return (int) db()->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$totalEndpoints = scalar("SELECT COUNT(*) FROM endpoints");
$totalGroups    = scalar("SELECT COUNT(*) FROM endpoint_groups");
$totalProfiles  = scalar("SELECT COUNT(*) FROM amt_profiles");
$totalHardware  = scalar("SELECT COUNT(*) FROM hardware_inventory");

// Distribuicoes uteis
function distrib($col) {
    try {
        return db()->query(
            "SELECT COALESCE(NULLIF($col,''),'(vazio)') AS k, COUNT(*) c "
          . "FROM endpoints GROUP BY k ORDER BY c DESC LIMIT 12")->fetchAll();
    } catch (Throwable $e) { return []; }
}
$byControl = distrib('control_mode');
$byPower   = distrib('power_state');
$byConn    = distrib('connection_status');

require __DIR__ . '/header.php';
?>
<h1>Painel</h1>
<div class="cards">
  <div class="card"><div class="n"><?= $totalEndpoints ?></div><div class="l">Dispositivos</div></div>
  <div class="card"><div class="n"><?= $totalGroups ?></div><div class="l">Grupos</div></div>
  <div class="card"><div class="n"><?= $totalProfiles ?></div><div class="l">Perfis AMT</div></div>
  <div class="card"><div class="n"><?= $totalHardware ?></div><div class="l">Inventarios de HW</div></div>
</div>

<div class="cards">
  <?php foreach (['Modo de Controle'=>$byControl,'Estado de Energia'=>$byPower,'Conexao do Agente'=>$byConn] as $titulo=>$rows): ?>
  <div class="panel">
    <h2><?= e($titulo) ?></h2>
    <table class="kv">
      <?php foreach ($rows as $r): ?>
        <tr><th><?= e($r['k']) ?></th><td><?= (int)$r['c'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td class="muted">Sem dados. Rode o coletor.</td></tr><?php endif; ?>
    </table>
  </div>
  <?php endforeach; ?>
</div>

<p class="muted">Use a aba <a href="endpoints.php">Dispositivos</a> para buscar, filtrar e exportar (HTML, Excel, CSV).</p>
<?php require __DIR__ . '/footer.php'; ?>
