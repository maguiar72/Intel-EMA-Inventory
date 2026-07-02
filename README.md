Intel EMA Inventory
===================

Solução de **relatório/inventário dos desktops gerenciados pelo Intel® Endpoint
Management Assistant (Intel® EMA)**, construída sobre a **API REST oficial** do
EMA (não acessa o banco do produto diretamente — isso não é suportado pela Intel
e quebra a cada upgrade).

Arquitetura (LAMP)
------------------

```
 ┌──────────────┐   API REST /OAuth2   ┌────────────────────┐   SQL   ┌──────────────┐
 │  Intel EMA    │ ───────────────────► │  Coletor (Python)   │ ──────► │  MariaDB/MySQL│
 │  Server       │   token + paginação  │  ema_collector.py   │ upsert  │ ema_inventory │
 └──────────────┘                       └────────────────────┘         └──────┬───────┘
                                                                               │ SELECT
                                                                        ┌──────▼───────┐
                                                                        │ Apache + PHP  │
                                                                        │  painel web   │
                                                                        │ busca/export  │
                                                                        └──────────────┘
```

- **Coletor Python** (`collector/ema_collector.py`): autentica via OAuth2, pagina
  todos os recursos do EMA (dispositivos, grupos, perfis AMT, hardware) e grava no
  MariaDB. Guarda o **JSON bruto** de cada objeto (fidelidade total) + campos-chave
  indexados. Rode via **cron**.
- **Front-end PHP** (`web/`): páginas HTML com **busca por múltiplos parâmetros**,
  filtros por grupo/energia/conexão/modo de controle, tela de detalhes com **todos**
  os campos retornados pela API, e **exportação em HTML, Excel e CSV** (respeitando
  os filtros aplicados).

Por que API e não banco direto?
--------------------------------

| | API REST (esta solução) | Banco SQL do EMA direto |
|---|---|---|
| Suporte Intel | ✅ Oficial | ❌ Não suportado |
| Estabilidade | ✅ Versionada (v2/v3/latest) | ❌ Schema muda em upgrades |
| Segurança | ✅ OAuth2 + RBAC | ⚠️ Amplia acesso ao SQL do produto |
| Impacto | ✅ Nenhum no serviço | ⚠️ Locks/performance |

Instalação
----------

### 1. Banco de dados
```bash
mysql -u root -p < schema.sql
# crie os usuarios ema_web (SELECT) e ema_collector (leitura/escrita) — ver fim do schema.sql
```

### 2. Coletor
```bash
cd collector
pip3 install -r requirements.txt
cp ../config.example.ini ../config.ini
# edite ../config.ini com URL do EMA, usuario da API e credenciais do banco
python3 ema_collector.py --config ../config.ini      # primeira coleta
```
Agende com cron (ver `collector/ema-inventory.cron`).

> O usuário da API no EMA precisa ter **role de leitura** que enxergue os grupos/tenants
> desejados (o EMA usa RBAC). Sem a role certa, a lista virá vazia.

### 3. Front-end web
```bash
sudo mkdir -p /var/www/ema-inventory
sudo cp -r . /var/www/ema-inventory/
sudo cp web/config.php /var/www/ema-inventory/web/config.php   # edite as credenciais
sudo cp apache/ema-inventory.conf /etc/apache2/sites-available/
sudo a2ensite ema-inventory && sudo systemctl reload apache2
# requer: apache2, php, php-mysql  (apt install apache2 php php-mysql php-xml php-zip)

# Exportacao Excel nativa (.xlsx) via PhpSpreadsheet:
cd /var/www/ema-inventory
composer install        # cria vendor/ (opcional; sem ele, o Excel cai no fallback .xls)
```
Acesse: `http://ema-inventory.suaempresa.com.br/`

> **Excel:** se o `vendor/` do Composer existir, a exportação gera **.xlsx nativo**
> (cabeçalho em negrito, auto-filtro, colunas auto-ajustadas, tudo como texto para
> não corromper MAC/serial). Sem o Composer, cai automaticamente num `.xls` baseado
> em HTML — nenhum código a mudar.

### 4. Autenticação do painel

Configure em `web/config.php` no bloco `auth`:

| `mode`  | Como funciona | Quando usar |
|---------|---------------|-------------|
| `none`  | Sem login | Rede interna confiável / testes |
| `basic` | HTTP Basic Auth validado pelo PHP contra `basic_users` | Poucos usuários, simples |
| `sso`   | Confia na identidade que o Apache/SSO coloca em `REMOTE_USER` | Basic Auth do Apache, ou OIDC (Azure AD, Okta, Keycloak, Google) |

**Modo `basic`** — gere o hash da senha e cole em `basic_users`:
```bash
php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
```
Com PHP-FPM/CGI, habilite `CGIPassAuth On` no `<Directory>` (ver apache/ema-inventory.conf).

**Modo `sso`** — deixe `sso_header = 'REMOTE_USER'` e configure a autenticação no
Apache. O arquivo `apache/ema-inventory.conf` traz exemplos prontos de **Basic Auth
via .htpasswd** (Opção A) e **OpenID Connect via mod_auth_openidc** (Opção B).
Opcionalmente restrinja quem entra com a allowlist `allowed_users`.

Segurança
---------
- `config.ini` (credenciais da API) fica **fora** do DocumentRoot e é ignorado pelo git.
- `config.php` só é lido pelo PHP; o VirtualHost ainda nega acesso direto a ele.
- Use um usuário MySQL **somente-leitura** (`ema_web`) para o front-end.
- Habilite **HTTPS** (certbot) e, de preferência, restrinja o acesso ao painel por
  rede/autenticação (Basic Auth ou SSO na frente do Apache).

Compatibilidade de campos
--------------------------
Os nomes de campo da API do EMA variam entre versões. O coletor extrai campos-chave
de forma **defensiva** (tenta vários nomes) e sempre guarda o JSON completo em `raw`,
então a tela de **Detalhes** mostra 100% dos dados mesmo que uma coluna resumida
fique vazia. Se sua versão do EMA usar caminhos diferentes para hardware, ajuste a
lista em `fetch_hardware()` — consulte `https://<seu_ema>/swagger`.

Estrutura de arquivos
---------------------
```
Intel-EMA-Inventory/
├── schema.sql                  # DDL MySQL/MariaDB
├── config.example.ini          # modelo de config do coletor
├── composer.json               # dependencia PhpSpreadsheet (export .xlsx)
├── collector/
│   ├── ema_collector.py        # coletor (cron)
│   ├── requirements.txt
│   └── ema-inventory.cron
├── web/                        # painel PHP (DocumentRoot)
│   ├── config.php  db.php  header.php  footer.php
│   ├── auth.php  logout.php     # autenticacao (none/basic/sso)
│   ├── index.php               # painel/dashboard
│   ├── endpoints.php           # lista + busca + filtros + export
│   ├── detail.php              # todos os campos de um dispositivo
│   ├── groups.php  profiles.php
│   ├── export.php              # HTML / Excel (.xlsx) / CSV
│   └── assets/style.css
└── apache/ema-inventory.conf   # VirtualHost + exemplos Basic Auth e OIDC
```
