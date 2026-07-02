<?php
/**
 * Logout.
 *  - basic: reenvia 401 para o navegador esquecer as credenciais.
 *  - sso:   a sessao e controlada pelo provedor SSO/Apache; oriente o
 *           usuario a encerrar por la (redireciona para a raiz).
 *  - none:  nada a fazer.
 */
require_once __DIR__ . '/db.php';
$mode = (cfg()['auth']['mode'] ?? 'none');

if ($mode === 'basic') {
    header('WWW-Authenticate: Basic realm="Intel EMA Inventory"');
    http_response_code(401);
    echo '<p>Voce saiu. <a href="index.php">Entrar novamente</a>.</p>';
    exit;
}

// sso / none
header('Location: index.php');
echo 'Redirecionando...';
