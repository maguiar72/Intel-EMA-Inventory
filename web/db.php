<?php
/**
 * Conexao PDO e helpers compartilhados entre as paginas.
 */

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Escapa para HTML. */
function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Achata um JSON aninhado em pares "chave.caminho" => valor escalar. */
function flatten_json($data, string $prefix = ''): array {
    $out = [];
    if (is_array($data)) {
        $isList = array_keys($data) === range(0, count($data) - 1);
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += flatten_json($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
    } else {
        $out[$prefix] = $data;
    }
    return $out;
}

/**
 * Constroi a clausula WHERE + binds para a listagem de endpoints a partir
 * dos parametros de busca (GET). Reutilizado pela listagem e pela exportacao,
 * garantindo que o export respeite os mesmos filtros.
 *
 * @return array{0:string,1:array} [where_sql, params]
 */
function build_endpoint_filter(array $q): array {
    $where = [];
    $params = [];

    // Busca livre (nome, fqdn, ip, mac, dominio)
    $term = trim($q['search'] ?? '');
    if ($term !== '') {
        $where[] = '(name LIKE :t OR fqdn LIKE :t OR ip_address LIKE :t '
                 . 'OR mac_address LIKE :t OR domain LIKE :t OR endpoint_id LIKE :t)';
        $params[':t'] = '%' . $term . '%';
    }

    // Filtros exatos por coluna
    $exact = [
        'group_id'          => 'group_id',
        'power_state'       => 'power_state',
        'connection_status' => 'connection_status',
        'control_mode'      => 'control_mode',
        'amt_version'       => 'amt_version',
    ];
    foreach ($exact as $param => $col) {
        $val = trim($q[$param] ?? '');
        if ($val !== '') {
            $where[] = "$col = :$param";
            $params[":$param"] = $val;
        }
    }

    $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    return [$sql, $params];
}

/** Valores distintos de uma coluna para popular dropdowns de filtro. */
function distinct_values(string $col): array {
    $allowed = ['power_state', 'connection_status', 'control_mode', 'amt_version'];
    if (!in_array($col, $allowed, true)) {
        return [];
    }
    $rows = db()->query(
        "SELECT DISTINCT $col AS v FROM endpoints "
      . "WHERE $col IS NOT NULL AND $col <> '' ORDER BY v"
    )->fetchAll();
    return array_column($rows, 'v');
}

/** Lista de grupos (id => nome) para o dropdown. */
function group_options(): array {
    $rows = db()->query(
        "SELECT group_id, name FROM endpoint_groups ORDER BY name"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['group_id']] = $r['name'] ?: $r['group_id'];
    }
    return $out;
}

/** Colunas exibidas na listagem/export (chave da coluna => rotulo). */
function endpoint_columns(): array {
    return [
        'name'              => 'Nome',
        'fqdn'              => 'FQDN',
        'domain'            => 'Dominio',
        'os_desc'           => 'Sistema Operacional',
        'ip_address'        => 'IP',
        'mac_address'       => 'MAC',
        'amt_version'       => 'Versao AMT',
        'control_mode'      => 'Modo de Controle',
        'power_state'       => 'Energia',
        'connection_status' => 'Conexao',
        'provisioning_state'=> 'Provisionamento',
        'group_name'        => 'Grupo',
        'updated_at'        => 'Atualizado em',
    ];
}
