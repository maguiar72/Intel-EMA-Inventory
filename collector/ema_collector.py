#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Intel EMA Inventory Collector
=============================

Autentica na API REST do Intel(R) Endpoint Management Assistant (EMA),
percorre todos os recursos disponiveis (endpoints/dispositivos, grupos,
perfis AMT e inventario de hardware) e grava o resultado num banco
MySQL / MariaDB.

Estrategia de fidelidade de dados
---------------------------------
Cada registro guarda o JSON *bruto* retornado pela API na coluna `raw`,
de forma que a pagina web consegue exibir 100% dos campos, mesmo os que
mudam entre versoes do EMA. Alem disso, os campos mais usados sao
extraidos de forma defensiva (tentando varios nomes de chave possiveis)
para colunas indexadas, o que viabiliza busca e filtros rapidos.

Uso
---
    python3 ema_collector.py --config /caminho/config.ini

Agende via cron (ver ema-inventory.cron).
"""

import argparse
import configparser
import datetime
import json
import logging
import os
import sys
import time

try:
    import requests
except ImportError:
    sys.exit("Falta a dependencia 'requests'. Rode: pip install -r requirements.txt")

try:
    import pymysql
except ImportError:
    sys.exit("Falta a dependencia 'PyMySQL'. Rode: pip install -r requirements.txt")


# ---------------------------------------------------------------------------
#  Utilidades
# ---------------------------------------------------------------------------
def now():
    return datetime.datetime.now()


def pick(d, *keys, default=None):
    """Retorna o primeiro valor presente e nao-vazio dentre varias chaves.

    Aceita chaves aninhadas com ponto, ex: 'Group.Name'. A busca e
    case-INSENSITIVE: o EMA devolve as chaves em PascalCase
    (EndpointGroupId, AmtProfileId, Name, EndpointCount...), enquanto o
    coletor as referencia em camelCase. Sem isso, ids de grupo/perfil
    saem None e os registros sao silenciosamente descartados.
    """
    if not isinstance(d, dict):
        return default
    for key in keys:
        cur = d
        ok = True
        for part in key.split('.'):
            if not isinstance(cur, dict):
                ok = False
                break
            if part in cur:                      # match exato (mais rapido)
                cur = cur[part]
                continue
            plow = part.lower()                  # fallback case-insensitive
            match = next((k for k in cur if k.lower() == plow), None)
            if match is None:
                ok = False
                break
            cur = cur[match]
        if ok and cur not in (None, ""):
            return cur
    return default


def as_text(value):
    """Normaliza qualquer valor para texto curto adequado a uma coluna."""
    if value is None:
        return None
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False)[:250]
    return str(value)[:250]


def parse_dt(value):
    """Converte timestamps ISO8601 do EMA (ex.: '2025-10-21T06:03:13Z') em
    datetime, adequado a colunas DATETIME. Retorna None se nao reconhecer.
    """
    if not value:
        return None
    if isinstance(value, datetime.datetime):
        return value
    s = str(value).strip().replace('T', ' ').replace('Z', '')
    if not s:
        return None
    # s[:19] descarta fuso ('+00:00') e fracao de segundo, se houver.
    try:
        return datetime.datetime.strptime(s[:19], '%Y-%m-%d %H:%M:%S')
    except ValueError:
        pass
    try:
        return datetime.datetime.strptime(s[:10], '%Y-%m-%d')
    except ValueError:
        return None


def is_connected(ep):
    """True se o endpoint aparenta estar conectado ao EMA (via agente ou
    CIRA/AMT). Condicao necessaria p/ a consulta de hardware AMT em tempo
    real ter chance de retornar dados (hardware nao fica no banco do EMA).
    """
    return bool(pick(ep, 'isConnected')) or bool(pick(ep, 'isCiraConnected'))


# ---------------------------------------------------------------------------
#  Cliente da API EMA
# ---------------------------------------------------------------------------
class EmaClient:
    def __init__(self, cfg):
        self.base = cfg['base_url'].rstrip('/')
        # Fluxo de autenticacao: 'client_credentials' (padrao no EMA recente,
        # equivale ao -useCCAuth) ou 'password' (Resource Owner).
        self.auth_flow = cfg.get('auth_flow', fallback='client_credentials').strip().lower()
        # Client Credentials (system-to-system)
        self.client_id = cfg.get('client_id', fallback='').strip()
        self.client_secret = cfg.get('client_secret', fallback='').strip()
        self.scope = cfg.get('scope', fallback='').strip()
        # Resource Owner Password (fallback)
        self.username = cfg.get('username', fallback='').strip()
        self.password = cfg.get('password', fallback='').strip()
        self.verify = cfg.getboolean('verify_ssl', fallback=True)
        self.api_version = cfg.get('api_version', 'latest')
        self.page_size = cfg.getint('page_size', fallback=200)
        self.timeout = cfg.getint('timeout', fallback=60)
        self.session = requests.Session()
        self.token = None
        if not self.verify:
            requests.packages.urllib3.disable_warnings()

    # -- autenticacao ------------------------------------------------------
    def authenticate(self):
        url = f"{self.base}/api/token"
        logging.info("Autenticando em %s (fluxo=%s)", url, self.auth_flow)

        if self.auth_flow == 'client_credentials':
            # client_id = GUID gerado no EMA; client_secret = segredo do par.
            # (Aceita username/password como fallback caso client_id/secret
            #  nao tenham sido preenchidos separadamente.)
            cid = self.client_id or self.username
            csecret = self.client_secret or self.password
            if not cid or not csecret:
                raise RuntimeError("client_id/client_secret ausentes para o fluxo "
                                   "client_credentials. Preencha no config.ini.")
            body = {
                'grant_type': 'client_credentials',
                'client_id': cid,
                'client_secret': csecret,
            }
            if self.scope:
                body['scope'] = self.scope
        else:
            body = {
                'grant_type': 'password',
                'username': self.username,
                'password': self.password,
            }

        resp = self.session.post(
            url, data=body,
            headers={'Content-Type': 'application/x-www-form-urlencoded'},
            verify=self.verify, timeout=self.timeout,
        )
        if resp.status_code >= 400:
            # Loga o corpo da resposta p/ diagnostico (invalid_client,
            # unsupported_grant_type, invalid_scope, etc.).
            detail = (resp.text or '').strip()[:800]
            logging.error("Falha na autenticacao (HTTP %s). Resposta do EMA: %s",
                          resp.status_code, detail or '(vazio)')
            resp.raise_for_status()

        data = resp.json()
        self.token = pick(data, 'access_token', 'accessToken', 'token')
        if not self.token:
            raise RuntimeError(f"Token nao encontrado na resposta: {data}")
        self.session.headers.update({'Authorization': f'Bearer {self.token}'})
        logging.info("Autenticado com sucesso.")

    def _url(self, path):
        path = path.lstrip('/')
        if path.startswith('api/'):
            return f"{self.base}/{path}"
        return f"{self.base}/api/{self.api_version}/{path}"

    def get(self, path, params=None):
        resp = self.session.get(
            self._url(path), params=params,
            verify=self.verify, timeout=self.timeout,
        )
        resp.raise_for_status()
        if not resp.content:
            return None
        return resp.json()

    def probe(self, path):
        """Faz um GET sem levantar excecao e resume a resposta.

        Usado pelo modo --debug-probe para descobrir quais caminhos de
        recurso essa instancia do EMA realmente expoe (status HTTP, tamanho
        da lista extraida e um trecho do corpo).
        """
        url = self._url(path)
        try:
            resp = self.session.get(url, verify=self.verify, timeout=self.timeout)
        except Exception as exc:  # noqa: BLE001
            return {'path': path, 'url': url, 'status': None,
                    'count': None, 'snippet': f'ERRO: {exc}'}
        status = resp.status_code
        count = None
        snippet = (resp.text or '')[:200].replace('\n', ' ')
        if resp.ok and resp.content:
            try:
                count = len(self._extract_list(resp.json()))
            except ValueError:
                snippet = '(resposta nao-JSON) ' + snippet
        return {'path': path, 'url': url, 'status': status,
                'count': count, 'snippet': snippet}

    def get_list(self, path):
        """Percorre um recurso de lista lidando com paginacao e formatos.

        O EMA pode devolver uma lista pura ou um objeto envelope contendo a
        lista (chaves como 'value', 'items', 'endpoints', 'data').

        Estrategia: esta instancia do EMA devolve a colecao INTEIRA numa
        unica resposta e, para varios recursos (endpointGroups, amtProfiles),
        RESPONDE VAZIO quando recebe parametros de paginacao desconhecidos.
        Por isso tentamos primeiro SEM parametros; so caimos para paginacao
        explicita se a resposta encostar exatamente no tamanho de pagina
        (indicio de que pode haver continuacao).
        """
        # 1) Tentativa limpa, sem parametros de paginacao.
        try:
            first = self._extract_list(self.get(path))
        except requests.HTTPError as exc:
            logging.debug("GET sem params falhou em %s (%s); tentando paginar.",
                          path, exc)
            first = None

        # Resposta que nao encosta no page_size e completa (o servidor
        # devolveu tudo de uma vez). Cobre grupos (57), perfis (3) e o parque
        # inteiro de endpoints (>page_size numa unica resposta).
        if first is not None and len(first) != self.page_size:
            return first

        # 2) Ou a resposta sem params falhou, ou trouxe exatamente page_size
        #    itens (pode haver mais). Pagina explicitamente, deduplicando.
        collected = list(first) if first else []
        seen = {self._item_signature(it) for it in collected}
        skip = self.page_size if collected else 0
        page = 2 if collected else 1
        while True:
            # Tenta variantes de paginacao mais comuns em APIs .NET/EMA.
            params = {'$top': self.page_size, '$skip': skip,
                      'count': self.page_size, 'start': skip,
                      'limit': self.page_size, 'offset': skip,
                      'page': page, 'pageSize': self.page_size}
            try:
                data = self.get(path, params=params)
            except requests.HTTPError as exc:
                # Paginacao nao suportada -> ficamos com o que ja temos.
                logging.debug("Paginacao rejeitada em %s (%s); usando resposta base.",
                              path, exc)
                break

            items = self._extract_list(data)
            if not items:
                break

            # Deduplica por identidade do item. Muitas versoes do EMA IGNORAM
            # os parametros de paginacao e devolvem SEMPRE a lista completa; sem
            # esta checagem, cada "pagina" reinsere o conjunto inteiro e o loop
            # so para no limite de seguranca, acumulando ate 10.000x os dados em
            # memoria -> processo morto pelo OOM killer.
            new_items = []
            for it in items:
                sig = self._item_signature(it)
                if sig in seen:
                    continue
                seen.add(sig)
                new_items.append(it)

            # Nenhum item novo nesta pagina => o servidor esta reenviando o
            # mesmo conjunto (paginacao nao honrada). Encerramos.
            if not new_items:
                if page > 1:
                    logging.debug("Paginacao nao honrada em %s; encerrando na "
                                  "pagina %s (sem itens novos).", path, page)
                break

            collected.extend(new_items)

            # Pagina "curta" (menos itens que o tamanho pedido) indica fim
            # natural quando a paginacao e respeitada.
            if len(items) < self.page_size:
                break
            skip += self.page_size
            page += 1
            if page > 10000:  # trava de seguranca
                logging.warning("Paginacao excedeu limite em %s", path)
                break
        return collected

    # Chaves de identidade preferenciais p/ deduplicar itens entre paginas.
    _ID_KEYS = ('endpointId', 'EndpointId', 'id', 'Id', 'guid',
                'endpointGroupId', 'groupId', 'amtProfileId', 'profileId')

    @classmethod
    def _item_signature(cls, item):
        """Assinatura estavel de um item p/ detectar duplicatas entre paginas."""
        if isinstance(item, dict):
            for key in cls._ID_KEYS:
                val = item.get(key)
                if val not in (None, ""):
                    return f"{key}={val}"
            try:
                return json.dumps(item, sort_keys=True, ensure_ascii=False)
            except TypeError:
                return repr(item)
        return repr(item)

    @staticmethod
    def _extract_list(data):
        if data is None:
            return []
        if isinstance(data, list):
            return data
        if isinstance(data, dict):
            for key in ('value', 'items', 'data', 'endpoints', 'results',
                        'Endpoints', 'Items', 'Value'):
                if isinstance(data.get(key), list):
                    return data[key]
            # objeto unico
            return [data]
        return []


# ---------------------------------------------------------------------------
#  Camada de banco de dados
# ---------------------------------------------------------------------------
class Db:
    def __init__(self, cfg):
        self.conn = pymysql.connect(
            host=cfg.get('host', '127.0.0.1'),
            port=cfg.getint('port', fallback=3306),
            user=cfg['user'],
            password=cfg['password'],
            database=cfg['name'],
            charset='utf8mb4',
            autocommit=False,
        )

    def start_run(self, base_url):
        with self.conn.cursor() as cur:
            cur.execute(
                "INSERT INTO collection_runs (started_at, status, ema_base_url) "
                "VALUES (%s, 'running', %s)", (now(), base_url))
        self.conn.commit()
        return self.conn.insert_id()

    def finish_run(self, run_id, status, counts, message=None):
        with self.conn.cursor() as cur:
            cur.execute(
                "UPDATE collection_runs SET finished_at=%s, status=%s, "
                "endpoints_count=%s, groups_count=%s, profiles_count=%s, "
                "hardware_count=%s, message=%s WHERE id=%s",
                (now(), status, counts.get('endpoints', 0),
                 counts.get('groups', 0), counts.get('profiles', 0),
                 counts.get('hardware', 0), message, run_id))
        self.conn.commit()

    def upsert_endpoint(self, ep, run_id):
        eid = pick(ep, 'endpointId', 'EndpointId', 'id', 'Id', 'guid')
        if not eid:
            return False
        vals = {
            'endpoint_id': str(eid),
            'name': as_text(pick(ep, 'name', 'Name', 'hostName', 'computerName')),
            'fqdn': as_text(pick(ep, 'fqdn', 'FQDN', 'dnsSuffix', 'fullyQualifiedDomainName')),
            'domain': as_text(pick(ep, 'domain', 'Domain', 'domainName')),
            'os_desc': as_text(pick(ep, 'osDescription', 'operatingSystem', 'os', 'clientOS')),
            'ip_address': as_text(pick(ep, 'ipAddress', 'IPAddress', 'ip', 'wiredIPAddress')),
            'mac_address': as_text(pick(ep, 'macAddress', 'MACAddress', 'mac', 'wiredMACAddress')),
            'amt_version': as_text(pick(ep, 'amtVersion', 'AMTVersion', 'meshAgentVersion',
                                        'MEVersion')),
            'power_state': as_text(pick(ep, 'powerState', 'PowerState', 'amtPowerState')),
            'connection_status': as_text(pick(ep, 'connectionStatus', 'agentStatus',
                                              'meshConnStatus', 'online', 'isConnected')),
            'control_mode': as_text(pick(ep, 'controlMode', 'amtControlMode',
                                         'provisioningMode', 'activationMode')),
            'provisioning_state': as_text(pick(ep, 'provisioningState', 'amtProvisioningState',
                                               'setupState')),
            'group_id': as_text(pick(ep, 'endpointGroupId', 'groupId', 'EndpointGroupId', 'Group.Id')),
            'group_name': as_text(pick(ep, 'endpointGroupName', 'groupName', 'Group.Name')),
            'last_seen': parse_dt(pick(ep, 'lastSeen', 'lastContact', 'lastConnected',
                                       'LastUpdate', 'lastUpdate', 'lastCommunication')),
            'raw': json.dumps(ep, ensure_ascii=False),
            'ts': now(),
            'run_id': run_id,
        }
        sql = """
        INSERT INTO endpoints
          (endpoint_id, name, fqdn, domain, os_desc, ip_address, mac_address,
           amt_version, power_state, connection_status, control_mode,
           provisioning_state, group_id, group_name, last_seen, raw,
           first_collected, updated_at, last_run_id)
        VALUES
          (%(endpoint_id)s, %(name)s, %(fqdn)s, %(domain)s, %(os_desc)s,
           %(ip_address)s, %(mac_address)s, %(amt_version)s, %(power_state)s,
           %(connection_status)s, %(control_mode)s, %(provisioning_state)s,
           %(group_id)s, %(group_name)s, %(last_seen)s, %(raw)s,
           %(ts)s, %(ts)s, %(run_id)s)
        ON DUPLICATE KEY UPDATE
           name=VALUES(name), fqdn=VALUES(fqdn), domain=VALUES(domain),
           os_desc=VALUES(os_desc), ip_address=VALUES(ip_address),
           mac_address=VALUES(mac_address), amt_version=VALUES(amt_version),
           power_state=VALUES(power_state), connection_status=VALUES(connection_status),
           control_mode=VALUES(control_mode), provisioning_state=VALUES(provisioning_state),
           group_id=VALUES(group_id), group_name=VALUES(group_name),
           raw=VALUES(raw), updated_at=VALUES(updated_at), last_run_id=VALUES(last_run_id)
        """
        with self.conn.cursor() as cur:
            cur.execute(sql, vals)
        return True

    def upsert_group(self, g, run_id):
        gid = pick(g, 'endpointGroupId', 'groupId', 'id', 'Id')
        if not gid:
            return False
        vals = {
            'group_id': str(gid),
            'name': as_text(pick(g, 'name', 'Name', 'groupName')),
            'description': as_text(pick(g, 'description', 'Description')),
            'endpoint_count': pick(g, 'endpointCount', 'count', 'numberOfEndpoints'),
            'amt_profile_id': as_text(pick(g, 'amtProfileId', 'intelAmtProfileId')),
            'raw': json.dumps(g, ensure_ascii=False),
            'ts': now(),
            'run_id': run_id,
        }
        sql = """
        INSERT INTO endpoint_groups
          (group_id, name, description, endpoint_count, amt_profile_id, raw,
           first_collected, updated_at, last_run_id)
        VALUES
          (%(group_id)s, %(name)s, %(description)s, %(endpoint_count)s,
           %(amt_profile_id)s, %(raw)s, %(ts)s, %(ts)s, %(run_id)s)
        ON DUPLICATE KEY UPDATE
           name=VALUES(name), description=VALUES(description),
           endpoint_count=VALUES(endpoint_count), amt_profile_id=VALUES(amt_profile_id),
           raw=VALUES(raw), updated_at=VALUES(updated_at), last_run_id=VALUES(last_run_id)
        """
        with self.conn.cursor() as cur:
            cur.execute(sql, vals)
        return True

    def upsert_profile(self, p, run_id):
        pid = pick(p, 'amtProfileId', 'profileId', 'id', 'Id')
        if not pid:
            return False
        vals = {
            'profile_id': str(pid),
            'name': as_text(pick(p, 'name', 'Name', 'profileName')),
            'description': as_text(pick(p, 'description', 'Description')),
            'activation_mode': as_text(pick(p, 'activationMode', 'controlMode', 'mode')),
            'raw': json.dumps(p, ensure_ascii=False),
            'ts': now(),
            'run_id': run_id,
        }
        sql = """
        INSERT INTO amt_profiles
          (profile_id, name, description, activation_mode, raw,
           first_collected, updated_at, last_run_id)
        VALUES
          (%(profile_id)s, %(name)s, %(description)s, %(activation_mode)s,
           %(raw)s, %(ts)s, %(ts)s, %(run_id)s)
        ON DUPLICATE KEY UPDATE
           name=VALUES(name), description=VALUES(description),
           activation_mode=VALUES(activation_mode), raw=VALUES(raw),
           updated_at=VALUES(updated_at), last_run_id=VALUES(last_run_id)
        """
        with self.conn.cursor() as cur:
            cur.execute(sql, vals)
        return True

    def upsert_hardware(self, endpoint_id, hw, run_id):
        vals = {
            'endpoint_id': str(endpoint_id),
            # HardwareInfoFromAmt e aninhado (AmtPlatformInfo/AmtBiosInfo/...).
            # AmtPlatformInfo.* confirmados no probe; demais com fallback plano.
            'manufacturer': as_text(pick(hw,
                'AmtPlatformInfo.ManufacturerName', 'AmtPlatformInfo.Manufacturer',
                'manufacturer', 'Manufacturer', 'systemManufacturer')),
            'model': as_text(pick(hw,
                'AmtPlatformInfo.ComputerModel', 'AmtPlatformInfo.Model',
                'model', 'Model', 'systemModel', 'productName')),
            'serial_number': as_text(pick(hw,
                'AmtPlatformInfo.SerialNumber', 'AmtBaseboardInfo.SerialNumber',
                'serialNumber', 'SerialNumber', 'serial')),
            'bios_version': as_text(pick(hw,
                'AmtBiosInfo.Version', 'AmtBiosInfo.BiosVersion',
                'biosVersion', 'BiosVersion', 'biosVersionString')),
            'cpu_desc': as_text(pick(hw,
                'AmtProcessorInfo.Version', 'AmtProcessorInfo.ProcessorName',
                'processor', 'cpu', 'processorName', 'cpuDescription')),
            'total_memory': as_text(pick(hw,
                'AmtMemoryInfo.TotalMemory', 'AmtPlatformInfo.TotalMemory',
                'totalMemory', 'memory', 'installedMemory', 'ramSize')),
            'raw': json.dumps(hw, ensure_ascii=False),
            'ts': now(),
            'run_id': run_id,
        }
        sql = """
        INSERT INTO hardware_inventory
          (endpoint_id, manufacturer, model, serial_number, bios_version,
           cpu_desc, total_memory, raw, updated_at, last_run_id)
        VALUES
          (%(endpoint_id)s, %(manufacturer)s, %(model)s, %(serial_number)s,
           %(bios_version)s, %(cpu_desc)s, %(total_memory)s, %(raw)s,
           %(ts)s, %(run_id)s)
        ON DUPLICATE KEY UPDATE
           manufacturer=VALUES(manufacturer), model=VALUES(model),
           serial_number=VALUES(serial_number), bios_version=VALUES(bios_version),
           cpu_desc=VALUES(cpu_desc), total_memory=VALUES(total_memory),
           raw=VALUES(raw), updated_at=VALUES(updated_at), last_run_id=VALUES(last_run_id)
        """
        with self.conn.cursor() as cur:
            cur.execute(sql, vals)

    def commit(self):
        self.conn.commit()

    def close(self):
        self.conn.close()


# ---------------------------------------------------------------------------
#  Orquestracao
# ---------------------------------------------------------------------------
def run(config_path):
    cfg = configparser.ConfigParser()
    if not cfg.read(config_path):
        sys.exit(f"Nao consegui ler o arquivo de config: {config_path}")

    setup_logging(cfg['log'] if cfg.has_section('log') else {})

    client = EmaClient(cfg['ema'])
    db = Db(cfg['database'])
    counts = {'endpoints': 0, 'groups': 0, 'profiles': 0, 'hardware': 0}
    run_id = db.start_run(client.base)
    logging.info("Iniciando coleta (run_id=%s)", run_id)

    try:
        client.authenticate()

        # --- Grupos ------------------------------------------------------
        logging.info("Coletando grupos de endpoints...")
        for g in client.get_list('endpointGroups'):
            if db.upsert_group(g, run_id):
                counts['groups'] += 1
        db.commit()
        logging.info("Grupos coletados: %s", counts['groups'])

        # --- Perfis AMT --------------------------------------------------
        logging.info("Coletando perfis AMT...")
        try:
            # perfis AMT ficam sob /api/v2/amtProfiles em algumas versoes
            profiles = client.get_list('amtProfiles')
            if not profiles:
                profiles = client.get_list('api/v2/amtProfiles')
        except requests.HTTPError:
            profiles = client.get_list('api/v2/amtProfiles')
        for p in profiles:
            if db.upsert_profile(p, run_id):
                counts['profiles'] += 1
        db.commit()
        logging.info("Perfis coletados: %s", counts['profiles'])

        # --- Endpoints ---------------------------------------------------
        logging.info("Coletando endpoints/dispositivos...")
        endpoints = client.get_list('endpoints')
        endpoint_ids = []
        connected_ids = []
        for ep in endpoints:
            if db.upsert_endpoint(ep, run_id):
                counts['endpoints'] += 1
                eid = pick(ep, 'endpointId', 'EndpointId', 'id', 'Id', 'guid')
                if eid:
                    eid = str(eid)
                    endpoint_ids.append(eid)
                    if is_connected(ep):
                        connected_ids.append(eid)
            if counts['endpoints'] % 200 == 0:
                db.commit()
        db.commit()
        logging.info("Endpoints coletados: %s (conectados: %s)",
                     counts['endpoints'], len(connected_ids))

        # --- Inventario de hardware por dispositivo ----------------------
        # Hardware NAO fica no banco do EMA: e lido do Intel AMT em tempo real
        # (GET endpoints/{id}/HardwareInfoFromAmt) e so responde p/ hosts
        # CONECTADOS com AMT provisionada. Por isso consultamos apenas os
        # conectados, e nao os 11 mil (a maioria offline).
        if cfg['ema'].getboolean('collect_hardware', fallback=True):
            if not connected_ids:
                logging.info("Nenhum endpoint conectado no momento; hardware AMT "
                             "(tempo real) nao e coletavel agora.")
            else:
                # Canary: 1 chamada p/ detectar cedo falta de permissao (403) ou
                # caminho ausente (404) e evitar milhares de chamadas inuteis.
                canary = client.probe(
                    f"endpoints/{connected_ids[0]}/HardwareInfoFromAmt")
                st = canary['status']
                if st in (401, 403):
                    logging.warning(
                        "Sem permissao p/ ler hardware AMT (HTTP %s em "
                        "HardwareInfoFromAmt). A conta da API (client_credentials) le "
                        "o inventario, mas nao possui direitos de manageability/AMT "
                        "sobre os grupos de endpoints no EMA. Conceda esses direitos a "
                        "conta no EMA p/ habilitar a coleta de hardware. Pulando etapa.",
                        st)
                elif st == 404:
                    logging.warning(
                        "HardwareInfoFromAmt indisponivel neste servidor (HTTP 404). "
                        "Pulando etapa de hardware.")
                else:
                    logging.info("Coletando hardware AMT em tempo real de %s host(s) "
                                 "conectado(s)...", len(connected_ids))
                    for i, eid in enumerate(connected_ids, 1):
                        hw = fetch_hardware(client, eid)
                        if hw:
                            db.upsert_hardware(eid, hw, run_id)
                            counts['hardware'] += 1
                        if i % 50 == 0:
                            db.commit()
                            logging.info("  ...hardware %s/%s", i, len(connected_ids))
                    db.commit()
                    logging.info("Hardware coletado: %s de %s conectados",
                                 counts['hardware'], len(connected_ids))

        db.finish_run(run_id, 'ok', counts)
        logging.info("Coleta concluida com sucesso: %s", counts)

    except Exception as exc:  # noqa: BLE001 - queremos registrar qualquer falha
        logging.exception("Falha na coleta")
        db.finish_run(run_id, 'error', counts, message=str(exc)[:2000])
        db.close()
        sys.exit(1)

    db.close()


def hardware_path_candidates(eid):
    """Lista de caminhos candidatos p/ inventario de hardware de um endpoint.

    Cobre dois padroes de URL observados na API do EMA: o sub-recurso
    ('endpoints/{id}/PlatformCapabilities') e o recurso-primeiro
    ('amtSetups/endpoints/{id}').
    """
    subresources = [
        'HardwareInfoFromAmt',  # caminho oficial (script Intel)
        'HardwareInfo', 'hardwareInfo', 'HardwareInformation', 'hardwareInformation',
        'hardware', 'Hardware', 'hardwareAssets', 'HardwareAssets',
        'amtHardwareInfo', 'AmtHardwareInfo', 'amtHardwareAssets',
        'GeneralInfo', 'generalInfo', 'amtGeneralInfo', 'AmtGeneralInfo',
        'amtGeneralSettings', 'generalSettings', 'systemInfo', 'SystemInfo',
        'computerSystem', 'platformInfo', 'PlatformInfo', 'assets', 'inventory',
    ]
    resource_first = [
        'amtHardwareInfo', 'hardwareInfo', 'amtHardware', 'hardware',
        'endpointHardware', 'amtHardwareAssets', 'amtGeneralInfo',
    ]
    paths = [f"endpoints/{eid}/{sr}" for sr in subresources]
    paths += [f"{rf}/endpoints/{eid}" for rf in resource_first]
    # 'PlatformCapabilities' e conhecido (200) -> referencia de sanidade.
    paths.append(f"endpoints/{eid}/PlatformCapabilities")
    return paths


def debug_probe(config_path, probe_endpoint=None):
    """Autentica e testa varios caminhos candidatos p/ grupos, perfis e
    hardware. Ajuda a descobrir o caminho certo de cada recurso: caminho
    errado (404/erro) vs. lista realmente vazia (200 + count=0).

    Se probe_endpoint for informado, os caminhos de hardware sao testados
    contra ESSE endpoint (util p/ usar uma maquina que voce sabe estar
    online e com hardware visivel no web UI). Caso contrario, usa o
    primeiro endpoint retornado pela API.
    """
    cfg = configparser.ConfigParser()
    if not cfg.read(config_path):
        sys.exit(f"Nao consegui ler o arquivo de config: {config_path}")
    setup_logging(cfg['log'] if cfg.has_section('log') else {})

    client = EmaClient(cfg['ema'])
    client.authenticate()

    candidates = [
        # grupos de endpoints
        'endpointGroups', 'EndpointGroups', 'endpointgroups', 'groups',
        'api/v2/endpointGroups', 'api/v1/endpointGroups',
        # perfis AMT
        'amtProfiles', 'AMTProfiles', 'amtprofiles', 'profiles',
        'api/v2/amtProfiles', 'api/v1/amtProfiles',
        # referencia: endpoints (sabemos que funciona)
        'endpoints',
    ]
    logging.info("Sondando %s caminhos candidatos (grupos/perfis)...", len(candidates))
    logging.info("%-32s %-6s %-8s %s", "CAMINHO", "HTTP", "ITENS", "TRECHO")
    for path in candidates:
        r = client.probe(path)
        logging.info("%-32s %-6s %-8s %s",
                     r['path'], r['status'],
                     '-' if r['count'] is None else r['count'],
                     r['snippet'][:120])

    # --- Hardware ---------------------------------------------------------
    eid = probe_endpoint
    if not eid:
        logging.info("Buscando 1 endpoint de amostra p/ sondar hardware...")
        sample = client.get_list('endpoints')[:1]
        if not sample:
            logging.warning("Nenhum endpoint disponivel p/ sondar hardware.")
            return
        eid = pick(sample[0], 'endpointId', 'EndpointId', 'id', 'Id', 'guid')
    logging.info("Sondando caminhos de hardware p/ endpoint_id=%s", eid)
    logging.info("(dica: passe --probe-endpoint <ID de uma maquina ONLINE> "
                 "p/ testar contra um host com hardware visivel no web UI)")
    hw_paths = hardware_path_candidates(eid)
    logging.info("%-42s %-6s %-8s %s", "CAMINHO", "HTTP", "ITENS", "TRECHO")
    hits = []
    for path in hw_paths:
        r = client.probe(path)
        status = r['status']
        # Destaca respostas promissoras (2xx que nao sejam o proprio endpoint).
        if status and 200 <= status < 300:
            hits.append(path)
        logging.info("%-42s %-6s %-8s %s",
                     path, status,
                     '-' if r['count'] is None else r['count'],
                     r['snippet'][:120])
    if hits:
        logging.info("Caminhos de hardware que responderam 2xx: %s", hits)
    else:
        logging.info("Nenhum caminho de hardware respondeu 2xx para esse host.")


def fetch_hardware(client, endpoint_id):
    """Le o inventario de hardware AMT (em tempo real) de um endpoint.

    Caminho oficial (script Intel Get-IntelEMAEndpointAMTHardwareInfo):
    GET endpoints/{id}/HardwareInfoFromAmt. O dado nao fica no banco do
    EMA -- e consultado no Intel AMT no momento da chamada -- entao so
    retorna se o dispositivo estiver conectado e com AMT provisionada.
    Os demais sao fallbacks p/ outras versoes/nomenclaturas.
    """
    candidates = [
        f"endpoints/{endpoint_id}/HardwareInfoFromAmt",
        f"endpoints/{endpoint_id}/HardwareInfo",
        f"endpoints/{endpoint_id}/hardwareInfo",
    ]
    for path in candidates:
        try:
            data = client.get(path)
            if data:
                return data
        except requests.HTTPError as exc:
            if exc.response is not None and exc.response.status_code in (404, 400):
                continue
            logging.debug("Erro ao buscar hardware em %s: %s", path, exc)
        except Exception as exc:  # noqa: BLE001
            logging.debug("Erro inesperado em %s: %s", path, exc)
    return None


def setup_logging(log_cfg):
    level = getattr(logging, str(log_cfg.get('level', 'INFO')).upper(), logging.INFO)
    handlers = [logging.StreamHandler(sys.stdout)]
    log_file = log_cfg.get('file') if hasattr(log_cfg, 'get') else None
    if log_file:
        try:
            os.makedirs(os.path.dirname(log_file), exist_ok=True)
            handlers.append(logging.FileHandler(log_file, encoding='utf-8'))
        except OSError:
            pass
    logging.basicConfig(
        level=level,
        format='%(asctime)s %(levelname)-7s %(message)s',
        handlers=handlers,
    )


def main():
    parser = argparse.ArgumentParser(description="Coletor de inventario Intel EMA")
    parser.add_argument('--config', default=os.path.join(
        os.path.dirname(__file__), '..', 'config.ini'),
        help="Caminho do config.ini (padrao: ../config.ini)")
    parser.add_argument('--debug-probe', action='store_true',
        help="Nao coleta; apenas testa caminhos de recurso (grupos/perfis/"
             "hardware) e mostra status HTTP e contagem de itens de cada um.")
    parser.add_argument('--probe-endpoint', metavar='ENDPOINT_ID', default=None,
        help="Com --debug-probe: testa os caminhos de hardware contra este "
             "endpoint especifico (use o ID de uma maquina ONLINE, com "
             "hardware visivel no web UI).")
    args = parser.parse_args()
    start = time.time()
    if args.debug_probe:
        debug_probe(os.path.abspath(args.config), probe_endpoint=args.probe_endpoint)
    else:
        run(os.path.abspath(args.config))
    logging.info("Tempo total: %.1fs", time.time() - start)


if __name__ == '__main__':
    main()
