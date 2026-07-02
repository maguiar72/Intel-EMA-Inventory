<?php
require_once __DIR__ . '/db.php';
$active = 'ep';

[$where, $params] = build_endpoint_filter($_GET);
$cols = endpoint_columns();

// Paginacao
$pageSize = (int) cfg()['page_size'];
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $pageSize;

$total = 0;
$rows = [];
$dbError = null;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM endpoints $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $sql = "SELECT * FROM endpoints $where ORDER BY name LIMIT $pageSize OFFSET $offset";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$totalPages = max(1, (int)ceil($total / $pageSize));

// Preserva filtros nos links de export/paginacao
$qs = $_GET;
unset($qs['p']);
$baseQs = http_build_query($qs);

function status_badge($col, $val) {
    // Datas -> dd/mm/aaaa
    if (in_array($col, ['updated_at', 'first_collected', 'last_seen'], true)) {
        $d = fmt_datetime($val);
        return $d === '' ? '<span class="muted">-</span>' : e($d);
    }
    // Colunas codificadas -> rotulo amigavel + cor
    if (in_array($col, ['connection_status', 'power_state', 'control_mode'], true)) {
        $label = friendly_value($col, $val);
        $cls = '';
        if ($col === 'connection_status') {
            $cls = $label === 'Conectado' ? 'ok' : ($label === 'Desconectado' ? 'err' : '');
        } elseif ($col === 'power_state') {
            $cls = $label === 'Ligado' ? 'ok' : ($label === 'Desligado' ? 'na' : 'warn');
        } elseif ($col === 'control_mode') {
            $cls = strpos($label, 'ACM') !== false ? 'ok'
                 : (strpos($label, 'CCM') !== false ? 'warn' : '');
        }
        return $cls ? '<span class="badge '.$cls.'">'.e($label).'</span>' : e($label);
    }
    return $val === null || $val === '' ? '<span class="muted">-</span>' : e($val);
}

require __DIR__ . '/header.php';
?>
<h1>Dispositivos <span class="count"><?= $total ?> resultado(s)</span></h1>

<?php if ($dbError): ?>
  <div class="panel"><strong>Erro ao consultar o banco:</strong> <?= e($dbError) ?>
  <br><span class="muted">Verifique as credenciais em config.php e se o schema foi criado.</span></div>
<?php endif; ?>

<div class="panel">
  <form class="filters" method="get" action="endpoints.php">
    <div class="fld" style="min-width:260px">
      <label>Busca (nome, FQDN, IP, MAC, dominio)</label>
      <input type="text" name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="ex: NOTE-, 10.0., 00:1a:...">
    </div>
    <div class="fld">
      <label>Grupo</label>
      <select name="group_id">
        <option value="">Todos</option>
        <?php foreach (group_options() as $gid=>$gname): ?>
          <option value="<?= e($gid) ?>" <?= (($_GET['group_id']??'')===$gid)?'selected':'' ?>><?= e($gname) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
      $selects = [
        'control_mode'      => 'Modo de Controle',
        'power_state'       => 'Energia',
        'connection_status' => 'Conexao',
        'amt_version'       => 'Versao AMT',
      ];
      foreach ($selects as $col=>$lbl):
    ?>
      <div class="fld">
        <label><?= e($lbl) ?></label>
        <select name="<?= e($col) ?>">
          <option value="">Todos</option>
          <?php
            $friendly = in_array($col, ['control_mode','power_state','connection_status'], true);
            foreach (distinct_values($col) as $v):
              $txt = $friendly ? friendly_value($col, $v) : $v;
          ?>
            <option value="<?= e($v) ?>" <?= (($_GET[$col]??'')===$v)?'selected':'' ?>><?= e($txt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endforeach; ?>
    <div class="fld">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Filtrar</button>
    </div>
    <div class="fld">
      <label>&nbsp;</label>
      <a class="btn sec" href="endpoints.php">Limpar</a>
    </div>
  </form>
</div>

<div class="exports">
  <a class="btn sec" href="export.php?format=html<?= $baseQs?'&'.e($baseQs):'' ?>">Exportar HTML</a>
  <a class="btn sec" href="export.php?format=xlsx<?= $baseQs?'&'.e($baseQs):'' ?>">Exportar Excel</a>
  <a class="btn sec" href="export.php?format=csv<?= $baseQs?'&'.e($baseQs):'' ?>">Exportar CSV</a>
</div>

<div class="table-wrap">
<table class="grid">
  <thead>
    <tr>
      <?php foreach ($cols as $key=>$lbl): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($cols as $key=>$lbl): ?>
          <td><?= status_badge($key, $r[$key] ?? '') ?></td>
        <?php endforeach; ?>
        <td><a href="detail.php?id=<?= urlencode($r['endpoint_id']) ?>">detalhes</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows && !$dbError): ?>
      <tr><td colspan="<?= count($cols)+1 ?>" class="muted">Nenhum dispositivo encontrado.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?>
    <a href="endpoints.php?<?= e($baseQs) ?>&p=<?= $page-1 ?>">&laquo; Anterior</a>
  <?php endif; ?>
  <span>Pagina <?= $page ?> de <?= $totalPages ?></span>
  <?php if ($page < $totalPages): ?>
    <a href="endpoints.php?<?= e($baseQs) ?>&p=<?= $page+1 ?>">Proxima &raquo;</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
