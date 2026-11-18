-- CMDB - migração v4 (agendamento das coletas: 30 em 30 minutos ou uma vez ao dia)
-- Pode ser executada mais de uma vez:   sudo mariadb < sql/migracao-v4.sql
USE cmdb;

-- ---------------------------------------------------------------------------
-- Agendamento das coletas (uma linha; o agendador lê daqui a cada minuto)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agendamento (
  id              TINYINT UNSIGNED NOT NULL,
  modo            VARCHAR(10) NOT NULL DEFAULT 'desligado',   -- desligado | 30min | diario
  hora            CHAR(5) NOT NULL DEFAULT '02:00',           -- HH:MM, usado no modo diario
  ultimo_slot     DATETIME NULL,                              -- último horário programado já atendido
  ultima_execucao DATETIME NULL,
  ultimo_estado   VARCHAR(20) NULL,
  ultima_mensagem TEXT NULL,
  ultimo_pulso    DATETIME NULL,                              -- última vez que o agendador (cron) rodou
  atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
INSERT IGNORE INTO agendamento (id, modo, hora) VALUES (1, 'desligado', '02:00');

DELIMITER //
DROP TRIGGER IF EXISTS trg_agendamento_no_delete//
CREATE TRIGGER trg_agendamento_no_delete BEFORE DELETE ON agendamento
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: agendamento nao pode ser removido';
END//

DELIMITER ;
