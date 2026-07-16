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

    // Busca livre (nome, fqdn, ip, mac, dominio). Cada ocorrencia usa um
    // placeholder proprio: com prepares nativos (EMULATE_PREPARES=false) o
    // MySQL nao permite reusar o mesmo nome -> SQLSTATE[HY093].
    $term = trim($q['search'] ?? '');
    if ($term !== '') {
        $where[] = '(name LIKE :t_name OR fqdn LIKE :t_fqdn OR ip_address LIKE :t_ip '
                 . 'OR mac_address LIKE :t_mac OR domain LIKE :t_domain '
                 . 'OR endpoint_id LIKE :t_eid)';
        $like = '%' . $term . '%';
        $params[':t_name']   = $like;
        $params[':t_fqdn']   = $like;
        $params[':t_ip']     = $like;
        $params[':t_mac']    = $like;
        $params[':t_domain'] = $like;
        $params[':t_eid']    = $like;
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

/**
 * Traduz um valor cru de coluna do EMA/AMT p/ um rotulo amigavel em pt-BR
 * e formata datas como dd/mm/aaaa. Centraliza a exibicao usada por todas as
 * telas e pelos exports, garantindo consistencia. Colunas nao tratadas
 * retornam o valor original.
 */
function friendly_value(string $col, $v): string {
    $s = trim((string)($v ?? ''));
    if ($s === '(vazio)') { $s = ''; }   // rotulo usado nas distribuicoes do painel
    switch ($col) {
        case 'control_mode':   // Intel AMT Control Mode
            $map = [
                '0' => 'Nao ativado',
                '1' => 'Controle pelo Cliente (CCM)',
                '2' => 'Controle pelo Administrador (ACM)',
            ];
            return $map[$s] ?? ($s === '' ? 'Desconhecido' : 'Codigo ' . $s);
        case 'power_state':    // Estado de energia do endpoint
            $map = [
                '0' => 'Ligado',
                '1' => 'Em suspensao',
                '2' => 'Desligado',
            ];
            return $map[$s] ?? ($s === '' ? 'Desconhecido' : 'Codigo ' . $s);
        case 'connection_status':  // IsConnected (agente)
            $l = strtolower($s);
            if (in_array($l, ['true', '1', 'connected', 'online'], true))     return 'Conectado';
            if (in_array($l, ['false', '0', 'disconnected', 'offline'], true)) return 'Desconectado';
            return $s === '' ? 'Desconhecido' : $s;
        case 'provisioning_state':  // Intel AMT Provisioning State
            $map = [
                '0' => 'Nao provisionado',
                '1' => 'Em provisionamento',
                '2' => 'Provisionado',
            ];
            return $map[$s] ?? ($s === '' ? 'Desconhecido' : 'Codigo ' . $s);
        case 'updated_at':
        case 'first_collected':
        case 'last_seen':
            return fmt_datetime($s);
    }
    return $s;
}

/**
 * Traduz um par chave/valor do JSON bruto (tabela "Dados completos") para
 * exibicao amigavel: booleanos -> Verdadeiro/Falso e chaves codificadas
 * conhecidas (PowerState, AmtControlMode, AmtProvisioningState) -> rotulo.
 */
function friendly_raw_value(string $key, $value): string {
    if (is_bool($value)) {
        return $value ? 'Verdadeiro' : 'Falso';
    }
    $s = (string) $value;
    $low = strtolower(trim($s));
    if ($low === 'true')  { return 'Verdadeiro'; }
    if ($low === 'false') { return 'Falso'; }

    // Usa o ultimo segmento da chave achatada (ex.: "Group.PowerState" -> "PowerState").
    $leaf = ($pos = strrpos($key, '.')) !== false ? substr($key, $pos + 1) : $key;
    $coded = [
        'PowerState'           => 'power_state',
        'AmtControlMode'       => 'control_mode',
        'ControlMode'          => 'control_mode',
        'AmtProvisioningState' => 'provisioning_state',
        'ProvisioningState'    => 'provisioning_state',
    ];
    if (isset($coded[$leaf]) && $s !== '') {
        return friendly_value($coded[$leaf], $s);
    }
    return $s;
}

/** Formata 'AAAA-MM-DD HH:MM:SS' -> 'dd/mm/aaaa HH:MM' (pt-BR). */
function fmt_datetime($v): string {
    $s = trim((string)($v ?? ''));
    if ($s === '' || strpos($s, '0000-00-00') === 0) { return ''; }
    try {
        return (new DateTime($s))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $s;
    }
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

/**
 * Colunas da LISTAGEM (compacta, cabe sem scroll lateral). Omite
 * domain/os_desc/ip_address/mac_address, que a API do EMA nao fornece p/
 * estes endpoints (sempre vazias). Detalhe e export usam o conjunto completo.
 */
function endpoint_list_columns(): array {
    return [
        'name'              => 'Nome',
        'fqdn'              => 'FQDN',
        'amt_version'       => 'Versao AMT',
        'control_mode'      => 'Modo de Controle',
        'power_state'       => 'Energia',
        'connection_status' => 'Conexao',
        'provisioning_state'=> 'Provisionamento',
        'group_name'        => 'Grupo',
        'updated_at'        => 'Atualizado em',
    ];
}
