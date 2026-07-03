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

/** Renderiza uma tabela chave/valor a partir do JSON bruto achatado.
 *  Traduz booleanos (Verdadeiro/Falso) e codigos conhecidos (PowerState,
 *  AmtControlMode, AmtProvisioningState) para rotulos amigaveis. */
function render_raw($json) {
    if (!$json) { echo '<p class="muted">Sem dados brutos.</p>'; return; }
    $data = json_decode($json, true);
    if (!is_array($data)) { echo '<p class="muted">JSON invalido.</p>'; return; }
    $flat = flatten_json($data);
    ksort($flat);
    echo '<table class="kv">';
    foreach ($flat as $k => $v) {
        echo '<tr><th>' . e($k) . '</th><td>' . e(friendly_raw_value($k, $v)) . '</td></tr>';
    }
    echo '</table>';
}
?>
<h1><?= e($ep['name'] ?: $ep['endpoint_id']) ?></h1>
<p><a href="endpoints.php">&laquo; Voltar para a lista</a></p>

<div class="panel">
  <h2>Resumo</h2>
  <table class="kv">
    <?php foreach (endpoint_columns() as $key=>$lbl):
        $val = friendly_value($key, $ep[$key] ?? '');
        if ($val === '') { continue; }   // oculta campos vazios (ex.: IP/MAC/SO nao fornecidos pela API do EMA)
    ?>
      <tr><th><?= e($lbl) ?></th><td><?= e($val) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Endpoint ID</th><td><?= e($ep['endpoint_id']) ?></td></tr>
  </table>
</div>

<div class="panel">
  <h2>Dados completos do dispositivo (API EMA)</h2>
  <details class="raw-details">
    <summary>Ver dados completos</summary>
    <?php render_raw($ep['raw']); ?>
  </details>
</div>

<div class="panel">
  <h2>Inventario de Hardware</h2>

  <?php
    // Mensagem de status do botao "Atualizar hardware agora".
    $hwMsgs = [
      'ok'          => ['ok',  'Hardware atualizado com sucesso a partir do Intel EMA.'],
      'empty'       => ['warn','O Intel EMA nao retornou hardware. O dispositivo provavelmente esta offline ou com o AMT desconectado no momento (o dado e lido em tempo real).'],
      'unavailable' => ['warn','Nao foi possivel obter o hardware deste dispositivo agora (host offline / AMT indisponivel).'],
      'forbidden'   => ['err', 'Sem permissao para ler hardware AMT. A credencial do EMA precisa do scope EndpointManager.'],
      'noconfig'    => ['err', 'Conexao com o Intel EMA nao configurada. Preencha [ema] client_id/client_secret em config.php.'],
      'notfound'    => ['err', 'Dispositivo nao encontrado.'],
      'error'       => ['err', 'Falha ao consultar o Intel EMA. Verifique credenciais, rede e o log do servidor web.'],
    ];
    $hwKey = $_GET['hw'] ?? '';
    if (isset($hwMsgs[$hwKey])):
      [$cls, $txt] = $hwMsgs[$hwKey];
  ?>
    <div class="notice <?= e($cls) ?>"><?= e($txt) ?></div>
  <?php endif; ?>

  <?php if (!empty(cfg()['ema']['client_id']) || !empty(cfg()['ema']['username'])): ?>
    <form method="post" action="refresh_hardware.php" class="hw-refresh">
      <input type="hidden" name="id" value="<?= e($ep['endpoint_id']) ?>">
      <button class="btn" type="submit">&#128260; Atualizar hardware agora (consultar Intel EMA)</button>
      <span class="muted">Consulta o AMT em tempo real; funciona apenas se o dispositivo estiver conectado.</span>
    </form>
  <?php endif; ?>

  <?php if ($hw): ?>
    <p class="muted">Atualizado em <?= e(fmt_datetime($hw['updated_at'] ?? '')) ?></p>
    <details class="raw-details">
      <summary>Ver detalhes do hardware</summary>
      <?php render_raw($hw['raw']); ?>
    </details>
  <?php else: ?>
    <p class="muted">Sem inventario de hardware coletado para este dispositivo. Use o botao acima para consultar o Intel EMA agora.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
