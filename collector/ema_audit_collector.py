#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Intel EMA AuditEvents Collector -> OpenSearch
=============================================

Coleta de forma incremental os eventos de auditoria do Intel(R) EMA
(recurso AuditEvents da API REST) e os indexa num OpenSearch, para
classificacao, busca e alertas via OpenSearch Dashboards.

Estrategia
----------
- Reaproveita o cliente/auth do coletor de inventario (EmaClient).
- Puxa apenas eventos novos usando um CURSOR (ultimo horario visto),
  persistido num arquivo de estado. Uma pequena sobreposicao de janela e
  segura porque cada evento e indexado com _id = id do evento: reindexar
  o mesmo evento apenas o sobrescreve (sem duplicata).
- Nomes de caminho/parametros da API sao configuraveis (secao [audit]),
  pois variam entre versoes do EMA. Rode com --probe para descobrir o
  formato real (campos de id/horario) da SUA instancia e ajustar o config.

Uso
---
    python3 ema_audit_collector.py --config ../config.ini --probe
    python3 ema_audit_collector.py --config ../config.ini

Agende via cron/systemd timer (ex.: a cada 5-15 min).

Observacao sobre permissao: eventos de auditoria sao dados de tenant.
Se a coleta retornar 403, use uma credencial com scope TenantManager
(pode ser um config.ini separado apontado por --config).
"""

import argparse
import configparser
import datetime
import hashlib
import json
import logging
import os
import sys
import time

try:
    import requests
except ImportError:
    sys.exit("Falta a dependencia 'requests'. Rode: pip install -r requirements.txt")

# Reaproveita autenticacao e helpers do coletor de inventario.
from ema_collector import EmaClient, pick, setup_logging


# ---------------------------------------------------------------------------
#  Estado (cursor incremental)
# ---------------------------------------------------------------------------
def load_state(path):
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return {}


def save_state(path, state):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(state, fh, ensure_ascii=False)
    os.replace(tmp, path)


# ---------------------------------------------------------------------------
#  Extracao defensiva de id e horario do evento
# ---------------------------------------------------------------------------
def event_id(ev):
    return pick(ev, 'auditEventId', 'AuditEventId', 'id', 'Id',
                'eventId', 'EventId', 'auditId', 'guid')


def event_time(ev):
    return pick(ev, 'eventTime', 'EventTime', 'timestamp', 'Timestamp',
                'createdDate', 'CreatedDate', 'dateTime', 'DateTime',
                'date', 'Date', 'time', 'Time', 'occurredOn')


def doc_id(ev):
    """Id estavel p/ o documento no OpenSearch (idempotencia)."""
    eid = event_id(ev)
    if eid not in (None, ''):
        return str(eid)
    blob = json.dumps(ev, sort_keys=True, ensure_ascii=False).encode('utf-8')
    return hashlib.sha1(blob).hexdigest()


# ---------------------------------------------------------------------------
#  Busca dos eventos de auditoria (paginada + filtro incremental por data)
# ---------------------------------------------------------------------------
def fetch_audit(client, acfg, cursor_iso):
    """Percorre o recurso de auditoria a partir de cursor_iso (inclusive),
    lidando com paginacao e deduplicando por id. Retorna lista de eventos.
    """
    path = acfg.get('path', fallback='auditEvents')
    from_param = acfg.get('from_param', fallback='startDateTime').strip()
    # O AuditEvents do EMA NAO pagina: estreita-se por intervalo de data
    # (startDateTime/endDateTime) e ele devolve tudo do intervalo. Deixe
    # paginate=false (padrao). paginate=true fica p/ outras versoes de API.
    paginate = acfg.getboolean('paginate', fallback=False)

    base_params = {}
    if from_param and cursor_iso:
        base_params[from_param] = cursor_iso

    collected = []
    if not paginate:
        collected = list(client._extract_list(client.get(path, params=base_params)))
    else:
        page_size = acfg.getint('page_size', fallback=500)
        top_param = acfg.get('top_param', fallback='$top')
        skip_param = acfg.get('skip_param', fallback='$skip')
        seen = set()
        skip = 0
        page = 1
        while True:
            params = dict(base_params)
            params[top_param] = page_size
            params[skip_param] = skip
            items = client._extract_list(client.get(path, params=params))
            if not items:
                break
            new = 0
            for ev in items:
                key = doc_id(ev)
                if key in seen:
                    continue
                seen.add(key)
                collected.append(ev)
                new += 1
            if new == 0 or len(items) != page_size:
                break
            skip += page_size
            page += 1
            if page > 100000:
                logging.warning("Paginacao de auditoria excedeu o limite.")
                break

    # Rede de seguranca: filtra por cursor no cliente, caso o servidor ignore
    # o filtro de data. Usa >= p/ nao perder eventos de mesmo horario no limite
    # (a idempotencia por _id evita duplicata).
    if cursor_iso:
        collected = [ev for ev in collected
                     if str(event_time(ev) or '') >= cursor_iso]
    return collected


# ---------------------------------------------------------------------------
#  Indexacao no OpenSearch (Bulk API)
# ---------------------------------------------------------------------------
def bulk_index(oscfg, events):
    """Indexa uma lista de eventos via _bulk. Retorna (ok, falhas)."""
    if not events:
        return 0, 0
    base = oscfg.get('url', fallback='https://localhost:9200').rstrip('/')
    index = oscfg.get('index', fallback='ema-audit')
    verify = oscfg.getboolean('verify_ssl', fallback=True)
    user = oscfg.get('username', fallback='').strip()
    pwd = oscfg.get('password', fallback='')
    auth = (user, pwd) if user else None

    lines = []
    for ev in events:
        doc = dict(ev)
        et = event_time(ev)
        if et and '@timestamp' not in doc:
            doc['@timestamp'] = et
        lines.append(json.dumps({'index': {'_index': index, '_id': doc_id(ev)}}))
        lines.append(json.dumps(doc, ensure_ascii=False))
    body = ('\n'.join(lines) + '\n').encode('utf-8')

    resp = requests.post(
        f"{base}/_bulk", data=body,
        headers={'Content-Type': 'application/x-ndjson'},
        auth=auth, verify=verify, timeout=oscfg.getint('timeout', fallback=60))
    resp.raise_for_status()
    result = resp.json()
    ok = failed = 0
    if result.get('errors'):
        for item in result.get('items', []):
            info = item.get('index', item.get('create', {}))
            if info.get('error'):
                failed += 1
                logging.debug("Falha ao indexar %s: %s", info.get('_id'), info.get('error'))
            else:
                ok += 1
    else:
        ok = len(events)
    return ok, failed


# ---------------------------------------------------------------------------
#  Modo probe: revela o formato real do AuditEvents
# ---------------------------------------------------------------------------
def probe(client, acfg):
    path = acfg.get('path', fallback='auditEvents')
    page_size = min(acfg.getint('page_size', fallback=500), 5)
    top_param = acfg.get('top_param', fallback='$top')
    logging.info("Sondando %s (%s=%s, sem filtro de data)...", path, top_param, page_size)
    try:
        data = client.get(path, params={top_param: page_size})
    except requests.HTTPError as exc:
        code = exc.response.status_code if exc.response is not None else '?'
        logging.error("Falha ao acessar %s (HTTP %s). Se for 403, use uma "
                      "credencial com scope TenantManager.", path, code)
        return
    items = client._extract_list(data)
    logging.info("Eventos retornados na amostra: %s", len(items))
    if not items:
        logging.info("Resposta bruta (300 chars): %s",
                     json.dumps(data, ensure_ascii=False)[:300])
        return
    ev = items[0]
    logging.info("Chaves do 1o evento: %s", list(ev.keys()) if isinstance(ev, dict) else type(ev))
    logging.info("id detectado  : %s", event_id(ev))
    logging.info("horario detect: %s", event_time(ev))
    logging.info("Amostra do 1o evento: %s", json.dumps(ev, ensure_ascii=False)[:600])
    logging.info("Ajuste [audit] from_param/path no config se id/horario acima "
                 "estiverem vazios ou o caminho estiver errado.")


# ---------------------------------------------------------------------------
#  Execucao
# ---------------------------------------------------------------------------
def run(config_path, do_probe=False):
    cfg = configparser.ConfigParser()
    if not cfg.read(config_path):
        sys.exit(f"Nao consegui ler o config: {config_path}")
    setup_logging(cfg['log'] if cfg.has_section('log') else {})

    if not cfg.has_section('audit'):
        sys.exit("Falta a secao [audit] no config (veja config.example.ini).")

    client = EmaClient(cfg['ema'])
    client.authenticate()
    acfg = cfg['audit']

    if do_probe:
        probe(client, acfg)
        return

    if not cfg.has_section('opensearch'):
        sys.exit("Falta a secao [opensearch] no config (veja config.example.ini).")
    oscfg = cfg['opensearch']

    state_file = acfg.get('state_file', fallback='ema_audit_state.json')
    if not os.path.isabs(state_file):
        state_file = os.path.join(os.path.dirname(os.path.abspath(config_path)), state_file)
    state = load_state(state_file)

    cursor = state.get('cursor')
    if not cursor:
        lookback = acfg.getint('lookback_hours', fallback=24)
        start = datetime.datetime.utcnow() - datetime.timedelta(hours=lookback)
        cursor = start.strftime('%Y-%m-%dT%H:%M:%SZ')
        logging.info("Primeira execucao: janela inicial de %sh (desde %s).", lookback, cursor)

    logging.info("Coletando auditoria a partir de %s...", cursor)
    events = fetch_audit(client, acfg, cursor)
    logging.info("Eventos novos coletados: %s", len(events))

    # Avanca o cursor para o maior horario visto (comparacao lexicografica de
    # ISO8601 zulu funciona; mantem o anterior se nada novo tiver horario).
    max_time = cursor
    for ev in events:
        et = event_time(ev)
        if et and str(et) > max_time:
            max_time = str(et)

    ok, failed = bulk_index(oscfg, events)
    logging.info("Indexados no OpenSearch: %s (falhas: %s)", ok, failed)

    if events and failed == 0:
        state['cursor'] = max_time
        state['updated_at'] = datetime.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ')
        save_state(state_file, state)
        logging.info("Cursor avancado para %s.", max_time)
    elif failed:
        logging.warning("Houve falhas de indexacao; cursor NAO avancado para "
                        "reprocessar na proxima execucao.")


def main():
    parser = argparse.ArgumentParser(description="Coletor de AuditEvents do Intel EMA -> OpenSearch")
    parser.add_argument('--config', default=os.path.join(
        os.path.dirname(__file__), '..', 'config.ini'),
        help="Caminho do config.ini (padrao: ../config.ini)")
    parser.add_argument('--probe', action='store_true',
        help="Nao indexa; apenas mostra o formato real do AuditEvents (campos "
             "de id/horario) p/ ajustar o config.")
    args = parser.parse_args()
    start = time.time()
    run(os.path.abspath(args.config), do_probe=args.probe)
    logging.info("Tempo total: %.1fs", time.time() - start)


if __name__ == '__main__':
    main()
