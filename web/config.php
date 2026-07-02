<?php
/**
 * Configuracao do front-end web (LAMP).
 * Ajuste as credenciais do banco de leitura.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'ema_inventory',
        'user'     => 'ema_web',
        'password' => 'TROQUE_ESTA_SENHA',
        'charset'  => 'utf8mb4',
    ],
    // Titulo exibido no cabecalho das paginas
    'app_title' => 'Intel EMA - Inventario de Desktops',
    // Quantos registros por pagina na listagem
    'page_size' => 50,

    // -----------------------------------------------------------------
    //  Autenticacao do painel
    //  mode:
    //    'none'  -> sem autenticacao (use so em rede interna confiavel)
    //    'basic' -> HTTP Basic Auth validado pelo proprio PHP
    //    'sso'   -> confia na identidade fornecida por Apache/SSO
    //               (mod_auth_openidc, mod_auth_mellon, Basic Auth do
    //                Apache, etc.) via variavel de servidor.
    // -----------------------------------------------------------------
    'auth' => [
        'mode' => 'none',

        // Para mode='basic': usuario => hash bcrypt.
        // Gere o hash com:
        //   php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
        'basic_users' => [
            // 'admin' => '$2y$10$exemploDeHashSubstituaPeloReal..............',
        ],

        // Para mode='sso': variavel de servidor com a identidade do usuario
        // preenchida pelo Apache/proxy. 'REMOTE_USER' e a mais segura pois
        // e definida pelo modulo de auth do Apache (nao falsificavel pelo
        // cliente). Se usar um header de proxy (ex: 'X-Remote-User'),
        // GARANTA que o proxy remove esse header vindo do cliente.
        'sso_header' => 'REMOTE_USER',

        // Opcional: allowlist. Se preenchida, somente estes usuarios entram.
        'allowed_users' => [],
    ],
];
