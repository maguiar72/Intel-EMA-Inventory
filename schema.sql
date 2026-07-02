-- =====================================================================
--  Intel EMA Inventory - Esquema MySQL / MariaDB
-- ---------------------------------------------------------------------
--  Estrategia: cada recurso guarda o JSON bruto retornado pela API do
--  Intel EMA (coluna `raw`) para refletir 100% dos dados, e tambem
--  extrai os campos mais usados para colunas indexadas, permitindo
--  busca rapida e filtros no front-end PHP.
--
--  Uso:
--     mysql -u root -p < schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS ema_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ema_inventory;

-- ---------------------------------------------------------------------
--  Auditoria das coletas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collection_runs (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  started_at     DATETIME NOT NULL,
  finished_at    DATETIME NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'running',  -- running | ok | error
  ema_base_url   VARCHAR(255) NULL,
  endpoints_count   INT DEFAULT 0,
  groups_count      INT DEFAULT 0,
  profiles_count    INT DEFAULT 0,
  hardware_count    INT DEFAULT 0,
  message        TEXT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  Dispositivos / Endpoints
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS endpoints (
  endpoint_id        VARCHAR(64)  NOT NULL PRIMARY KEY,
  name               VARCHAR(255) NULL,
  fqdn               VARCHAR(255) NULL,
  domain             VARCHAR(255) NULL,
  os_desc            VARCHAR(255) NULL,
  ip_address         VARCHAR(64)  NULL,
  mac_address        VARCHAR(64)  NULL,
  amt_version        VARCHAR(64)  NULL,
  power_state        VARCHAR(64)  NULL,
  connection_status  VARCHAR(64)  NULL,   -- agente conectado / desconectado
  control_mode       VARCHAR(32)  NULL,   -- ACM / CCM / Not provisioned
  provisioning_state VARCHAR(64)  NULL,
  group_id           VARCHAR(64)  NULL,
  group_name         VARCHAR(255) NULL,
  last_seen          DATETIME     NULL,
  raw                LONGTEXT     NULL,    -- JSON completo da API
  first_collected    DATETIME     NOT NULL,
  updated_at         DATETIME     NOT NULL,
  last_run_id        BIGINT       NULL,
  INDEX idx_ep_name   (name),
  INDEX idx_ep_group  (group_id),
  INDEX idx_ep_power  (power_state),
  INDEX idx_ep_conn   (connection_status),
  INDEX idx_ep_mode   (control_mode),
  INDEX idx_ep_amt    (amt_version)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  Grupos de Endpoints
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS endpoint_groups (
  group_id        VARCHAR(64)  NOT NULL PRIMARY KEY,
  name            VARCHAR(255) NULL,
  description     TEXT         NULL,
  endpoint_count  INT          NULL,
  amt_profile_id  VARCHAR(64)  NULL,
  raw             LONGTEXT     NULL,
  first_collected DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  last_run_id     BIGINT       NULL,
  INDEX idx_grp_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  Perfis Intel AMT
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS amt_profiles (
  profile_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
  name            VARCHAR(255) NULL,
  description     TEXT         NULL,
  activation_mode VARCHAR(64)  NULL,
  raw             LONGTEXT     NULL,
  first_collected DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  last_run_id     BIGINT       NULL,
  INDEX idx_prof_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  Inventario de Hardware (1 por endpoint)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hardware_inventory (
  endpoint_id     VARCHAR(64)  NOT NULL PRIMARY KEY,
  manufacturer    VARCHAR(255) NULL,
  model           VARCHAR(255) NULL,
  serial_number   VARCHAR(255) NULL,
  bios_version    VARCHAR(255) NULL,
  cpu_desc        VARCHAR(255) NULL,
  total_memory    VARCHAR(64)  NULL,
  raw             LONGTEXT     NULL,
  updated_at      DATETIME     NOT NULL,
  last_run_id     BIGINT       NULL,
  CONSTRAINT fk_hw_endpoint FOREIGN KEY (endpoint_id)
      REFERENCES endpoints (endpoint_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  Usuario de leitura para o front-end (ajuste a senha!)
-- ---------------------------------------------------------------------
-- CREATE USER IF NOT EXISTS 'ema_web'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
-- GRANT SELECT ON ema_inventory.* TO 'ema_web'@'localhost';
-- CREATE USER IF NOT EXISTS 'ema_collector'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON ema_inventory.* TO 'ema_collector'@'localhost';
-- FLUSH PRIVILEGES;
