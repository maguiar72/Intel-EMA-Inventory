<?php
/**
 * Autenticacao configuravel do painel.
 *
 * Chame require_auth() antes de qualquer saida (o modo 'basic' precisa
 * enviar o cabecalho 401/WWW-Authenticate). Retorna o nome do usuario
 * autenticado e o disponibiliza via current_user().
 *
 * Modos (config.php -> 'auth' -> 'mode'):
 *   none  : sem autenticacao.
 *   basic : HTTP Basic Auth validado pelo PHP contra 'basic_users'.
 *   sso   : confia na identidade posta por Apache/SSO em 'sso_header'.
 */

require_once __DIR__ . '/db.php';

function auth_cfg(): array {
    $c = cfg();
    return $c['auth'] ?? ['mode' => 'none'];
}

function current_user(): ?string {
    return $GLOBALS['__ema_user'] ?? null;
}

/** Le a identidade fornecida pelo servidor (REMOTE_USER ou header de proxy). */
function _server_identity(string $name): string {
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return (string) $_SERVER[$name];
    }
    // Headers HTTP chegam prefixados: X-Remote-User -> HTTP_X_REMOTE_USER
    $h = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$h] ?? '');
}

/** Verifica allowlist opcional. */
function _user_allowed(string $user, array $cfg): bool {
    $allow = $cfg['allowed_users'] ?? [];
    if (!is_array($allow) || count($allow) === 0) {
        return true;
    }
    return in_array($user, $allow, true);
}

function _deny_basic(): void {
    header('WWW-Authenticate: Basic realm="Intel EMA Inventory"');
    http_response_code(401);
    echo 'Autenticacao necessaria.';
    exit;
}

function _deny_forbidden(string $msg = 'Acesso negado.'): void {
    http_response_code(403);
    echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    exit;
}

/**
 * Exige autenticacao conforme o modo configurado.
 * @return string usuario autenticado
 */
function require_auth(): string {
    $cfg = auth_cfg();
    $mode = $cfg['mode'] ?? 'none';

    if ($mode === 'none') {
        return $GLOBALS['__ema_user'] = 'anonimo';
    }

    if ($mode === 'sso') {
        $headerName = $cfg['sso_header'] ?? 'REMOTE_USER';
        $user = _server_identity($headerName);
        if ($user === '') {
            _deny_forbidden('Identidade SSO ausente. Verifique a configuracao do Apache/SSO.');
        }
        if (!_user_allowed($user, $cfg)) {
            _deny_forbidden('Usuario sem permissao de acesso ao painel.');
        }
        return $GLOBALS['__ema_user'] = $user;
    }

    if ($mode === 'basic') {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        $users = $cfg['basic_users'] ?? [];

        if ($user === '' || !isset($users[$user]) || !password_verify($pass, (string)$users[$user])) {
            _deny_basic();
        }
        if (!_user_allowed($user, $cfg)) {
            _deny_forbidden('Usuario sem permissao de acesso ao painel.');
        }
        return $GLOBALS['__ema_user'] = $user;
    }

    _deny_forbidden('Modo de autenticacao invalido em config.php.');
    return ''; // inalcancavel
}
