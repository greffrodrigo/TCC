-- CMDB - migração v3 (usuário que alterou o recurso no histórico)
-- Pode ser executada mais de uma vez:   sudo mariadb < sql/migracao-v3.sql
USE cmdb;
ALTER TABLE coleta ADD COLUMN IF NOT EXISTS cluster_id BIGINT UNSIGNED NULL AFTER gerada_em;
ALTER TABLE iconf_historico ADD COLUMN IF NOT EXISTS alterado_por VARCHAR(190) NULL AFTER valor_novo;
