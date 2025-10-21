-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Tempo de geração: 09/10/2025 às 01:01
-- Versão do servidor: 8.4.5-5
-- Versão do PHP: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `martinspay-app`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `adquirentes`
--

CREATE TABLE `adquirentes` (
  `id` bigint UNSIGNED NOT NULL,
  `adquirente` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `supports_pix` tinyint(1) NOT NULL DEFAULT '1',
  `supports_card` tinyint(1) NOT NULL DEFAULT '0',
  `supports_boleto` tinyint(1) NOT NULL DEFAULT '0',
  `is_default_pix` tinyint(1) NOT NULL DEFAULT '0',
  `is_default_card` tinyint(1) NOT NULL DEFAULT '0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_default_card_billet` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `referencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `adquirentes`
--

INSERT INTO `adquirentes` (`id`, `adquirente`, `status`, `supports_pix`, `supports_card`, `supports_boleto`, `is_default_pix`, `is_default_card`, `is_default`, `is_default_card_billet`, `url`, `referencia`, `created_at`, `updated_at`) VALUES
(1, 'cashtime', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api.cashtime.com.br', 'cashtime', NULL, '2025-10-08 13:12:49'),
(2, 'sixxpayments', 0, 1, 0, 0, 0, 0, 0, 0, 'https://sixxpayments.com/api/', 'sixxpayments', NULL, '2025-10-08 13:12:49'),
(4, 'mercadopago', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api.mercadopago.com', 'mercadopago', NULL, '2025-10-08 13:12:49'),
(5, 'efi', 0, 1, 0, 0, 0, 0, 0, 0, 'https://pix.api.efipay.com.br', 'efi', NULL, '2025-10-08 13:12:49'),
(6, 'xgate', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api.xgate.com.br', 'xgate', NULL, '2025-10-08 13:12:49'),
(7, 'witetec', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api.witetec.net', 'witetec', NULL, '2025-10-08 13:12:49'),
(8, 'Pixup', 1, 1, 0, 0, 0, 0, 0, 0, 'https://api.pixupbr.com/v2/', 'pixup', '2025-09-06 19:15:14', '2025-10-08 13:12:49'),
(9, 'Woovi', 1, 1, 0, 0, 0, 0, 1, 0, 'https://api.woovi.com', 'woovi', '2025-09-09 19:42:28', '2025-10-08 13:12:49'),
(10, 'BSPay', 1, 1, 0, 0, 0, 0, 0, 0, 'https://api.bspay.co/v2/', 'bspay', '2025-09-10 23:10:48', '2025-10-08 13:12:49'),
(11, 'Asaas', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api-sandbox.asaas.com/v3/', 'asaas', '2025-09-14 05:05:07', '2025-10-08 13:12:49'),
(13, 'Syscoop', 0, 1, 0, 0, 0, 0, 0, 0, 'http://prodafsyscoopws-845630056.us-east-1.elb.amazonaws.com/wssyscoopapp/fazahml/syscoopapp.dll', 'syscoop', '2025-09-14 22:42:57', '2025-10-08 13:12:49'),
(16, 'PrimePay7', 1, 1, 0, 0, 0, 0, 0, 1, 'https://api.primepay7.com', 'primepay7', '2025-10-04 12:06:58', '2025-10-08 13:31:29'),
(17, 'XDPag', 0, 1, 0, 0, 0, 0, 0, 0, 'https://api.xdpag.com', 'xdpag', '2025-10-04 12:06:58', '2025-10-08 13:12:49');

-- --------------------------------------------------------

--
-- Estrutura para tabela `ad_mercadopago`
--

CREATE TABLE `ad_mercadopago` (
  `id` bigint UNSIGNED NOT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT '5.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `ad_mercadopago`
--

INSERT INTO `ad_mercadopago` (`id`, `access_token`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `created_at`, `updated_at`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, 5.00, 5.00, NULL, '2025-09-07 16:21:48', 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `ad_primepag`
--

CREATE TABLE `ad_primepag` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.primepag.com.br',
  `url_cash_in` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.primepag.com.br/v1/pix/qrcodes',
  `url_cash_out` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.primepag.com.br/v1/pix/payments',
  `url_webhook_deposit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_webhook_payment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT '5.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `ad_primepag`
--

INSERT INTO `ad_primepag` (`id`, `client_id`, `client_secret`, `url`, `url_cash_in`, `url_cash_out`, `url_webhook_deposit`, `url_webhook_payment`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, 'https://api.primepag.com.br', 'https://api.primepag.com.br/v1/pix/qrcodes', 'https://api.primepag.com.br/v1/pix/payments', NULL, NULL, 2.50, 2.50, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `app`
--

CREATE TABLE `app` (
  `id` bigint UNSIGNED NOT NULL,
  `gateway_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_logo_dark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gateway_favicon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_banner_home` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0cd72e',
  `numero_users` int NOT NULL DEFAULT '0',
  `faturamento_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_transacoes` decimal(10,2) NOT NULL DEFAULT '0.00',
  `visitantes` int NOT NULL DEFAULT '0',
  `manutencao` tinyint(1) NOT NULL DEFAULT '0',
  `baseline` decimal(10,2) NOT NULL DEFAULT '1.00',
  `taxa_fixa_pix` decimal(10,2) NOT NULL DEFAULT '0.00',
  `taxa_flexivel_valor_minimo` decimal(10,2) NOT NULL DEFAULT '15.00',
  `taxa_flexivel_fixa_baixo` decimal(10,2) NOT NULL DEFAULT '1.00',
  `taxa_flexivel_percentual_alto` decimal(10,2) NOT NULL DEFAULT '4.00',
  `relatorio_entradas_mostrar_meio` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_transacao_id` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_valor` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_valor_liquido` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_nome` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_documento` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_status` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_data` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_entradas_mostrar_taxa` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_transacao_id` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_valor` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_nome` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_chave_pix` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_tipo_chave` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_status` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_data` tinyint(1) NOT NULL DEFAULT '1',
  `relatorio_saidas_mostrar_taxa` tinyint(1) NOT NULL DEFAULT '1',
  `taxa_flexivel_ativa` tinyint(1) NOT NULL DEFAULT '0',
  `taxa_cash_in_padrao` decimal(10,2) NOT NULL DEFAULT '4.00',
  `taxa_cash_out_padrao` decimal(10,2) NOT NULL DEFAULT '4.00',
  `taxa_saque_api_padrao` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_saque_cripto_padrao` decimal(10,2) NOT NULL DEFAULT '1.00',
  `taxa_por_fora_api` tinyint(1) NOT NULL DEFAULT '1',
  `taxa_fixa_padrao` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_fixa_padrao_cash_out` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sms_url_cadastro_pendente` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_url_cadastro_ativo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_url_notificacao_user` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_url_redefinir_senha` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_url_autenticar_admin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_valor_real_cash_in_padrao` decimal(10,2) NOT NULL DEFAULT '5.00',
  `global_ips` json DEFAULT NULL COMMENT 'IPs globais autorizados para todos os usuários (interface web)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `limite_saque_automatico` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `limite_saque_mensal` decimal(10,2) NOT NULL DEFAULT '5000.00',
  `deposito_minimo` decimal(10,2) NOT NULL DEFAULT '10.00',
  `saque_minimo` decimal(10,2) NOT NULL DEFAULT '10.00',
  `contato` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cnpj` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `niveis_ativo` tinyint(1) NOT NULL DEFAULT '1',
  `gerente_active` tinyint(1) NOT NULL DEFAULT '0',
  `gerente_percentage` decimal(10,2) NOT NULL DEFAULT '0.00',
  `saque_automatico` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `app`
--

INSERT INTO `app` (`id`, `gateway_name`, `gateway_logo`, `gateway_logo_dark`, `gateway_favicon`, `gateway_banner_home`, `gateway_color`, `numero_users`, `faturamento_total`, `total_transacoes`, `visitantes`, `manutencao`, `baseline`, `taxa_fixa_pix`, `taxa_flexivel_valor_minimo`, `taxa_flexivel_fixa_baixo`, `taxa_flexivel_percentual_alto`, `relatorio_entradas_mostrar_meio`, `relatorio_entradas_mostrar_transacao_id`, `relatorio_entradas_mostrar_valor`, `relatorio_entradas_mostrar_valor_liquido`, `relatorio_entradas_mostrar_nome`, `relatorio_entradas_mostrar_documento`, `relatorio_entradas_mostrar_status`, `relatorio_entradas_mostrar_data`, `relatorio_entradas_mostrar_taxa`, `relatorio_saidas_mostrar_transacao_id`, `relatorio_saidas_mostrar_valor`, `relatorio_saidas_mostrar_nome`, `relatorio_saidas_mostrar_chave_pix`, `relatorio_saidas_mostrar_tipo_chave`, `relatorio_saidas_mostrar_status`, `relatorio_saidas_mostrar_data`, `relatorio_saidas_mostrar_taxa`, `taxa_flexivel_ativa`, `taxa_cash_in_padrao`, `taxa_cash_out_padrao`, `taxa_saque_api_padrao`, `taxa_saque_cripto_padrao`, `taxa_por_fora_api`, `taxa_fixa_padrao`, `taxa_fixa_padrao_cash_out`, `sms_url_cadastro_pendente`, `sms_url_cadastro_ativo`, `sms_url_notificacao_user`, `sms_url_redefinir_senha`, `sms_url_autenticar_admin`, `taxa_pix_valor_real_cash_in_padrao`, `global_ips`, `created_at`, `updated_at`, `limite_saque_automatico`, `limite_saque_mensal`, `deposito_minimo`, `saque_minimo`, `contato`, `cnpj`, `niveis_ativo`, `gerente_active`, `gerente_percentage`, `saque_automatico`) VALUES
(1, 'MartinsPay', '/uploads/68e6e85f16129.png', NULL, '/uploads/68e6e8bec0db6.png', '/uploads/68cb08105d27f.png', '#0400ff', 0, 0.00, 0.00, 0, 0, 0.80, 0.00, 15.00, 1.00, 4.00, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 2.00, 2.50, 5.00, 1.00, 1, 0.50, 0.00, NULL, NULL, NULL, NULL, NULL, 5.00, '[\"72.60.250.159\"]', NULL, '2025-10-08 19:42:06', 5000.00, 50000.00, 1.00, 5.00, '0', '40824737000143', 1, 0, 20.00, 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `asaas`
--

CREATE TABLE `asaas` (
  `id` bigint UNSIGNED NOT NULL,
  `api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment` enum('sandbox','production') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sandbox',
  `webhook_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `bspay`
--

CREATE TABLE `bspay` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `bspay`
--

INSERT INTO `bspay` (`id`, `client_id`, `client_secret`, `url`, `created_at`, `updated_at`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, 'https://api.bspay.co/v2/', '2025-10-04 13:39:55', '2025-10-08 13:34:48', 0.00, 0.00, 1.00, 1.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('martinspay_cache_7c7ac0cff974fad51b85aa869a8971f57afb943c', 'i:2;', 1759964176),
('martinspay_cache_7c7ac0cff974fad51b85aa869a8971f57afb943c:timer', 'i:1759964176;', 1759964176),
('martinspay_cache_8d7d5fddaa19441d8d38f0fa6f9e9c33b8e074c1', 'i:1;', 1759964238),
('martinspay_cache_8d7d5fddaa19441d8d38f0fa6f9e9c33b8e074c1:timer', 'i:1759964238;', 1759964238),
('martinspay_cache_admin@admin|189.6.240.223', 'i:1;', 1759962882),
('martinspay_cache_admin@admin|189.6.240.223:timer', 'i:1759962882;', 1759962882);

-- --------------------------------------------------------

--
-- Estrutura para tabela `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `cashtimes`
--

CREATE TABLE `cashtimes` (
  `id` bigint UNSIGNED NOT NULL,
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/',
  `url_cash_in` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/transactions',
  `url_cash_out` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/request/withdraw',
  `url_webhook_deposit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_webhook_payment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT '5.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `cashtimes`
--

INSERT INTO `cashtimes` (`id`, `secret`, `url`, `url_cash_in`, `url_cash_out`, `url_webhook_deposit`, `url_webhook_payment`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `created_at`, `updated_at`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, 'https://api.cashtime.com.br/v1/', 'https://api.cashtime.com.br/v1/transactions', 'https://api.cashtime.com.br/v1/request/withdraw', NULL, NULL, 0.00, 0.00, '2025-04-28 15:01:47', '2025-08-21 14:27:05', 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `checkout_build`
--

CREATE TABLE `checkout_build` (
  `id` int UNSIGNED NOT NULL,
  `id_unico` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `produto_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `produto_descricao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `descricao_extra` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `produto_valor` decimal(10,2) DEFAULT '0.00',
  `produto_de_valor` decimal(10,2) DEFAULT '0.00',
  `produto_categoria` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `produto_tipo` enum('info','fisico') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `produto_tipo_cob` enum('unico','recorrente') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `produto_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_suporte` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_suporte` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao_exta` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checkout_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_color_default` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_color_card` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_timer_active` tinyint(1) DEFAULT '1',
  `checkout_timer_tempo` int DEFAULT NULL,
  `checkout_timer_cor_fundo` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_timer_cor_texto` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_timer_texto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_header_logo_active` tinyint(1) DEFAULT '1',
  `checkout_header_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_header_image_active` tinyint(1) DEFAULT '1',
  `checkout_header_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_banner_active` tinyint(1) NOT NULL DEFAULT '0',
  `checkout_banner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_topbar_active` tinyint(1) DEFAULT '1',
  `checkout_topbar_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_topbar_text_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_topbar_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_ads_meta` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checkout_ads_google` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checkout_ads_tiktok` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checkout_ads_utmfy` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_pagina_vendas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo_garantia` int DEFAULT '7',
  `status` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `methods` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '"[\\"pix\\"]"'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `checkout_build`
--

INSERT INTO `checkout_build` (`id`, `id_unico`, `user_id`, `produto_name`, `produto_descricao`, `descricao_extra`, `produto_valor`, `produto_de_valor`, `produto_categoria`, `produto_tipo`, `produto_tipo_cob`, `produto_image`, `whatsapp_suporte`, `email_suporte`, `descricao_exta`, `checkout_color`, `checkout_color_default`, `checkout_color_card`, `checkout_timer_active`, `checkout_timer_tempo`, `checkout_timer_cor_fundo`, `checkout_timer_cor_texto`, `checkout_timer_texto`, `checkout_header_logo_active`, `checkout_header_logo`, `checkout_header_image_active`, `checkout_header_image`, `checkout_banner_active`, `checkout_banner`, `checkout_topbar_active`, `checkout_topbar_text`, `checkout_topbar_text_color`, `checkout_topbar_color`, `checkout_ads_meta`, `checkout_ads_google`, `checkout_ads_tiktok`, `checkout_ads_utmfy`, `url_pagina_vendas`, `periodo_garantia`, `status`, `created_at`, `updated_at`, `methods`) VALUES
(6, '066bc860-b608-4e4d-a9ac-b8f27372371d', 1, 'Caixa para Envio', 'Caixa para envio e-commerce', NULL, 4.00, 0.00, '0', 'info', 'unico', '/checkouts/6/checkout_produto_image.jpg', NULL, NULL, NULL, '#ffffff', '#ff0000', '#ffffff', 0, 2, '#00c26e', '#ffffff', NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, '#ffffff', '#00c26e', NULL, NULL, NULL, NULL, 'https://demo.hkpay.shop/obrigado?order_id=ORDER_ID', 7, 1, '2025-09-20 10:50:32', '2025-10-08 19:01:14', '\"[\\\"pix\\\",\\\"card\\\"]\"');

-- --------------------------------------------------------

--
-- Estrutura para tabela `checkout_depoimentos`
--

CREATE TABLE `checkout_depoimentos` (
  `id` int UNSIGNED NOT NULL,
  `nome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `depoimento` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `checkout_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `checkout_orders`
--

CREATE TABLE `checkout_orders` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cpf` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cep` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `endereco` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complemento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bairro` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cidade` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor_total` decimal(10,2) DEFAULT NULL,
  `quantidade` int DEFAULT NULL,
  `order_bumps` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('gerado','pendente','pago','cancelado','encaminhado','entregue') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idTransaction` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qrcode` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checkout_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `checkout_order_bumps`
--

CREATE TABLE `checkout_order_bumps` (
  `id` int UNSIGNED NOT NULL,
  `nome` varchar(255) NOT NULL,
  `descricao` text,
  `image` varchar(255) DEFAULT NULL,
  `valor_de` decimal(10,2) DEFAULT '0.00',
  `valor_por` decimal(10,2) DEFAULT '0.00',
  `ativo` tinyint(1) DEFAULT '1',
  `checkout_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Estrutura para tabela `confirmar_deposito`
--

CREATE TABLE `confirmar_deposito` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `externalreference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `depositos_api`
--

CREATE TABLE `depositos_api` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_externo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `cliente_nome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cliente_documento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cliente_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cliente_telefone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_real` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('aguardando','aprovado','rejeitado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aguardando',
  `qrcode` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pixcopiaecola` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `idTransaction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `callback_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adquirente_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_cash_in` decimal(10,2) NOT NULL,
  `deposito_liquido` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_adquirente` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_valor_fixo` decimal(10,2) NOT NULL,
  `executor_ordem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao_transacao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `efi`
--

CREATE TABLE `efi` (
  `id` bigint UNSIGNED NOT NULL,
  `access_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chave_pix` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identificador_conta` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cert` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT '5.00',
  `billet_tx_fixed` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billet_tx_percent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billet_days_availability` int NOT NULL DEFAULT '5',
  `card_tx_percent` decimal(10,2) NOT NULL DEFAULT '0.29',
  `card_tx_fixed` decimal(10,2) NOT NULL DEFAULT '4.99',
  `card_days_availability` int NOT NULL DEFAULT '5',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `efi`
--

INSERT INTO `efi` (`id`, `access_token`, `client_id`, `client_secret`, `chave_pix`, `identificador_conta`, `gateway_id`, `cert`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `billet_tx_fixed`, `billet_tx_percent`, `billet_days_availability`, `card_tx_percent`, `card_tx_fixed`, `card_days_availability`, `created_at`, `updated_at`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.00, 5.00, '3.5', '0.5', 1, 4.69, 4.99, 21, NULL, '2025-07-31 12:56:17', 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `gerente_apoio`
--

CREATE TABLE `gerente_apoio` (
  `id` int NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `imagem` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Despejando dados para a tabela `gerente_apoio`
--

INSERT INTO `gerente_apoio` (`id`, `titulo`, `descricao`, `imagem`, `created_at`, `updated_at`) VALUES
(1, 'titulo 1', 'descricao 1', '/uploads/material-apoio/682fa2a9a92b5.png', '2025-05-22 19:18:17', '2025-05-22 19:22:39'),
(2, 'titulo 2', 'descricao 2', '/uploads/material-apoio/682fa305debff.png', '2025-05-22 19:19:49', '2025-05-22 19:19:49'),
(3, 'titulo 3', 'descricao 3', '/uploads/material-apoio/682fa97426f80.png', '2025-05-22 19:47:16', '2025-05-22 19:47:16');

-- --------------------------------------------------------

--
-- Estrutura para tabela `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `landingpage`
--

CREATE TABLE `landingpage` (
  `id` int NOT NULL,
  `section1_title` varchar(255) DEFAULT 'A plataforma de vendas online inovadora.',
  `section1_description` varchar(255) DEFAULT 'Liberdade, alta conversão e baixa taxa para escalar as vendas do seu Infoproduto ou serviço.',
  `section1_image` varchar(255) DEFAULT '/LandingPage/img/AppIcon.svg',
  `section1_link` varchar(255) DEFAULT NULL,
  `section2_title` varchar(255) DEFAULT 'Plataforma de inovações.',
  `section2_description` varchar(255) DEFAULT 'Tudo o que você precisa para vender online com a melhor conversão.',
  `section2_image1` varchar(255) DEFAULT '/LandingPage/img/Checkout.png',
  `section2_image2` varchar(255) DEFAULT '/LandingPage/img/CheckoutBuilder.png',
  `section2_image3` varchar(255) DEFAULT '/LandingPage/img/UpSell.png',
  `section3_title` varchar(255) DEFAULT 'Tudo o que você precisa. Nós temos.',
  `section3_item1_image` varchar(255) DEFAULT '/LandingPage/img/Suporte.png',
  `section3_item1_title` varchar(255) DEFAULT 'Suporte',
  `section3_item1_description` varchar(255) DEFAULT 'Na PAY2PIX você tem gerentes de contas exclusivos que respondem em até 2 minutos 24 horas por dia',
  `section3_item2_image` varchar(255) DEFAULT '/LandingPage/img/Recupera%C3%A7%C3%A3oDeVendas.png',
  `section3_item2_title` varchar(255) DEFAULT 'Recuperação de Vendas',
  `section3_item2_description` varchar(255) DEFAULT 'Aqui temos um funil de recuperação validado por e-mail e SMS, diretamente integrado na plataforma!',
  `section3_item3_image` varchar(255) DEFAULT '/LandingPage/img/AltaConversao.png',
  `section3_item3_title` varchar(255) DEFAULT 'Alta conversão',
  `section3_item3_description` varchar(255) DEFAULT 'Nosso checkout otimizado junto à funcionalidades que só nós temos te oferece a maior conversão.',
  `section4_title` varchar(255) DEFAULT 'Assinaturas e SaaS',
  `section4_image` varchar(255) DEFAULT '/LandingPage/img/Assinaturas.png',
  `section4_description` varchar(255) DEFAULT 'Na PAY2PIX você pode receber assinaturas desde semanais até anuais. Contamos com avisos de renovação e recuperação assinaturas não renovadas. E para os players do mercado de Software, oferecemos uma API descomplicada para fazer a integração.',
  `section4_link` varchar(255) DEFAULT '#',
  `section5_title` varchar(255) DEFAULT 'Taxas e prazos na PAY2PIX.',
  `section5_description` varchar(255) DEFAULT 'O prazo de recebimento padrão de vendas por cartão é de 15 dias. Porém com a análise da sua operação pelo time de compliance, conseguimos antecipar em D+2',
  `section5_image` varchar(255) DEFAULT '/LandingPage/img/TaxasPrazos.png',
  `section6_title` varchar(255) DEFAULT 'Awards',
  `section6_description` varchar(255) DEFAULT 'Para a PAY2PIX não há nada mais importante do que você, nosso cliente, por isso te tratarmos como rei ou rainha!',
  `section6_image` varchar(255) DEFAULT '/LandingPage/img/Placas.png',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Despejando dados para a tabela `landingpage`
--

INSERT INTO `landingpage` (`id`, `section1_title`, `section1_description`, `section1_image`, `section1_link`, `section2_title`, `section2_description`, `section2_image1`, `section2_image2`, `section2_image3`, `section3_title`, `section3_item1_image`, `section3_item1_title`, `section3_item1_description`, `section3_item2_image`, `section3_item2_title`, `section3_item2_description`, `section3_item3_image`, `section3_item3_title`, `section3_item3_description`, `section4_title`, `section4_image`, `section4_description`, `section4_link`, `section5_title`, `section5_description`, `section5_image`, `section6_title`, `section6_description`, `section6_image`, `created_at`, `updated_at`) VALUES
(1, 'SUA COMPRA MAIS SEGURA', 'Somos um gateway de pagamento completo, que conecta negócios digitais a soluções financeiras inteligentes e seguras. Facilitamos transações, automatizamos processos e impulsionamos o crescimento de empresas de todos os portes no mercado digital.', '/landing/68cb0947e2a20.png', NULL, 'O melhor gateway de pagamentos do mercado', 'Com nossa tecnologia de ponta e colaborações estratégicas, garantimos uma taxa de aprovação de pagamento excepcionalmente alta, assegurando que praticamente todas as suas transações sejam processadas com êxito e segurança absoluta', '/landing/68cb0947e2b35.png', '/landing/68cb0947e2bce.png', '/landing/68cb0947e2c50.png', 'Tudo o que você precisa. Nós temos.', '/landing/68cb0947e2cc7.png', 'Suporte', 'Na Detsegpay você tem gerentes de contas exclusivos que respondem em até 2 minutos 24 horas por dia', '/landing/68cb0947e2d52.png', 'Recuperação de Vendas', 'Aqui temos um funil de recuperação validado por e-mail e SMS, diretamente integrado na plataforma!', '/landing/68cb0947e2f6a.png', 'Alta conversão', 'Nosso checkout otimizado junto à funcionalidades que só nós temos te oferece a maior conversão.', 'Assinaturas e SaaS', '/landing/68cb0947e30c3.png', 'Na Detsegpay você pode receber assinaturas desde semanais até anuais. Contamos com avisos de renovação e recuperação assinaturas não renovadas. E para os players do mercado de Software, oferecemos uma API descomplicada para fazer a integração.', '#', 'Taxas e prazos na Detsegpay', 'O prazo de recebimento padrão de vendas por PIX é instantâneo.', '/landing/68cb0947e3141.png', 'Abra sua conta na Detsegpay e mude o rumo do seu negócio digital.', 'Chega de sistemas fragmentados. Aqui você encontra soluções de ponta a ponta para cada passo do seu negócio digital.  Tecnologia 360º para quem quer escalar com segurança, simplicidade, automação e suporte de verdade.', '/landing/68cb0947e3267.png', '2025-04-29 13:24:23', '2025-09-17 16:17:27');

-- --------------------------------------------------------

--
-- Estrutura para tabela `logs_ip_cash_out`
--

CREATE TABLE `logs_ip_cash_out` (
  `id` bigint UNSIGNED NOT NULL,
  `ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `migrations`
--

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2025_02_07_162003_create_adquirentes_table', 1),
(5, '2025_02_07_162502_create_ad_primepags_table', 1),
(6, '2025_02_07_162731_create_apps_table', 1),
(7, '2025_02_07_163016_create_checkout_builds_table', 1),
(8, '2025_02_07_163427_create_pix_depositos_table', 1),
(9, '2025_02_07_163748_create_retiradas_table', 1),
(10, '2025_02_07_164039_create_confirmar_depositos_table', 1),
(11, '2025_02_07_164203_create_log_ip_cash_outs_table', 1),
(12, '2025_02_07_164233_create_segurancas_table', 1),
(13, '2025_02_07_164538_create_deposito_apis_table', 1),
(14, '2025_02_07_165353_create_solicitacoes_table', 1),
(15, '2025_02_07_165848_create_solicitacoes_cash_outs_table', 1),
(16, '2025_02_07_170024_create_transactions_table', 1),
(17, '2025_02_07_170119_create_users_keys_table', 1),
(18, '2025_02_07_173912_create_personal_access_tokens_table', 1),
(19, '2025_02_08_134847_add_username_in_table_users', 1),
(20, '2025_02_09_205605_add_permission_table_users', 1),
(21, '2025_02_09_230523_add_status_table_confirmar_deposito', 1),
(22, '2025_02_10_210736_create_pedidos_table', 1),
(23, '2025_02_10_214347_add_idtransaction_and_paymentcode_table_checkout_vendas', 1),
(24, '2025_02_10_224220_add_status_table_checkout_vendas', 1),
(25, '2025_02_11_133506_add_limite_saque_automatico', 2),
(26, '2025_02_12_002526_add_app_token_in_table_users', 3),
(27, '2025_02_19_110427_add_google2fa_to_users', 4),
(28, '2025_02_26_122015_add_ref_in_table_users', 5),
(29, '2025_04_28_120846_alter_keys_in_users', 6),
(30, '2025_04_28_175324_create_cashtimes_table', 7),
(34, '2025_05_19_072223_create_nivels_table', 8),
(35, '2025_07_06_211621_create_mercadopagos_table', 8),
(36, '2025_07_06_214618_add_filds', 9),
(37, '2025_07_06_224045_create_efis_table', 10),
(38, '2025_07_06_232648_add_filds', 11),
(39, '2025_07_09_171154_create_pagarmes_table', 12),
(40, '2025_07_10_110411_add_filds_taxas_in_users', 12),
(41, '2025_07_13_155513_add_filds_billet_in_solicitacoes', 12),
(42, '2025_07_13_160659_add_filds_billet_in_solicitacoes', 12),
(43, '2025_07_13_173219_add_field_methods_in_checkout_build', 12),
(44, '2025_07_14_160239_add_field_days_availability_in_table_solicitacoes', 12),
(45, '2025_07_23_165001_add_field_utmfy_in_table_checkout_build', 13),
(46, '2025_07_23_172358_add_field_utmfy_in_table_users', 14),
(47, '2025_08_17_114158_create_xgates_table', 15),
(48, '2025_08_20_151743_create_witetecs_table', 16),
(49, '2025_09_06_161421_add_pixup_to_adquirentes_table', 17),
(50, '2025_09_06_162043_create_pixups_table', 18),
(52, '2025_09_07_100714_add_preferred_adquirente_to_users_table', 19),
(53, '2025_09_07_101702_add_index_to_adquirentes_referencia', 20),
(54, '2025_09_07_101558_create_user_adquirente_configs_table', 21),
(55, '2025_09_07_131427_add_is_default_to_adquirentes_table', 22),
(56, '2025_09_08_093244_create_saque_configs_table', 23),
(57, '2025_09_08_094528_add_saque_ips_to_users_table', 24),
(58, '2025_09_09_110243_add_saque_automatico_to_app_table', 25),
(59, '2025_09_09_140956_create_woovi_table', 26),
(60, '2025_09_09_182248_add_woovi_identifier_to_solicitacoes_table', 27),
(61, '2025_09_10_165522_create_split_payments_table', 28),
(62, '2025_09_10_180906_add_pin_to_users_table', 29),
(63, '2025_09_10_181353_modify_pin_column_in_users_table', 30),
(78, '2025_09_17_133611_fix_taxa_fixa_padrao_cash_out_decimal_precision', 43),
(97, '2025_10_04_115912_create_split_internos_table', 44),
(98, '2025_09_10_195446_create_bspay_table', 45),
(99, '2025_09_10_201339_add_flexible_tax_fields_to_app_table', 45),
(100, '2025_09_10_203045_remove_tax_fields_from_adquirentes_tables', 45),
(101, '2025_09_11_170938_add_allowed_domains_to_apps_table', 45),
(102, '2025_09_12_141703_add_taxa_flexivel_fields_to_users_table', 45),
(103, '2025_09_12_144320_add_saque_api_cripto_tax_fields_to_app_table', 45),
(104, '2025_09_12_144759_add_saque_api_cripto_tax_fields_to_users_table', 45),
(105, '2025_09_12_145015_add_taxas_personalizadas_ativas_to_users_table', 45),
(106, '2025_09_12_160415_add_global_ips_to_app_table', 45),
(107, '2025_09_12_164821_add_2fa_fields_to_users_table', 45),
(108, '2025_09_14_020442_create_asaas_table', 45),
(109, '2025_09_14_193939_create_syscoop_table', 45),
(110, '2025_09_14_194225_add_syscoop_to_adquirentes_table', 45),
(111, '2025_09_15_125124_add_executor_ordem_to_solicitacoes_cash_out_table', 45),
(112, '2025_09_17_150032_fix_taxa_fixa_padrao_cash_out_decimal_precision', 45),
(113, '2025_09_18_174828_add_taxa_por_fora_to_app_table', 45),
(114, '2025_09_18_181006_add_taxa_fields_to_bspay_table', 45),
(115, '2025_09_19_104324_remove_taxas_personalizadas_from_users_table', 45),
(116, '2025_09_19_133140_add_taxa_fixa_pix_to_app_table', 45),
(117, '2025_09_19_135436_add_commission_fields_to_transactions_table', 45),
(118, '2025_09_19_145543_add_taxas_personalizadas_to_users_table', 45),
(119, '2025_09_19_151032_add_relatorio_personalizacao_to_apps_table', 45),
(120, '2025_09_29_123607_create_primepay7_table', 45),
(121, '2025_09_29_123821_add_primepay7_to_adquirentes_table', 45),
(122, '2025_09_29_124913_add_keys_to_primepay7_table', 45),
(123, '2025_09_29_125643_remove_client_fields_from_primepay7_table', 45),
(124, '2025_09_29_142437_add_contrato_social_to_users_table', 45),
(125, '2025_10_01_015617_create_xdpag_table', 45),
(126, '2025_10_01_015843_add_xdpag_to_adquirentes_table', 45),
(127, '2025_10_04_124957_add_affiliate_fields_to_users_table', 46),
(128, '2025_10_01_020425_update_xdpag_table_remove_unnecessary_fields', 47),
(129, '2025_10_01_020609_update_xdpag_table_add_basic_fields', 47),
(130, '2025_10_06_161042_remove_app_id_from_woovi_table', 47),
(131, '2025_10_06_161247_remove_sandbox_from_primepay7_table', 48),
(132, '2025_10_06_182226_add_gateway_logo_dark_to_app_table', 49),
(133, '2025_10_06_183250_add_payment_types_to_adquirentes_table', 50),
(135, '2025_10_06_183258_add_specific_adquirente_fields_to_users_table', 51),
(136, '2025_10_07_004427_create_push_tokens_table', 52),
(137, '2025_10_07_004428_create_notifications_table', 52),
(138, '2025_10_08_130224_add_separate_adquirente_fields_to_adquirentes_and_users_tables', 53);

-- --------------------------------------------------------

--
-- Estrutura para tabela `niveis`
--

CREATE TABLE `niveis` (
  `id` bigint UNSIGNED NOT NULL,
  `nome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `minimo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `maximo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `niveis`
--

INSERT INTO `niveis` (`id`, `nome`, `cor`, `icone`, `minimo`, `maximo`, `created_at`, `updated_at`) VALUES
(1, 'Bronze', '#ff8800', NULL, 0.00, 100000.00, '2025-05-19 08:35:13', '2025-07-07 08:43:59'),
(2, 'Prata', '#5e5e5e', NULL, 100001.00, 500000.00, '2025-05-19 08:44:07', '2025-07-07 08:44:21'),
(3, 'Ouro', '#d6c400', NULL, 500001.00, 1000000.00, '2025-05-19 08:45:18', '2025-07-07 08:44:30'),
(4, 'Safira', '#0066cc', NULL, 1000001.00, 5000000.00, '2025-05-19 08:45:35', '2025-07-07 08:44:36'),
(5, 'Diamante', '#00ccff', NULL, 5000001.00, 10000000.00, '2025-05-19 08:45:35', '2025-07-07 08:44:36');

-- --------------------------------------------------------

--
-- Estrutura para tabela `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'transaction',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `push_sent` tinyint(1) NOT NULL DEFAULT '0',
  `local_sent` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pagarme`
--

CREATE TABLE `pagarme` (
  `id` bigint UNSIGNED NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'https://api.pagar.me',
  `url_cash_in` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'https://api.pagar.me/core/v5/orders',
  `url_cash_out` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'https://api.pagar.me/core/v5/transaction',
  `taxa_pix_cash_in` decimal(8,2) DEFAULT NULL,
  `taxa_pix_cash_out` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `pagarme`
--

INSERT INTO `pagarme` (`id`, `token`, `secret`, `url`, `url_cash_in`, `url_cash_out`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `created_at`, `updated_at`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, 'https://api.pagar.me', 'https://api.pagar.me/core/v5/orders', 'https://api.pagar.me/core/v5/transaction', NULL, NULL, NULL, NULL, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pixup`
--

CREATE TABLE `pixup` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.pixupbr.com/v2/',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `pixup`
--

INSERT INTO `pixup` (`id`, `client_id`, `client_secret`, `url`, `created_at`, `updated_at`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, 'https://api.pixupbr.com/v2/', '2025-09-06 19:21:25', '2025-09-17 13:30:55', 0.00, 0.00, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `pix_deposito`
--

CREATE TABLE `pix_deposito` (
  `id` bigint UNSIGNED NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `primepay7`
--

CREATE TABLE `primepay7` (
  `id` bigint UNSIGNED NOT NULL,
  `private_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `withdrawal_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.primepay7.com',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `primepay7`
--

INSERT INTO `primepay7` (`id`, `private_key`, `public_key`, `withdrawal_key`, `url`, `status`, `created_at`, `updated_at`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, NULL, 'https://api.primepay7.com', 1, '2025-10-08 13:36:28', '2025-10-08 13:36:28', 0.00, 0.00, 1.00, 1.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `push_tokens`
--

CREATE TABLE `push_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'expo',
  `device_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `retiradas`
--

CREATE TABLE `retiradas` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `valor_liquido` decimal(10,2) NOT NULL,
  `tipo_chave` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `chave` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_solicitacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_pagamento` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `taxa_cash_out` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `saque_configs`
--

CREATE TABLE `saque_configs` (
  `id` bigint UNSIGNED NOT NULL,
  `saque_automatico` tinyint(1) NOT NULL DEFAULT '0',
  `teto_maximo_saque` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `ips_permitidos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `saque_configs`
--

INSERT INTO `saque_configs` (`id`, `saque_automatico`, `teto_maximo_saque`, `ips_permitidos`, `created_at`, `updated_at`) VALUES
(1, 0, 1000.00, '[]', '2025-09-08 12:40:31', '2025-09-08 12:40:31');

-- --------------------------------------------------------

--
-- Estrutura para tabela `seguranca`
--

CREATE TABLE `seguranca` (
  `id` bigint UNSIGNED NOT NULL,
  `keyseguranca` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `sixxpayments`
--

CREATE TABLE `sixxpayments` (
  `id` bigint UNSIGNED NOT NULL,
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://sixxpayments.com/api/',
  `url_cash_in` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://sixxpayments.com/api/wallet/deposit/payment',
  `url_cash_out` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://sixxpayments.com/api/send/transfer/pix',
  `url_webhook_deposit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_webhook_payment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT '5.00',
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT '5.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `sixxpayments`
--

INSERT INTO `sixxpayments` (`id`, `secret`, `token`, `url`, `url_cash_in`, `url_cash_out`, `url_webhook_deposit`, `url_webhook_payment`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, 'https://sixxpayments.com/api/', 'https://sixxpayments.com/api/wallet/deposit/payment', 'https://sixxpayments.com/api/send/transfer/pix', NULL, NULL, 5.00, 0.00, '2025-04-28 15:01:47', '2025-05-10 16:27:20');

-- --------------------------------------------------------

--
-- Estrutura para tabela `solicitacoes`
--

CREATE TABLE `solicitacoes` (
  `id` bigint UNSIGNED NOT NULL,
  `method` enum('pix','billet','card') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pix',
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `externalreference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `client_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` datetime NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `idTransaction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `primepay7_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `woovi_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deposito_liquido` decimal(10,2) NOT NULL,
  `qrcode_pix` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `paymentcode` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `paymentCodeBase64` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `billet_download` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adquirente_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `taxa_cash_in` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_adquirente` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_valor_fixo` decimal(10,2) NOT NULL,
  `client_telefone` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `executor_ordem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao_transacao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `callback` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `split_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `split_percentage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banking_billet` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `days_availability` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expire_at` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `solicitacoes_cash_out`
--

CREATE TABLE `solicitacoes_cash_out` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `externalreference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `beneficiaryname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `beneficiarydocument` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pix` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pixkey` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` datetime NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `idTransaction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primepay7_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `taxa_cash_out` decimal(10,2) NOT NULL,
  `cash_out_liquido` decimal(10,2) NOT NULL,
  `end_to_end` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao_transacao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `executor_ordem` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `callback` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao_externa` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `blockchainNetwork` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `cryptocurrency` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `split_internos`
--

CREATE TABLE `split_internos` (
  `id` bigint UNSIGNED NOT NULL,
  `usuario_beneficiario_id` bigint UNSIGNED NOT NULL,
  `usuario_pagador_id` bigint UNSIGNED NOT NULL,
  `porcentagem_split` decimal(5,2) NOT NULL,
  `tipo_taxa` enum('deposito','saque_pix') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'deposito',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_por_admin_id` bigint UNSIGNED NOT NULL,
  `data_inicio` timestamp NULL DEFAULT NULL,
  `data_fim` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `split_internos_executados`
--

CREATE TABLE `split_internos_executados` (
  `id` bigint UNSIGNED NOT NULL,
  `split_internos_id` bigint UNSIGNED NOT NULL,
  `solicitacao_id` bigint UNSIGNED DEFAULT NULL,
  `usuario_pagador_id` bigint UNSIGNED NOT NULL,
  `usuario_beneficiario_id` bigint UNSIGNED NOT NULL,
  `valor_taxa_original` decimal(12,2) NOT NULL,
  `valor_split` decimal(12,2) NOT NULL,
  `porcentagem_aplicada` decimal(5,2) NOT NULL,
  `status` enum('pendente','processado','falhado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `processado_em` timestamp NULL DEFAULT NULL,
  `observacoes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `split_payments`
--

CREATE TABLE `split_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `solicitacao_id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `split_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `split_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `split_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `split_status` enum('pending','processing','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `split_type` enum('percentage','fixed','partner','affiliate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `processed_at` timestamp NULL DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `syscoop`
--

CREATE TABLE `syscoop` (
  `id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gerente_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solicitacao_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comission_value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `transaction_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `comission_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `price` decimal(20,0) NOT NULL,
  `status` tinyint NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `gerente_id` int DEFAULT NULL,
  `affiliate_id` bigint UNSIGNED DEFAULT NULL,
  `gerente_percentage` decimal(10,2) NOT NULL DEFAULT '0.00',
  `affiliate_percentage` decimal(5,2) DEFAULT '0.00',
  `is_affiliate` tinyint(1) DEFAULT '0',
  `affiliate_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `affiliate_link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `referral_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gerente_aprovar` tinyint(1) NOT NULL DEFAULT '0',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_fantasia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `razao_social` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cartao_cnpj` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cpf` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pin_active` tinyint(1) NOT NULL DEFAULT '0',
  `pin_created_at` timestamp NULL DEFAULT NULL,
  `cpf_cnpj` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_nascimento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saldo` double NOT NULL DEFAULT '0',
  `saldo_bloqueado` decimal(10,0) NOT NULL DEFAULT '0',
  `total_transacoes` int NOT NULL DEFAULT '0',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_user` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transacoes_aproved` int NOT NULL DEFAULT '0',
  `transacoes_recused` int NOT NULL DEFAULT '0',
  `valor_sacado` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_saque_pendente` decimal(10,2) NOT NULL DEFAULT '0.00',
  `token` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banido` tinyint(1) NOT NULL DEFAULT '0',
  `cliente_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `taxa_percentual` decimal(10,2) NOT NULL DEFAULT '5.00',
  `volume_transacional` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_pago_taxa` decimal(10,2) NOT NULL DEFAULT '0.00',
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cep` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rua` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cidade` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bairro` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_residencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complemento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_rg_frente` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_rg_verso` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selfie_rg` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contrato_social` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `media_faturamento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo_referencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whitelisted_ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pushcut_pixpago` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permission` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `app_token` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `google2fa_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google2fa_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `google2fa_enabled_at` timestamp NULL DEFAULT NULL,
  `code_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indicador_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_endpoint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '[]',
  `integracao_utmfy` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxas_personalizadas_ativas` tinyint(1) NOT NULL DEFAULT '0',
  `taxa_percentual_deposito` decimal(5,2) DEFAULT NULL,
  `taxa_fixa_deposito` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_minimo_deposito` decimal(10,2) DEFAULT NULL,
  `taxa_percentual_pix` decimal(5,2) DEFAULT NULL,
  `taxa_minima_pix` decimal(10,2) DEFAULT NULL,
  `taxa_fixa_pix` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_minimo_saque` decimal(10,2) DEFAULT NULL,
  `limite_mensal_pf` decimal(12,2) DEFAULT NULL,
  `taxa_saque_api` decimal(5,2) DEFAULT NULL,
  `taxa_saque_crypto` decimal(5,2) DEFAULT NULL,
  `sistema_flexivel_ativo` tinyint(1) NOT NULL DEFAULT '0',
  `valor_minimo_flexivel` decimal(10,2) DEFAULT NULL,
  `taxa_fixa_baixos` decimal(10,2) DEFAULT NULL,
  `taxa_percentual_altos` decimal(5,2) DEFAULT NULL,
  `observacoes_taxas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `preferred_adquirente` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_adquirente_card_billet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adquirente_override` tinyint(1) NOT NULL DEFAULT '0',
  `adquirente_card_billet_override` tinyint(1) NOT NULL DEFAULT '0',
  `preferred_adquirente_pix` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adquirente_override_pix` tinyint(1) NOT NULL DEFAULT '0',
  `preferred_adquirente_card` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adquirente_override_card` tinyint(1) NOT NULL DEFAULT '0',
  `saque_automatico` tinyint(1) NOT NULL DEFAULT '0',
  `teto_maximo_saque` decimal(10,2) DEFAULT NULL,
  `ips_saque_permitidos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `twofa_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `twofa_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `twofa_enabled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `users`
--

INSERT INTO `users` (`id`, `gerente_id`, `affiliate_id`, `gerente_percentage`, `affiliate_percentage`, `is_affiliate`, `affiliate_code`, `affiliate_link`, `referral_code`, `gerente_aprovar`, `name`, `nome_fantasia`, `razao_social`, `cartao_cnpj`, `cpf`, `username`, `email`, `email_verified_at`, `password`, `remember_token`, `pin`, `pin_active`, `pin_created_at`, `cpf_cnpj`, `data_nascimento`, `telefone`, `saldo`, `saldo_bloqueado`, `total_transacoes`, `avatar`, `status`, `data_cadastro`, `ip_user`, `transacoes_aproved`, `transacoes_recused`, `valor_sacado`, `valor_saque_pendente`, `token`, `banido`, `cliente_id`, `taxa_percentual`, `volume_transacional`, `valor_pago_taxa`, `user_id`, `cep`, `rua`, `estado`, `cidade`, `bairro`, `numero_residencia`, `complemento`, `foto_rg_frente`, `foto_rg_verso`, `selfie_rg`, `contrato_social`, `media_faturamento`, `codigo_referencia`, `whitelisted_ip`, `pushcut_pixpago`, `permission`, `created_at`, `updated_at`, `app_token`, `google2fa_secret`, `google2fa_enabled`, `google2fa_enabled_at`, `code_ref`, `indicador_ref`, `webhook_url`, `webhook_endpoint`, `integracao_utmfy`, `taxas_personalizadas_ativas`, `taxa_percentual_deposito`, `taxa_fixa_deposito`, `valor_minimo_deposito`, `taxa_percentual_pix`, `taxa_minima_pix`, `taxa_fixa_pix`, `valor_minimo_saque`, `limite_mensal_pf`, `taxa_saque_api`, `taxa_saque_crypto`, `sistema_flexivel_ativo`, `valor_minimo_flexivel`, `taxa_fixa_baixos`, `taxa_percentual_altos`, `observacoes_taxas`, `preferred_adquirente`, `preferred_adquirente_card_billet`, `adquirente_override`, `adquirente_card_billet_override`, `preferred_adquirente_pix`, `adquirente_override_pix`, `preferred_adquirente_card`, `adquirente_override_card`, `saque_automatico`, `teto_maximo_saque`, `ips_saque_permitidos`, `twofa_secret`, `twofa_enabled`, `twofa_enabled_at`) VALUES
(1, NULL, NULL, 0.00, 0.00, 0, NULL, NULL, NULL, 0, 'GATEWAY ADMIN', NULL, NULL, NULL, NULL, 'admin', 'admin@admin.com', NULL, '$2y$12$XOBdps.wlvNXpofbTd.nFuzHes58m.qTcBgY2WgBeRfzE.HyNqfdC', 'eKDccgLRBiiq7YwAXSpKxnnzHkYaXER0FT5JRkFroO8INT1OgZ93VCFDvIe2', '$2y$12$zYxfrAzmGGgxQeMZF3XuBuh3mxTEuZFZrrwFRuNsHZxuspMEszMfq', 1, '2025-10-07 03:00:27', '40.073.909/0001-94', '1993-03-05', '(27) 99700-6570', 0, 0, 0, '/uploads/avatars/avatar_default.jpg', 1, '2025-02-11 10:31:45', NULL, 0, 38, 2257.00, 0.00, NULL, 0, 'd8e0ab0f-a5f6-45e4-9da2-b8231ded4b37', 5.00, 0.00, 0.00, 'admin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, '2025-02-11 13:31:45', '2025-10-08 20:01:24', '', 'ZDOK6L6ZNL6VQDPF', 0, NULL, NULL, NULL, 'https://papagaiopgbet.com/gateway/playgame_pix_webhook.php', '\"[\\\"gerado\\\",\\\"pago\\\"]\"', '1231233', 0, NULL, 0.00, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'woovi', 'primepay7', 1, 1, 'woovi', 1, NULL, 0, 0, NULL, '[\"31.97.25.193\",\"191.44.21.53\",\"18.228.6.105\",\"72.60.57.206\"]', 'ZUA6OFULVEH63K2X', 0, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `users_key`
--

CREATE TABLE `users_key` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `users_key`
--

INSERT INTO `users_key` (`id`, `user_id`, `secret`, `status`, `created_at`, `updated_at`, `token`) VALUES
(118, 'admin', 'f33d6de2-2ec2-4de3-badb-efe0fda467b7', '1', '2025-04-15 19:43:23', '2025-09-09 20:38:57', '82b404ab-fd93-48c6-a034-6eacbaa816b1');

-- --------------------------------------------------------

--
-- Estrutura para tabela `user_adquirente_configs`
--

CREATE TABLE `user_adquirente_configs` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `adquirente_referencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `witetec`
--

CREATE TABLE `witetec` (
  `id` bigint UNSIGNED NOT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.witetec.net',
  `api_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tx_billet_fixed` decimal(10,2) NOT NULL DEFAULT '5.00',
  `tx_billet_percent` decimal(10,2) NOT NULL DEFAULT '5.00',
  `tx_card_fixed` decimal(10,2) NOT NULL DEFAULT '5.00',
  `tx_card_percent` decimal(10,2) NOT NULL DEFAULT '5.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `witetec`
--

INSERT INTO `witetec` (`id`, `url`, `api_token`, `tx_billet_fixed`, `tx_billet_percent`, `tx_card_fixed`, `tx_card_percent`, `created_at`, `updated_at`) VALUES
(1, 'https://api.witetec.net', NULL, 5.00, 5.00, 5.00, 5.00, NULL, '2025-08-25 15:01:20');

-- --------------------------------------------------------

--
-- Estrutura para tabela `woovi`
--

CREATE TABLE `woovi` (
  `id` bigint UNSIGNED NOT NULL,
  `api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `webhook_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.woovi.com',
  `sandbox` tinyint(1) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `woovi`
--

INSERT INTO `woovi` (`id`, `api_key`, `webhook_secret`, `url`, `sandbox`, `status`, `created_at`, `updated_at`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, 'Q2xpZW50X0lkXzc4MDkwZTUxLTc1ZWYtNDQwOC1iNmI3LTZiNzg5ZDMxYzg4MDpDbGllbnRfU2VjcmV0X05KQm5obForaU9QMlVqeURYTmdXTm45TTlOajBNbHJRT2tPMXVtRFR3RU09', '1IDRtJgQARaygp3gTeVCmkQe7FFGj0lZ', 'https://api.woovi-sandbox.com', 1, 1, '2025-09-09 18:28:14', '2025-10-08 19:53:12', 0.00, 0.00, 0.80, 1.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `xdpag`
--

CREATE TABLE `xdpag` (
  `id` bigint UNSIGNED NOT NULL,
  `webhook_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `xgate`
--

CREATE TABLE `xgate` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_entradas` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taxa_adquirente_saidas` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `xgate`
--

INSERT INTO `xgate` (`id`, `email`, `password`, `created_at`, `updated_at`, `taxa_pix_cash_in`, `taxa_pix_cash_out`, `taxa_adquirente_entradas`, `taxa_adquirente_saidas`) VALUES
(1, NULL, NULL, NULL, '2025-08-20 16:26:59', 0.00, 0.00, 0.00, 0.00);

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `adquirentes`
--
ALTER TABLE `adquirentes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `adquirentes_adquirente_unique` (`adquirente`),
  ADD UNIQUE KEY `adquirentes_referencia_unique` (`referencia`),
  ADD KEY `adquirentes_referencia_index` (`referencia`);

--
-- Índices de tabela `ad_mercadopago`
--
ALTER TABLE `ad_mercadopago`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `ad_primepag`
--
ALTER TABLE `ad_primepag`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `app`
--
ALTER TABLE `app`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `asaas`
--
ALTER TABLE `asaas`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `bspay`
--
ALTER TABLE `bspay`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`);

--
-- Índices de tabela `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`);

--
-- Índices de tabela `cashtimes`
--
ALTER TABLE `cashtimes`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `checkout_build`
--
ALTER TABLE `checkout_build`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_unico` (`id_unico`),
  ADD KEY `fk_user_id` (`user_id`);

--
-- Índices de tabela `checkout_depoimentos`
--
ALTER TABLE `checkout_depoimentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_checkout_depoimentos_checkout` (`checkout_id`);

--
-- Índices de tabela `checkout_orders`
--
ALTER TABLE `checkout_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `checkout_id` (`checkout_id`);

--
-- Índices de tabela `checkout_order_bumps`
--
ALTER TABLE `checkout_order_bumps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_checkout_order_bumps_checkout` (`checkout_id`);

--
-- Índices de tabela `confirmar_deposito`
--
ALTER TABLE `confirmar_deposito`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `depositos_api`
--
ALTER TABLE `depositos_api`
  ADD PRIMARY KEY (`id`),
  ADD KEY `depositos_api_user_id_foreign` (`user_id`);

--
-- Índices de tabela `efi`
--
ALTER TABLE `efi`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Índices de tabela `gerente_apoio`
--
ALTER TABLE `gerente_apoio`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Índices de tabela `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `landingpage`
--
ALTER TABLE `landingpage`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `logs_ip_cash_out`
--
ALTER TABLE `logs_ip_cash_out`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `niveis`
--
ALTER TABLE `niveis`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_user_id_read_at_index` (`user_id`,`read_at`),
  ADD KEY `notifications_user_id_type_index` (`user_id`,`type`),
  ADD KEY `notifications_user_id_index` (`user_id`);

--
-- Índices de tabela `pagarme`
--
ALTER TABLE `pagarme`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Índices de tabela `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Índices de tabela `pixup`
--
ALTER TABLE `pixup`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `pix_deposito`
--
ALTER TABLE `pix_deposito`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pix_deposito_user_id_foreign` (`user_id`);

--
-- Índices de tabela `primepay7`
--
ALTER TABLE `primepay7`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `push_tokens`
--
ALTER TABLE `push_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `push_tokens_token_unique` (`token`),
  ADD KEY `push_tokens_user_id_is_active_index` (`user_id`,`is_active`),
  ADD KEY `push_tokens_user_id_index` (`user_id`);

--
-- Índices de tabela `retiradas`
--
ALTER TABLE `retiradas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `retiradas_user_id_foreign` (`user_id`);

--
-- Índices de tabela `saque_configs`
--
ALTER TABLE `saque_configs`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `seguranca`
--
ALTER TABLE `seguranca`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Índices de tabela `sixxpayments`
--
ALTER TABLE `sixxpayments`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `solicitacoes`
--
ALTER TABLE `solicitacoes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `solicitacoes_idtransaction_unique` (`idTransaction`),
  ADD KEY `solicitacoes_user_id_foreign` (`user_id`);

--
-- Índices de tabela `solicitacoes_cash_out`
--
ALTER TABLE `solicitacoes_cash_out`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `solicitacoes_cash_out_idtransaction_unique` (`idTransaction`),
  ADD KEY `solicitacoes_cash_out_user_id_foreign` (`user_id`);

--
-- Índices de tabela `split_internos`
--
ALTER TABLE `split_internos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_split_config` (`usuario_pagador_id`,`usuario_beneficiario_id`,`tipo_taxa`),
  ADD KEY `split_internos_usuario_pagador_id_tipo_taxa_ativo_index` (`usuario_pagador_id`,`tipo_taxa`,`ativo`),
  ADD KEY `split_internos_usuario_beneficiario_id_ativo_index` (`usuario_beneficiario_id`,`ativo`),
  ADD KEY `split_internos_usuario_beneficiario_id_index` (`usuario_beneficiario_id`),
  ADD KEY `split_internos_usuario_pagador_id_index` (`usuario_pagador_id`),
  ADD KEY `split_internos_criado_por_admin_id_index` (`criado_por_admin_id`);

--
-- Índices de tabela `split_internos_executados`
--
ALTER TABLE `split_internos_executados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `split_internos_executados_split_internos_id_foreign` (`split_internos_id`),
  ADD KEY `split_internos_executados_solicitacao_id_foreign` (`solicitacao_id`),
  ADD KEY `idx_executados_benef_status_data` (`usuario_beneficiario_id`,`status`,`created_at`),
  ADD KEY `idx_executados_pag_status_data` (`usuario_pagador_id`,`status`,`created_at`);

--
-- Índices de tabela `split_payments`
--
ALTER TABLE `split_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `split_payments_solicitacao_id_index` (`solicitacao_id`),
  ADD KEY `split_payments_user_id_index` (`user_id`),
  ADD KEY `split_payments_split_status_index` (`split_status`),
  ADD KEY `split_payments_split_type_index` (`split_type`),
  ADD KEY `split_payments_created_at_index` (`created_at`);

--
-- Índices de tabela `syscoop`
--
ALTER TABLE `syscoop`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_user_id_foreign` (`user_id`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `users_user_id_unique` (`user_id`),
  ADD UNIQUE KEY `code_ref` (`code_ref`),
  ADD UNIQUE KEY `affiliate_code` (`affiliate_code`),
  ADD KEY `fk_users_indicador_ref` (`indicador_ref`),
  ADD KEY `affiliate_id` (`affiliate_id`);

--
-- Índices de tabela `users_key`
--
ALTER TABLE `users_key`
  ADD PRIMARY KEY (`id`),
  ADD KEY `users_key_user_id_foreign` (`user_id`);

--
-- Índices de tabela `user_adquirente_configs`
--
ALTER TABLE `user_adquirente_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_adquirente` (`user_id`,`adquirente_referencia`),
  ADD KEY `adquirente_referencia` (`adquirente_referencia`);

--
-- Índices de tabela `witetec`
--
ALTER TABLE `witetec`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `woovi`
--
ALTER TABLE `woovi`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `xdpag`
--
ALTER TABLE `xdpag`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `xgate`
--
ALTER TABLE `xgate`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `adquirentes`
--
ALTER TABLE `adquirentes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT de tabela `ad_mercadopago`
--
ALTER TABLE `ad_mercadopago`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `ad_primepag`
--
ALTER TABLE `ad_primepag`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `app`
--
ALTER TABLE `app`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `asaas`
--
ALTER TABLE `asaas`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `bspay`
--
ALTER TABLE `bspay`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `cashtimes`
--
ALTER TABLE `cashtimes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `checkout_build`
--
ALTER TABLE `checkout_build`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de tabela `checkout_depoimentos`
--
ALTER TABLE `checkout_depoimentos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `checkout_orders`
--
ALTER TABLE `checkout_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `checkout_order_bumps`
--
ALTER TABLE `checkout_order_bumps`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `confirmar_deposito`
--
ALTER TABLE `confirmar_deposito`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `depositos_api`
--
ALTER TABLE `depositos_api`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `efi`
--
ALTER TABLE `efi`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `gerente_apoio`
--
ALTER TABLE `gerente_apoio`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `landingpage`
--
ALTER TABLE `landingpage`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `logs_ip_cash_out`
--
ALTER TABLE `logs_ip_cash_out`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT de tabela `niveis`
--
ALTER TABLE `niveis`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de tabela `pagarme`
--
ALTER TABLE `pagarme`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `pixup`
--
ALTER TABLE `pixup`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `pix_deposito`
--
ALTER TABLE `pix_deposito`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `primepay7`
--
ALTER TABLE `primepay7`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `push_tokens`
--
ALTER TABLE `push_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `retiradas`
--
ALTER TABLE `retiradas`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `saque_configs`
--
ALTER TABLE `saque_configs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `seguranca`
--
ALTER TABLE `seguranca`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `sixxpayments`
--
ALTER TABLE `sixxpayments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `solicitacoes`
--
ALTER TABLE `solicitacoes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=410;

--
-- AUTO_INCREMENT de tabela `solicitacoes_cash_out`
--
ALTER TABLE `solicitacoes_cash_out`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT de tabela `split_internos`
--
ALTER TABLE `split_internos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `split_internos_executados`
--
ALTER TABLE `split_internos_executados`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `split_payments`
--
ALTER TABLE `split_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de tabela `syscoop`
--
ALTER TABLE `syscoop`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1565;

--
-- AUTO_INCREMENT de tabela `users_key`
--
ALTER TABLE `users_key`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=267;

--
-- AUTO_INCREMENT de tabela `user_adquirente_configs`
--
ALTER TABLE `user_adquirente_configs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `witetec`
--
ALTER TABLE `witetec`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `woovi`
--
ALTER TABLE `woovi`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `xdpag`
--
ALTER TABLE `xdpag`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `xgate`
--
ALTER TABLE `xgate`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `checkout_build`
--
ALTER TABLE `checkout_build`
  ADD CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `checkout_depoimentos`
--
ALTER TABLE `checkout_depoimentos`
  ADD CONSTRAINT `fk_checkout_depoimentos_checkout` FOREIGN KEY (`checkout_id`) REFERENCES `checkout_build` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `checkout_orders`
--
ALTER TABLE `checkout_orders`
  ADD CONSTRAINT `checkout_orders_ibfk_1` FOREIGN KEY (`checkout_id`) REFERENCES `checkout_build` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `checkout_order_bumps`
--
ALTER TABLE `checkout_order_bumps`
  ADD CONSTRAINT `fk_checkout_order_bumps_checkout` FOREIGN KEY (`checkout_id`) REFERENCES `checkout_build` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `depositos_api`
--
ALTER TABLE `depositos_api`
  ADD CONSTRAINT `depositos_api_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `pix_deposito`
--
ALTER TABLE `pix_deposito`
  ADD CONSTRAINT `pix_deposito_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `retiradas`
--
ALTER TABLE `retiradas`
  ADD CONSTRAINT `retiradas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `solicitacoes`
--
ALTER TABLE `solicitacoes`
  ADD CONSTRAINT `solicitacoes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `solicitacoes_cash_out`
--
ALTER TABLE `solicitacoes_cash_out`
  ADD CONSTRAINT `solicitacoes_cash_out_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `split_internos`
--
ALTER TABLE `split_internos`
  ADD CONSTRAINT `split_internos_criado_por_admin_id_foreign` FOREIGN KEY (`criado_por_admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `split_internos_usuario_beneficiario_id_foreign` FOREIGN KEY (`usuario_beneficiario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `split_internos_usuario_pagador_id_foreign` FOREIGN KEY (`usuario_pagador_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `split_internos_executados`
--
ALTER TABLE `split_internos_executados`
  ADD CONSTRAINT `split_internos_executados_solicitacao_id_foreign` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `split_internos_executados_split_internos_id_foreign` FOREIGN KEY (`split_internos_id`) REFERENCES `split_internos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `split_internos_executados_usuario_beneficiario_id_foreign` FOREIGN KEY (`usuario_beneficiario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `split_internos_executados_usuario_pagador_id_foreign` FOREIGN KEY (`usuario_pagador_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `split_payments`
--
ALTER TABLE `split_payments`
  ADD CONSTRAINT `split_payments_solicitacao_id_foreign` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `split_payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_indicador_ref` FOREIGN KEY (`indicador_ref`) REFERENCES `users` (`code_ref`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`affiliate_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `users_key`
--
ALTER TABLE `users_key`
  ADD CONSTRAINT `users_key_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `user_adquirente_configs`
--
ALTER TABLE `user_adquirente_configs`
  ADD CONSTRAINT `user_adquirente_configs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_adquirente_configs_ibfk_2` FOREIGN KEY (`adquirente_referencia`) REFERENCES `adquirentes` (`referencia`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
