<?php
/**
 * Acao do botao "Atualizar hardware agora" da tela de detalhe.
 *
 * Consulta o Intel EMA em tempo real (endpoints/{id}/HardwareInfoFromAmt),
 * grava/atualiza a linha em hardware_inventory e redireciona de volta para
 * detail.php com um status. So aceita POST e exige autenticacao.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ema_client.php';
require_auth();

$id   = (string)($_POST['id'] ?? '');
$back = 'detail.php?id=' . urlencode($id);

function redirect_hw(string $back, string $status): void
{
    header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&') . 'hw=' . $status);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id === '') {
    redirect_hw($back, 'error');
}

// So atualiza dispositivos que ja existem no inventario.
$stmt = db()->prepare("SELECT endpoint_id FROM endpoints WHERE endpoint_id = ?");
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    redirect_hw($back, 'notfound');
}

$emaCfg = cfg()['ema'] ?? [];
if (empty($emaCfg['client_id']) && empty($emaCfg['username'])) {
    redirect_hw($back, 'noconfig');
}

try {
    $ema = new EmaClient($emaCfg);
    $ema->authenticate();

    try {
        $hw = $ema->get("endpoints/$id/HardwareInfoFromAmt");
    } catch (RuntimeException $ex) {
        // 403 = credencial sem scope EndpointManager; 4xx = host indisponivel.
        $code = $ex->getCode();
        redirect_hw($back, ($code === 401 || $code === 403) ? 'forbidden' : 'unavailable');
    }

    if (!is_array($hw) || !$hw) {
        redirect_hw($back, 'empty');
    }

    $f = hw_extract_fields($hw);
    $sql = "INSERT INTO hardware_inventory
        (endpoint_id, manufacturer, model, serial_number, bios_version,
         cpu_desc, total_memory, raw, updated_at, last_run_id)
        VALUES
        (:endpoint_id, :manufacturer, :model, :serial_number, :bios_version,
         :cpu_desc, :total_memory, :raw, NOW(), NULL)
        ON DUPLICATE KEY UPDATE
         manufacturer=VALUES(manufacturer), model=VALUES(model),
         serial_number=VALUES(serial_number), bios_version=VALUES(bios_version),
         cpu_desc=VALUES(cpu_desc), total_memory=VALUES(total_memory),
         raw=VALUES(raw), updated_at=VALUES(updated_at)";
    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':endpoint_id'  => $id,
        ':manufacturer' => $f['manufacturer'],
        ':model'        => $f['model'],
        ':serial_number'=> $f['serial_number'],
        ':bios_version' => $f['bios_version'],
        ':cpu_desc'     => $f['cpu_desc'],
        ':total_memory' => $f['total_memory'],
        ':raw'          => json_encode($hw, JSON_UNESCAPED_UNICODE),
    ]);

    redirect_hw($back, 'ok');
} catch (Throwable $ex) {
    error_log('refresh_hardware: ' . $ex->getMessage());
    redirect_hw($back, 'error');
}
