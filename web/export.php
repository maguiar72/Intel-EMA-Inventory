<?php
/**
 * Exportacao dos dispositivos filtrados em HTML, Excel (.xlsx) ou CSV.
 * Reutiliza build_endpoint_filter() para respeitar exatamente os mesmos
 * filtros aplicados na tela de listagem.
 *
 * Excel: usa PhpSpreadsheet (.xlsx nativo) quando instalado via composer;
 *        caso contrario, faz fallback para um .xls baseado em HTML, sem
 *        dependencias.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_auth();

$format = strtolower($_GET['format'] ?? 'csv');
[$where, $params] = build_endpoint_filter($_GET);
$cols = endpoint_columns();

$sql = "SELECT * FROM endpoints $where ORDER BY name";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$fname = 'ema_dispositivos_' . date('Ymd_His');

// ===========================================================================
//  CSV
// ===========================================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fname.csv\"");
    echo "\xEF\xBB\xBF"; // BOM p/ acentuacao no Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, array_values($cols), ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $key => $lbl) { $line[] = $r[$key] ?? ''; }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

// ===========================================================================
//  Excel
// ===========================================================================
if ($format === 'xlsx' || $format === 'xls') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            export_xlsx($rows, $cols, $fname);   // .xlsx nativo (exits)
        }
    }
    export_xls_html($rows, $cols, $fname);       // fallback sem dependencia (exits)
}

// ===========================================================================
//  HTML (pagina standalone para salvar/imprimir)
// ===========================================================================
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="pt-br"><head><meta charset="utf-8">
<title>Export Intel EMA - <?= date('d/m/Y H:i') ?></title>
<style>
 body{font-family:Segoe UI,Arial,sans-serif;margin:24px;color:#1b2733}
 h1{font-size:18px;color:#00285a}
 table{border-collapse:collapse;width:100%;font-size:12px}
 th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}
 th{background:#eef3f8;color:#00285a}
 .meta{color:#5b6b7b;margin-bottom:14px}
 @media print{.noprint{display:none}}
</style></head><body>
<h1>Intel EMA - Inventario de Dispositivos</h1>
<div class="meta">Gerado em <?= date('d/m/Y H:i:s') ?> &mdash; <?= count($rows) ?> registro(s)
  <?php if (!empty($_GET['search'])): ?>&mdash; busca: "<?= e($_GET['search']) ?>"<?php endif; ?>
</div>
<button class="noprint" onclick="window.print()">Imprimir / Salvar PDF</button>
<table>
  <tr><?php foreach ($cols as $lbl): ?><th><?= e($lbl) ?></th><?php endforeach; ?></tr>
  <?php foreach ($rows as $r): ?>
    <tr><?php foreach ($cols as $key=>$lbl): ?><td><?= e($r[$key] ?? '') ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
</table>
</body></html>
<?php
exit;


// ===========================================================================
//  Funcoes de exportacao Excel
// ===========================================================================

/** Gera um .xlsx nativo usando PhpSpreadsheet. */
function export_xlsx(array $rows, array $cols, string $fname): void {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Dispositivos');

    // Cabecalho
    $colIndex = 1;
    foreach ($cols as $key => $lbl) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . '1';
        $sheet->setCellValue($cell, $lbl);
        $colIndex++;
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    $sheet->getStyle("A1:{$lastCol}1")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('EEF3F8');
    $sheet->freezePane('A2');

    // Linhas (tudo como texto p/ nao mangar MAC/serial/IP)
    $rowNum = 2;
    foreach ($rows as $r) {
        $colIndex = 1;
        foreach ($cols as $key => $lbl) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . $rowNum;
            $sheet->setCellValueExplicit(
                $cell,
                (string)($r[$key] ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $colIndex++;
        }
        $rowNum++;
    }

    // Auto-largura
    for ($i = 1; $i <= count($cols); $i++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }
    $sheet->setAutoFilter("A1:{$lastCol}" . max(1, count($rows) + 1));

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$fname.xlsx\"");
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
    exit;
}

/** Fallback: .xls baseado em HTML (Excel abre nativamente), sem dependencias. */
function export_xls_html(array $rows, array $cols, string $fname): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fname.xls\"");
    echo "\xEF\xBB\xBF";
    echo "<html><head><meta charset='utf-8'></head><body><table border='1'>";
    echo '<tr>';
    foreach ($cols as $lbl) { echo '<th>' . e($lbl) . '</th>'; }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $key => $lbl) {
            echo '<td style="mso-number-format:\'\@\'">' . e($r[$key] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
