-- CMDB - migração v2 (telas de usuários e de ambientes)
-- Para bancos JÁ criados com a versão anterior do schema.sql.
-- Pode ser executada mais de uma vez:   sudo mariadb < sql/migracao-v2.sql
-- Instalações novas NÃO precisam dela (o schema.sql já inclui tudo isto).

USE cmdb;

-- Usuários passam a ser desativados, não removidos
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER perfil;

-- Ambientes (Proxmox) cadastrados na tela; token cifrado (chave no config.php)
CREATE TABLE IF NOT EXISTS ambiente (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(60)  NOT NULL,
  nome          VARCHAR(120) NOT NULL,
  host          VARCHAR(190) NOT NULL,
  porta         SMALLINT UNSIGNED NOT NULL DEFAULT 8006,
  usuario_api   VARCHAR(190) NOT NULL,
  token_cifrado TEXT NOT NULL,
  ordem         INT NOT NULL DEFAULT 100,
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_estado    VARCHAR(20)  NULL,
  ultima_mensagem  VARCHAR(500) NULL,
  ultima_coleta_em DATETIME NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ambiente_slug (slug)
) ENGINE=InnoDB;

DELIMITER //

DROP TRIGGER IF EXISTS trg_usuario_no_delete//
CREATE TRIGGER trg_usuario_no_delete BEFORE DELETE ON usuario
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: usuarios nao podem ser removidos (desative-os)';
END//

DROP TRIGGER IF EXISTS trg_ambiente_no_delete//
CREATE TRIGGER trg_ambiente_no_delete BEFORE DELETE ON ambiente
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: ambientes nao podem ser removidos (desative-os)';
END//

DROP TRIGGER IF EXISTS trg_ambiente_slug_imutavel//
CREATE TRIGGER trg_ambiente_slug_imutavel BEFORE UPDATE ON ambiente
FOR EACH ROW
BEGIN
  IF NEW.slug <> OLD.slug THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: slug do ambiente nao pode ser alterado';
  END IF;
END//

DELIMITER ;
