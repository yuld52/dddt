-- Starfy Platform Database Schema
-- Generated for Replit environment setup

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(255) NOT NULL UNIQUE,
  `nome` VARCHAR(255) NOT NULL,
  `senha` VARCHAR(255) NOT NULL,
  `tipo` ENUM('admin','infoprodutor','aluno') NOT NULL DEFAULT 'infoprodutor',
  `telefone` VARCHAR(20),
  `foto_perfil` VARCHAR(500),
  `mp_public_key` VARCHAR(500),
  `mp_access_token` VARCHAR(500),
  `pushinpay_token` VARCHAR(500),
  `efi_client_id` VARCHAR(255),
  `efi_client_secret` VARCHAR(255),
  `efi_certificate_path` VARCHAR(500),
  `efi_pix_key` VARCHAR(255),
  `efi_payee_code` VARCHAR(255),
  `beehive_public_key` VARCHAR(500),
  `beehive_secret_key` VARCHAR(500),
  `hypercash_public_key` VARCHAR(500),
  `hypercash_secret_key` VARCHAR(500),
  `remember_token` VARCHAR(255),
  `remember_token_expires` DATETIME,
  `password_reset_token` VARCHAR(255),
  `password_reset_expires` DATETIME,
  `password_setup_token` VARCHAR(255),
  `password_setup_expires` DATETIME,
  `saas_plano_free_atribuido` TINYINT(1) DEFAULT 0,
  `data_cadastro` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usuario (usuario),
  INDEX idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- produtos
