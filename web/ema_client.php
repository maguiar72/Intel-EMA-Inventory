<?php
/**
 * Cliente REST minimo do Intel EMA para o front-end web.
 *
 * Usado pelo botao "Atualizar hardware agora" (refresh_hardware.php) para
 * consultar o AMT em tempo real via GET endpoints/{id}/HardwareInfoFromAmt.
 * Espelha a autenticacao do coletor Python (Client Credentials -> Bearer).
 */

require_once __DIR__ . '/db.php';

class EmaClient
{
    private array $cfg;
    private string $base;
    private ?string $token = null;

    public function __construct(array $cfg)
    {
        $this->cfg  = $cfg;
        $this->base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    }

    /** Obtem o token de acesso (client_credentials ou password). */
    public function authenticate(): void
    {
        $url  = $this->base . '/api/token';
        $flow = strtolower((string)($this->cfg['auth_flow'] ?? 'client_credentials'));

        if ($flow === 'client_credentials') {
            $body = [
                'grant_type'    => 'client_credentials',
                'client_id'     => (string)($this->cfg['client_id'] ?? ''),
                'client_secret' => (string)($this->cfg['client_secret'] ?? ''),
            ];
            if (!empty($this->cfg['scope'])) {
                $body['scope'] = (string)$this->cfg['scope'];
            }
        } else {
            $body = [
                'grant_type' => 'password',
                'username'   => (string)($this->cfg['username'] ?? ''),
                'password'   => (string)($this->cfg['password'] ?? ''),
            ];
        }

        [$code, $resp] = $this->http('POST', $url, http_build_query($body),
            ['Content-Type: application/x-www-form-urlencoded']);
        if ($code >= 400 || $resp === false || $resp === null) {
            throw new RuntimeException("Falha na autenticacao no EMA (HTTP $code).");
        }
        $data = json_decode((string)$resp, true);
        $this->token = $data['access_token'] ?? $data['accessToken'] ?? $data['token'] ?? null;
        if (!$this->token) {
            throw new RuntimeException('Token nao retornado pelo EMA.');
        }
    }

    /** GET num caminho da API (relativo a /api/{versao}/). Retorna array|null. */
    public function get(string $path)
    {
        $ver = (string)($this->cfg['api_version'] ?? 'latest');
        $url = $this->base . '/api/' . $ver . '/' . ltrim($path, '/');
        [$code, $resp] = $this->http('GET', $url, null,
            ['Authorization: Bearer ' . $this->token, 'Accept: application/json']);
        if ($code >= 400) {
            throw new RuntimeException("EMA respondeu HTTP $code em $path", $code);
        }
        if ($resp === '' || $resp === false || $resp === null) {
            return null;
        }
        return json_decode((string)$resp, true);
    }

    /** Requisicao HTTP via cURL. Retorna [http_code, corpo]. */
    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL do PHP nao esta disponivel.');
        }
        $verify = !empty($this->cfg['verify_ssl']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 60),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false && $err !== '') {
            throw new RuntimeException("Erro de rede ao acessar o EMA: $err");
        }
        return [$code, $resp];
    }
}

/**
 * Extrai as colunas indexadas do JSON do HardwareInfoFromAmt (aninhado, com
 * listas p/ CPU/memoria). Espelha extract_hardware_fields() do coletor Python.
 */
function hw_extract_fields(array $hw): array
{
    $nested = static function (array $data, string $path) {
        $cur = $data;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
            } else {
                return null;
            }
        }
        return (is_scalar($cur) && $cur !== '') ? $cur : null;
    };

    // CPU: 1o processador da lista.
    $cpu = null;
    if (!empty($hw['AmtProcessorInfo'][0]) && is_array($hw['AmtProcessorInfo'][0])) {
        $p = $hw['AmtProcessorInfo'][0];
        $cpu = $p['Version'] ?? $p['ProcessorName'] ?? $p['ManufacturerName'] ?? null;
    }

    // Memoria: soma o Size (KB) dos modulos e converte p/ GB.
    $mem = null;
    if (!empty($hw['AmtMemoryModuleInfo']) && is_array($hw['AmtMemoryModuleInfo'])) {
        $kb = 0;
        foreach ($hw['AmtMemoryModuleInfo'] as $m) {
            if (is_array($m) && isset($m['Size']) && is_numeric($m['Size'])) {
                $kb += (float) $m['Size'];
            }
        }
        if ($kb > 0) {
            $gb = $kb / (1024 * 1024);
            $mem = (abs($gb - round($gb)) < 0.05)
                 ? sprintf('%d GB', (int) round($gb))
                 : sprintf('%.1f GB', $gb);
        }
    }

    $cut = static fn($v) => ($v === null || $v === '') ? null : mb_substr((string)$v, 0, 250);

    return [
        'manufacturer'  => $cut($nested($hw, 'AmtPlatformInfo.ManufacturerName') ?? $nested($hw, 'AmtBaseBoardInfo.ManufacturerName')),
        'model'         => $cut($nested($hw, 'AmtPlatformInfo.ComputerModel') ?? $nested($hw, 'AmtBaseBoardInfo.ProductName')),
        'serial_number' => $cut($nested($hw, 'AmtPlatformInfo.SerialNumber') ?? $nested($hw, 'AmtBaseBoardInfo.SerialNumber')),
        'bios_version'  => $cut($nested($hw, 'AmtBiosInfo.VersionNumber') ?? $nested($hw, 'AmtBiosInfo.Version')),
        'cpu_desc'      => $cut($cpu),
        'total_memory'  => $cut($mem),
    ];
}