CREATE TABLE IF NOT EXISTS `produtos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(255) NOT NULL,
  `descricao` TEXT,
  `preco` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `preco_anterior` DECIMAL(10,2),
  `foto` VARCHAR(500),
  `checkout_hash` VARCHAR(255) UNIQUE,
  `tipo_entrega` VARCHAR(50),
  `conteudo_entrega` TEXT,
  `usuario_id` INT,
  `gateway` VARCHAR(50),
  `checkout_config` JSON,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_checkout_hash (checkout_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendas
CREATE TABLE IF NOT EXISTS `vendas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT,
  `comprador_nome` VARCHAR(255),
  `comprador_email` VARCHAR(255),
  `comprador_cpf` VARCHAR(20),
  `comprador_telefone` VARCHAR(20),
  `comprador_cep` VARCHAR(10),
  `comprador_logradouro` VARCHAR(255),
  `comprador_numero` VARCHAR(20),
  `comprador_complemento` VARCHAR(255),
  `comprador_bairro` VARCHAR(255),
  `comprador_cidade` VARCHAR(255),
  `comprador_estado` VARCHAR(5),
  `valor` DECIMAL(10,2),
  `status_pagamento` VARCHAR(50),
  `transacao_id` VARCHAR(255),
  `metodo_pagamento` VARCHAR(50),
  `checkout_session_uuid` VARCHAR(255),
  `email_entrega_enviado` TINYINT(1) DEFAULT 0,
  `email_reenviado_manual` TINYINT(1) DEFAULT 0,
  `utm_source` VARCHAR(255),
  `utm_campaign` VARCHAR(255),
  `utm_medium` VARCHAR(255),
  `utm_content` VARCHAR(255),
  `utm_term` VARCHAR(255),
  `src` VARCHAR(255),
  `sck` VARCHAR(255),
  `data_venda` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_produto_id (produto_id),
  INDEX idx_comprador_email (comprador_email),
  INDEX idx_status (status_pagamento),
  INDEX idx_transacao_id (transacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- configuracoes (per-user settings like SMTP)
CREATE TABLE IF NOT EXISTS `configuracoes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chave` VARCHAR(255) NOT NULL,
  `valor` TEXT,
  `usuario_id` INT,
  UNIQUE KEY uq_chave_usuario (chave, usuario_id),
  INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- configuracoes_sistema (system-wide settings)
CREATE TABLE IF NOT EXISTS `configuracoes_sistema` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chave` VARCHAR(255) NOT NULL UNIQUE,
  `valor` TEXT,
  `tipo` VARCHAR(50),
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cursos
CREATE TABLE IF NOT EXISTS `cursos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT,
  `titulo` VARCHAR(255) NOT NULL,
  `descricao` TEXT,
  `imagem_url` VARCHAR(500),
  `banner_url` VARCHAR(500),
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- modulos
CREATE TABLE IF NOT EXISTS `modulos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `curso_id` INT,
  `titulo` VARCHAR(255) NOT NULL,
  `imagem_capa_url` VARCHAR(500),
  `release_days` INT DEFAULT 0,
  `is_paid_module` TINYINT(1) DEFAULT 0,
  `linked_product_id` INT,
  `ordem` INT DEFAULT 0,
  INDEX idx_curso_id (curso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- aulas
CREATE TABLE IF NOT EXISTS `aulas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `modulo_id` INT,
  `titulo` VARCHAR(255) NOT NULL,
  `url_video` VARCHAR(500),
  `descricao` TEXT,
  `release_days` INT DEFAULT 0,
  `tipo_conteudo` VARCHAR(50),
  `ordem` INT DEFAULT 0,
  INDEX idx_modulo_id (modulo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- aula_arquivos
CREATE TABLE IF NOT EXISTS `aula_arquivos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `aula_id` INT,
  `nome_original` VARCHAR(255),
  `nome_salvo` VARCHAR(255),
  `caminho_arquivo` VARCHAR(500),
  `tipo_mime` VARCHAR(100),
  `tamanho_bytes` BIGINT,
  INDEX idx_aula_id (aula_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- alunos_acessos
CREATE TABLE IF NOT EXISTS `alunos_acessos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `aluno_email` VARCHAR(255) NOT NULL,
  `produto_id` INT,
  `data_acesso` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aluno_email (aluno_email),
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- aluno_progresso
CREATE TABLE IF NOT EXISTS `aluno_progresso` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `aluno_email` VARCHAR(255) NOT NULL,
  `aula_id` INT,
  `data_conclusao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aluno_aula (aluno_email, aula_id),
  INDEX idx_aluno_email (aluno_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- produto_ofertas
CREATE TABLE IF NOT EXISTS `produto_ofertas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT,
  `nome` VARCHAR(255),
  `preco` DECIMAL(10,2),
  `checkout_hash` VARCHAR(255) UNIQUE,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- product_exclusive_offers
CREATE TABLE IF NOT EXISTS `product_exclusive_offers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT,
  `nome` VARCHAR(255),
  `preco` DECIMAL(10,2),
  `checkout_hash` VARCHAR(255) UNIQUE,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- order_bumps
CREATE TABLE IF NOT EXISTS `order_bumps` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `main_product_id` INT,
  `offer_product_id` INT,
  `headline` VARCHAR(255),
  `description` TEXT,
  `ordem` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  INDEX idx_main_product_id (main_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reembolsos
CREATE TABLE IF NOT EXISTS `reembolsos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `venda_id` INT,
  `produto_id` INT,
  `comprador_email` VARCHAR(255),
  `comprador_nome` VARCHAR(255),
  `valor` DECIMAL(10,2),
  `motivo` TEXT,
  `status` VARCHAR(50) DEFAULT 'pendente',
  `data_solicitacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `usuario_id` INT,
  `transacao_id` VARCHAR(255),
  `metodo_pagamento` VARCHAR(50),
  INDEX idx_venda_id (venda_id),
  INDEX idx_usuario_id (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- plugins
CREATE TABLE IF NOT EXISTS `plugins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(255) NOT NULL,
  `pasta` VARCHAR(255) NOT NULL UNIQUE,
  `versao` VARCHAR(50),
  `ativo` TINYINT(1) DEFAULT 1,
  `data_instalacao` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- webhooks
CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `produto_id` INT,
  `url` VARCHAR(500),
  `venda_aprovada` TINYINT(1) DEFAULT 1,
  `venda_pendente` TINYINT(1) DEFAULT 0,
  `venda_cancelada` TINYINT(1) DEFAULT 0,
  `venda_reembolsada` TINYINT(1) DEFAULT 0,
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- notificacoes
CREATE TABLE IF NOT EXISTS `notificacoes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `tipo` VARCHAR(50),
  `mensagem` TEXT,
  `valor` DECIMAL(10,2),
  `link_acao` VARCHAR(500),
  `venda_id_fk` INT,
  `metodo_pagamento` VARCHAR(50),
  `data_notificacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `lida` TINYINT(1) DEFAULT 0,
  `displayed_live` TINYINT(1) DEFAULT 0,
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_lida (lida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- email_queue
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_email` VARCHAR(255),
  `recipient_name` VARCHAR(255),
  `subject` VARCHAR(500),
  `body` LONGTEXT,
  `status` VARCHAR(50) DEFAULT 'pending',
  `attempts` INT DEFAULT 0,
  `error_message` TEXT,
  `sent_at` DATETIME,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pwa_config
CREATE TABLE IF NOT EXISTS `pwa_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `app_name` VARCHAR(255),
  `short_name` VARCHAR(100),
  `description` TEXT,
  `start_url` VARCHAR(255) DEFAULT '/',
  `theme_color` VARCHAR(20),
  `background_color` VARCHAR(20),
  `icon_path` VARCHAR(500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rate_limits
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rate_key` VARCHAR(255) NOT NULL,
  `identifier` VARCHAR(255),
  `attempts` INT DEFAULT 1,
  `first_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `blocked_until` DATETIME NULL,
  INDEX idx_key_identifier (rate_key, identifier),
  INDEX idx_last_attempt (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- login_attempts
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `email` VARCHAR(255),
  `attempts` INT DEFAULT 1,
  `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `blocked_until` DATETIME,
  INDEX idx_ip_address (ip_address),
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- security_logs
CREATE TABLE IF NOT EXISTS `security_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45),
  `event_type` VARCHAR(100),
  `details` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip (ip_address),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_planos
CREATE TABLE IF NOT EXISTS `saas_planos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(255) NOT NULL,
  `descricao` TEXT,
  `preco` DECIMAL(10,2) DEFAULT 0.00,
  `periodo` VARCHAR(50),
  `max_produtos` INT DEFAULT 0,
  `max_pedidos_mes` INT DEFAULT 0,
  `is_free` TINYINT(1) DEFAULT 0,
  `ativo` TINYINT(1) DEFAULT 1,
  `ordem` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_assinaturas
CREATE TABLE IF NOT EXISTS `saas_assinaturas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `plano_id` INT,
  `status` VARCHAR(50) DEFAULT 'ativa',
  `data_inicio` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `data_vencimento` DATETIME,
  `transacao_id` VARCHAR(255),
  `metodo_pagamento` VARCHAR(50),
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_admin_gateways
CREATE TABLE IF NOT EXISTS `saas_admin_gateways` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `gateway` VARCHAR(50) NOT NULL,
  `public_key` VARCHAR(500),
  `secret_key` VARCHAR(500),
  `ativo` TINYINT(1) DEFAULT 1,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_payment_methods
CREATE TABLE IF NOT EXISTS `saas_payment_methods` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `gateway` VARCHAR(50),
  `metodo` VARCHAR(50),
  `ativo` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_config
CREATE TABLE IF NOT EXISTS `saas_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chave` VARCHAR(255) NOT NULL UNIQUE,
  `valor` TEXT,
  `enabled` TINYINT(1) DEFAULT 0,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saas_contadores_mensais
CREATE TABLE IF NOT EXISTS `saas_contadores_mensais` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `mes_ano` VARCHAR(7),
  `total_pedidos` INT DEFAULT 0,
  UNIQUE KEY uq_usuario_mes (usuario_id, mes_ano),
  INDEX idx_usuario_id (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- utmfy_integrations
CREATE TABLE IF NOT EXISTS `utmfy_integrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `produto_id` INT,
  `utmfy_token` VARCHAR(255),
  `ativo` TINYINT(1) DEFAULT 1,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cloned_sites
CREATE TABLE IF NOT EXISTS `cloned_sites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT,
  `produto_id` INT,
  `nome` VARCHAR(255),
  `url_original` VARCHAR(500),
  `conteudo` LONGTEXT,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usuario_id (usuario_id),
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cloned_site_settings
CREATE TABLE IF NOT EXISTS `cloned_site_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `chave` VARCHAR(255),
  `valor` TEXT,
  INDEX idx_site_id (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- starfy_tracking_products
CREATE TABLE IF NOT EXISTS `starfy_tracking_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT,
  `usuario_id` INT,
  `pixel_id` VARCHAR(255),
  `access_token` VARCHAR(500),
  `test_event_code` VARCHAR(100),
  `ativo` TINYINT(1) DEFAULT 1,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- starfy_tracking_events
CREATE TABLE IF NOT EXISTS `starfy_tracking_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT,
  `event_name` VARCHAR(100),
  `event_data` JSON,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_produto_id (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: default admin user (password: admin123)
INSERT IGNORE INTO `usuarios` (nome, usuario, senha, tipo)
VALUES ('Administrador', 'admin@starfy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Seed: default system settings
INSERT IGNORE INTO `configuracoes_sistema` (chave, valor) VALUES
('site_nome', 'Starfy'),
('site_url', ''),
('logo_url', ''),
('favicon_url', '');
