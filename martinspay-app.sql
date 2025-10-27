-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: martinspay_app
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `ad_mercadopago`
--

DROP TABLE IF EXISTS `ad_mercadopago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ad_mercadopago` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `access_token` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT 5.00,
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT 5.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ad_mercadopago`
--

LOCK TABLES `ad_mercadopago` WRITE;
/*!40000 ALTER TABLE `ad_mercadopago` DISABLE KEYS */;
/*!40000 ALTER TABLE `ad_mercadopago` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ad_primepag`
--

DROP TABLE IF EXISTS `ad_primepag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ad_primepag` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.primepag.com.br',
  `url_cash_in` varchar(255) NOT NULL DEFAULT 'https://api.primepag.com.br/v1/pix/qrcodes',
  `url_cash_out` varchar(255) NOT NULL DEFAULT 'https://api.primepag.com.br/v1/pix/payments',
  `url_webhook_deposit` varchar(255) DEFAULT NULL,
  `url_webhook_payment` varchar(255) DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT 5.00,
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ad_primepag`
--

LOCK TABLES `ad_primepag` WRITE;
/*!40000 ALTER TABLE `ad_primepag` DISABLE KEYS */;
/*!40000 ALTER TABLE `ad_primepag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adquirentes`
--

DROP TABLE IF EXISTS `adquirentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adquirentes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adquirente` varchar(255) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `url` varchar(255) NOT NULL,
  `referencia` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_default_card_billet` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adquirentes_adquirente_unique` (`adquirente`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adquirentes`
--

LOCK TABLES `adquirentes` WRITE;
/*!40000 ALTER TABLE `adquirentes` DISABLE KEYS */;
INSERT INTO `adquirentes` VALUES (1,'Pixup',0,'https://api.pixupbr.com/v2/','pixup','2025-10-27 12:49:10','2025-10-27 12:49:10',0,0),(2,'PrimePay7',0,'https://api.primepay7.com','primepay7','2025-10-27 12:49:10','2025-10-27 12:49:10',0,0),(3,'XDPag',0,'https://api.xdpag.com','xdpag','2025-10-27 12:49:10','2025-10-27 12:49:10',0,0);
/*!40000 ALTER TABLE `adquirentes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app`
--

DROP TABLE IF EXISTS `app`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `numero_users` int(11) NOT NULL DEFAULT 0,
  `faturamento_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_transacoes` decimal(10,2) NOT NULL DEFAULT 0.00,
  `visitantes` int(11) NOT NULL DEFAULT 0,
  `manutencao` tinyint(1) NOT NULL DEFAULT 0,
  `taxa_cash_in_padrao` decimal(10,2) NOT NULL DEFAULT 4.00,
  `taxa_cash_out_padrao` decimal(10,2) NOT NULL DEFAULT 4.00,
  `taxa_fixa_padrao` decimal(10,2) NOT NULL DEFAULT 5.00,
  `sms_url_cadastro_pendente` varchar(255) DEFAULT NULL,
  `sms_url_cadastro_ativo` varchar(255) DEFAULT NULL,
  `sms_url_notificacao_user` varchar(255) DEFAULT NULL,
  `sms_url_redefinir_senha` varchar(255) DEFAULT NULL,
  `sms_url_autenticar_admin` varchar(255) DEFAULT NULL,
  `taxa_pix_valor_real_cash_in_padrao` decimal(10,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `limite_saque_automatico` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `saque_automatico` tinyint(1) NOT NULL DEFAULT 0,
  `taxa_flexivel_valor_minimo` decimal(10,2) NOT NULL DEFAULT 15.00,
  `taxa_flexivel_fixa_baixo` decimal(10,2) NOT NULL DEFAULT 1.00,
  `taxa_flexivel_percentual_alto` decimal(10,2) NOT NULL DEFAULT 4.00,
  `taxa_flexivel_ativa` tinyint(1) NOT NULL DEFAULT 0,
  `taxa_saque_api_padrao` decimal(10,2) NOT NULL DEFAULT 5.00,
  `taxa_saque_cripto_padrao` decimal(10,2) NOT NULL DEFAULT 1.00,
  `global_ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'IPs globais autorizados para todos os usuários (interface web)' CHECK (json_valid(`global_ips`)),
  `taxa_por_fora_api` tinyint(1) NOT NULL DEFAULT 1,
  `taxa_fixa_pix` decimal(10,2) NOT NULL DEFAULT 0.00,
  `relatorio_entradas_mostrar_meio` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_transacao_id` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_valor` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_valor_liquido` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_nome` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_documento` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_status` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_data` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_entradas_mostrar_taxa` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_transacao_id` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_valor` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_nome` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_chave_pix` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_tipo_chave` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_status` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_data` tinyint(1) NOT NULL DEFAULT 1,
  `relatorio_saidas_mostrar_taxa` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app`
--

LOCK TABLES `app` WRITE;
/*!40000 ALTER TABLE `app` DISABLE KEYS */;
/*!40000 ALTER TABLE `app` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asaas`
--

DROP TABLE IF EXISTS `asaas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asaas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `api_key` varchar(255) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `webhook_token` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asaas`
--

LOCK TABLES `asaas` WRITE;
/*!40000 ALTER TABLE `asaas` DISABLE KEYS */;
/*!40000 ALTER TABLE `asaas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bspay`
--

DROP TABLE IF EXISTS `bspay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bspay` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bspay`
--

LOCK TABLES `bspay` WRITE;
/*!40000 ALTER TABLE `bspay` DISABLE KEYS */;
/*!40000 ALTER TABLE `bspay` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashtimes`
--

DROP TABLE IF EXISTS `cashtimes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashtimes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/',
  `url_cash_in` varchar(255) NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/transactions',
  `url_cash_out` varchar(255) NOT NULL DEFAULT 'https://api.cashtime.com.br/v1/request/withdraw',
  `url_webhook_deposit` varchar(255) DEFAULT NULL,
  `url_webhook_payment` varchar(255) DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT 5.00,
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashtimes`
--

LOCK TABLES `cashtimes` WRITE;
/*!40000 ALTER TABLE `cashtimes` DISABLE KEYS */;
/*!40000 ALTER TABLE `cashtimes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `checkout_build`
--

DROP TABLE IF EXISTS `checkout_build`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_build` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name_produto` varchar(255) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `referencia` varchar(255) DEFAULT NULL,
  `logo_produto` varchar(255) DEFAULT NULL,
  `obrigado_page` varchar(255) NOT NULL,
  `key_gateway` varchar(255) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `email` varchar(255) NOT NULL,
  `url_checkout` varchar(255) DEFAULT NULL,
  `banner_produto` varchar(255) DEFAULT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `methods` varchar(255) NOT NULL DEFAULT '["pix"]',
  `checkout_ads_utmfy` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checkout_build_user_id_foreign` (`user_id`),
  CONSTRAINT `checkout_build_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkout_build`
--

LOCK TABLES `checkout_build` WRITE;
/*!40000 ALTER TABLE `checkout_build` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkout_build` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `checkout_vendas`
--

DROP TABLE IF EXISTS `checkout_vendas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_vendas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `cep` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `checkout_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `idTransaction` varchar(255) DEFAULT NULL,
  `paymentCode` text DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checkout_vendas_checkout_id_foreign` (`checkout_id`),
  CONSTRAINT `checkout_vendas_checkout_id_foreign` FOREIGN KEY (`checkout_id`) REFERENCES `checkout_build` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkout_vendas`
--

LOCK TABLES `checkout_vendas` WRITE;
/*!40000 ALTER TABLE `checkout_vendas` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkout_vendas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `confirmar_deposito`
--

DROP TABLE IF EXISTS `confirmar_deposito`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `confirmar_deposito` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `externalreference` varchar(255) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `data` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `confirmar_deposito`
--

LOCK TABLES `confirmar_deposito` WRITE;
/*!40000 ALTER TABLE `confirmar_deposito` DISABLE KEYS */;
/*!40000 ALTER TABLE `confirmar_deposito` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `depositos_api`
--

DROP TABLE IF EXISTS `depositos_api`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `depositos_api` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `id_externo` varchar(255) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `cliente_nome` varchar(255) NOT NULL,
  `cliente_documento` varchar(255) NOT NULL,
  `cliente_email` varchar(255) NOT NULL,
  `cliente_telefone` varchar(255) NOT NULL,
  `data_real` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('aguardando','aprovado','rejeitado') NOT NULL DEFAULT 'aguardando',
  `qrcode` longtext NOT NULL,
  `pixcopiaecola` longtext NOT NULL,
  `idTransaction` varchar(255) NOT NULL,
  `callback_url` varchar(255) DEFAULT NULL,
  `adquirente_ref` varchar(255) DEFAULT NULL,
  `taxa_cash_in` decimal(10,2) NOT NULL,
  `deposito_liquido` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_adquirente` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_valor_fixo` decimal(10,2) NOT NULL,
  `executor_ordem` varchar(255) DEFAULT NULL,
  `descricao_transacao` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `depositos_api_user_id_foreign` (`user_id`),
  CONSTRAINT `depositos_api_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `depositos_api`
--

LOCK TABLES `depositos_api` WRITE;
/*!40000 ALTER TABLE `depositos_api` DISABLE KEYS */;
/*!40000 ALTER TABLE `depositos_api` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `efi`
--

DROP TABLE IF EXISTS `efi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `efi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `access_token` varchar(255) DEFAULT NULL,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `gateway_id` varchar(255) DEFAULT NULL,
  `cert` varchar(255) DEFAULT NULL,
  `taxa_pix_cash_in` decimal(10,2) NOT NULL DEFAULT 5.00,
  `taxa_pix_cash_out` decimal(10,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `chave_pix` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `efi`
--

LOCK TABLES `efi` WRITE;
/*!40000 ALTER TABLE `efi` DISABLE KEYS */;
/*!40000 ALTER TABLE `efi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logs_ip_cash_out`
--

DROP TABLE IF EXISTS `logs_ip_cash_out`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_ip_cash_out` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) NOT NULL,
  `data` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logs_ip_cash_out`
--

LOCK TABLES `logs_ip_cash_out` WRITE;
/*!40000 ALTER TABLE `logs_ip_cash_out` DISABLE KEYS */;
/*!40000 ALTER TABLE `logs_ip_cash_out` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2025_01_20_000002_add_performance_indexes',1),(5,'2025_01_20_000003_add_critical_performance_indexes',1),(6,'2025_01_27_000001_add_ips_saque_permitidos_to_users_table',1),(7,'2025_02_07_162003_create_adquirentes_table',1),(8,'2025_02_07_162502_create_ad_primepags_table',1),(9,'2025_02_07_162731_create_apps_table',1),(10,'2025_02_07_163016_create_checkout_builds_table',1),(11,'2025_02_07_163427_create_pix_depositos_table',1),(12,'2025_02_07_163748_create_retiradas_table',1),(13,'2025_02_07_164039_create_confirmar_depositos_table',1),(14,'2025_02_07_164203_create_log_ip_cash_outs_table',1),(15,'2025_02_07_164233_create_segurancas_table',1),(16,'2025_02_07_164538_create_deposito_apis_table',1),(17,'2025_02_07_165353_create_solicitacoes_table',1),(18,'2025_02_07_165848_create_solicitacoes_cash_outs_table',1),(19,'2025_02_07_170024_create_transactions_table',1),(20,'2025_02_07_170119_create_users_keys_table',1),(21,'2025_02_07_173912_create_personal_access_tokens_table',1),(22,'2025_02_08_134847_add_username_in_table_users',1),(23,'2025_02_09_205605_add_permission_table_users',1),(24,'2025_02_09_230523_add_status_table_confirmar_deposito',1),(25,'2025_02_10_210736_create_pedidos_table',1),(26,'2025_02_10_214347_add_idtransaction_and_paymentcode_table_checkout_vendas',1),(27,'2025_02_10_224220_add_status_table_checkout_vendas',1),(28,'2025_02_11_133506_add_limite_saque_automatico',1),(29,'2025_02_12_002526_add_app_token_in_table_users',1),(30,'2025_02_26_122015_add_ref_in_table_users',1),(31,'2025_04_28_120846_alter_keys_in_users',1),(32,'2025_04_28_175324_create_cashtimes_table',1),(33,'2025_05_19_072223_create_nivels_table',1),(34,'2025_07_06_211621_create_mercadopagos_table',1),(35,'2025_07_06_214618_add_filds',1),(36,'2025_07_06_224045_create_efis_table',1),(37,'2025_07_06_232648_add_filds',1),(38,'2025_07_09_171154_create_pagarmes_table',1),(39,'2025_07_10_110411_add_filds_taxas_in_users',1),(40,'2025_07_13_155513_add_filds_billet_in_solicitacoes',1),(41,'2025_07_13_160659_add_filds_billet_in_solicitacoes',1),(42,'2025_07_13_173219_add_field_methods_in_checkout_build',1),(43,'2025_07_14_160239_add_field_days_availability_in_table_solicitacoes',1),(44,'2025_07_23_165001_add_field_utmfy_in_table_checkout_build',1),(45,'2025_07_23_172358_add_field_utmfy_in_table_users',1),(46,'2025_08_17_114158_create_xgates_table',1),(47,'2025_08_20_151743_create_witetecs_table',1),(48,'2025_09_06_161421_add_pixup_to_adquirentes_table',1),(49,'2025_09_06_162043_create_pixups_table',1),(50,'2025_09_07_100714_add_preferred_adquirente_to_users_table',1),(51,'2025_09_07_101558_create_user_adquirente_configs_table',1),(52,'2025_09_07_101702_add_index_to_adquirentes_referencia',1),(53,'2025_09_07_131427_add_is_default_to_adquirentes_table',1),(54,'2025_09_09_110243_add_saque_automatico_to_app_table',1),(55,'2025_09_09_140956_create_woovi_table',1),(56,'2025_09_09_182248_add_woovi_identifier_to_solicitacoes_table',1),(57,'2025_09_10_165522_create_split_payments_table',1),(58,'2025_09_10_180906_add_pin_to_users_table',1),(59,'2025_09_10_181353_modify_pin_column_in_users_table',1),(60,'2025_09_10_195446_create_bspay_table',1),(61,'2025_09_10_201339_add_flexible_tax_fields_to_app_table',1),(62,'2025_09_10_203045_remove_tax_fields_from_adquirentes_tables',1),(63,'2025_09_11_170938_add_allowed_domains_to_apps_table',1),(64,'2025_09_12_141703_add_taxa_flexivel_fields_to_users_table',1),(65,'2025_09_12_144320_add_saque_api_cripto_tax_fields_to_app_table',1),(66,'2025_09_12_144759_add_saque_api_cripto_tax_fields_to_users_table',1),(67,'2025_09_12_145015_add_taxas_personalizadas_ativas_to_users_table',1),(68,'2025_09_12_160415_add_global_ips_to_app_table',1),(69,'2025_09_12_164821_add_2fa_fields_to_users_table',1),(70,'2025_09_14_020442_create_asaas_table',1),(71,'2025_09_14_193939_create_syscoop_table',1),(72,'2025_09_14_194225_add_syscoop_to_adquirentes_table',1),(73,'2025_09_15_125124_add_executor_ordem_to_solicitacoes_cash_out_table',1),(74,'2025_09_17_150032_fix_taxa_fixa_padrao_cash_out_decimal_precision',1),(75,'2025_09_18_174828_add_taxa_por_fora_to_app_table',1),(76,'2025_09_18_181006_add_taxa_fields_to_bspay_table',1),(77,'2025_09_19_104324_remove_taxas_personalizadas_from_users_table',1),(78,'2025_09_19_133140_add_taxa_fixa_pix_to_app_table',1),(79,'2025_09_19_135436_add_commission_fields_to_transactions_table',1),(80,'2025_09_19_145543_add_taxas_personalizadas_to_users_table',1),(81,'2025_09_19_151032_add_relatorio_personalizacao_to_apps_table',1),(82,'2025_09_29_123607_create_primepay7_table',1),(83,'2025_09_29_123821_add_primepay7_to_adquirentes_table',1),(84,'2025_09_29_124913_add_keys_to_primepay7_table',1),(85,'2025_09_29_125643_remove_client_fields_from_primepay7_table',1),(86,'2025_09_29_142437_add_contrato_social_to_users_table',1),(87,'2025_10_01_015617_create_xdpag_table',1),(88,'2025_10_01_015843_add_xdpag_to_adquirentes_table',1),(89,'2025_10_01_020425_update_xdpag_table_remove_unnecessary_fields',1),(90,'2025_10_01_020609_update_xdpag_table_add_basic_fields',1),(91,'2025_10_04_115912_create_split_internos_table',1),(92,'2025_10_04_124957_add_affiliate_fields_to_users_table',1),(93,'2025_10_06_161042_remove_app_id_from_woovi_table',1),(94,'2025_10_06_161247_remove_sandbox_from_primepay7_table',1),(95,'2025_10_06_183250_add_payment_types_to_adquirentes_table',1),(96,'2025_10_06_183258_add_specific_adquirente_fields_to_users_table',1),(97,'2025_10_07_004427_create_push_tokens_table',1),(98,'2025_10_07_004428_create_notifications_table',1),(99,'2025_10_08_130224_add_separate_adquirente_fields_to_adquirentes_and_users_tables',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `niveis`
--

DROP TABLE IF EXISTS `niveis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `niveis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) DEFAULT NULL,
  `cor` varchar(255) DEFAULT NULL,
  `icone` varchar(255) DEFAULT NULL,
  `minimo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `maximo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `niveis`
--

LOCK TABLES `niveis` WRITE;
/*!40000 ALTER TABLE `niveis` DISABLE KEYS */;
/*!40000 ALTER TABLE `niveis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'transaction',
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `push_sent` tinyint(1) NOT NULL DEFAULT 0,
  `local_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `notifications_user_id_type_index` (`user_id`,`type`),
  KEY `notifications_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pagarme`
--

DROP TABLE IF EXISTS `pagarme`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagarme` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(255) DEFAULT NULL,
  `secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT 'https://api.pagar.me',
  `url_cash_in` varchar(255) DEFAULT 'https://api.pagar.me/core/v5/orders',
  `url_cash_out` varchar(255) DEFAULT 'https://api.pagar.me/core/v5/transaction',
  `taxa_pix_cash_in` decimal(8,2) DEFAULT NULL,
  `taxa_pix_cash_out` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pagarme`
--

LOCK TABLES `pagarme` WRITE;
/*!40000 ALTER TABLE `pagarme` DISABLE KEYS */;
/*!40000 ALTER TABLE `pagarme` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pix_deposito`
--

DROP TABLE IF EXISTS `pix_deposito`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pix_deposito` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `value` decimal(10,2) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `data` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pix_deposito_user_id_foreign` (`user_id`),
  CONSTRAINT `pix_deposito_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pix_deposito`
--

LOCK TABLES `pix_deposito` WRITE;
/*!40000 ALTER TABLE `pix_deposito` DISABLE KEYS */;
/*!40000 ALTER TABLE `pix_deposito` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pixup`
--

DROP TABLE IF EXISTS `pixup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pixup` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.pixupbr.com/v2/',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pixup`
--

LOCK TABLES `pixup` WRITE;
/*!40000 ALTER TABLE `pixup` DISABLE KEYS */;
/*!40000 ALTER TABLE `pixup` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `primepay7`
--

DROP TABLE IF EXISTS `primepay7`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `primepay7` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.primepay7.com',
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `private_key` varchar(255) DEFAULT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `withdrawal_key` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `primepay7`
--

LOCK TABLES `primepay7` WRITE;
/*!40000 ALTER TABLE `primepay7` DISABLE KEYS */;
/*!40000 ALTER TABLE `primepay7` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `push_tokens`
--

DROP TABLE IF EXISTS `push_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `token` varchar(500) NOT NULL,
  `platform` varchar(255) NOT NULL DEFAULT 'expo',
  `device_id` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_tokens_token_unique` (`token`),
  KEY `push_tokens_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `push_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `push_tokens`
--

LOCK TABLES `push_tokens` WRITE;
/*!40000 ALTER TABLE `push_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `retiradas`
--

DROP TABLE IF EXISTS `retiradas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `retiradas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `referencia` varchar(255) DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `valor_liquido` decimal(10,2) NOT NULL,
  `tipo_chave` varchar(255) NOT NULL,
  `chave` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `data_solicitacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_pagamento` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `taxa_cash_out` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `retiradas_user_id_foreign` (`user_id`),
  CONSTRAINT `retiradas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `retiradas`
--

LOCK TABLES `retiradas` WRITE;
/*!40000 ALTER TABLE `retiradas` DISABLE KEYS */;
/*!40000 ALTER TABLE `retiradas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seguranca`
--

DROP TABLE IF EXISTS `seguranca`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seguranca` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `keyseguranca` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seguranca`
--

LOCK TABLES `seguranca` WRITE;
/*!40000 ALTER TABLE `seguranca` DISABLE KEYS */;
/*!40000 ALTER TABLE `seguranca` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `solicitacoes`
--

DROP TABLE IF EXISTS `solicitacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `solicitacoes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `externalreference` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `client_document` varchar(255) NOT NULL,
  `client_email` varchar(255) NOT NULL,
  `date` datetime NOT NULL,
  `status` varchar(255) NOT NULL,
  `idTransaction` varchar(255) NOT NULL,
  `deposito_liquido` decimal(10,2) NOT NULL,
  `qrcode_pix` varchar(500) NOT NULL,
  `paymentcode` varchar(500) NOT NULL,
  `paymentCodeBase64` text NOT NULL,
  `adquirente_ref` varchar(255) NOT NULL,
  `taxa_cash_in` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_adquirente` decimal(10,2) NOT NULL,
  `taxa_pix_cash_in_valor_fixo` decimal(10,2) NOT NULL,
  `client_telefone` varchar(15) NOT NULL,
  `executor_ordem` varchar(255) NOT NULL,
  `descricao_transacao` varchar(255) NOT NULL,
  `callback` varchar(255) DEFAULT NULL,
  `split_email` varchar(255) DEFAULT NULL,
  `split_percentage` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `method` enum('pix','billet','card') NOT NULL DEFAULT 'pix',
  `expire_at` varchar(255) DEFAULT NULL,
  `billet_download` varchar(255) DEFAULT NULL,
  `banking_billet` longtext DEFAULT NULL,
  `days_availability` int(11) DEFAULT NULL,
  `woovi_identifier` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `solicitacoes_idtransaction_unique` (`idTransaction`),
  KEY `solicitacoes_user_id_foreign` (`user_id`),
  CONSTRAINT `solicitacoes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `solicitacoes`
--

LOCK TABLES `solicitacoes` WRITE;
/*!40000 ALTER TABLE `solicitacoes` DISABLE KEYS */;
/*!40000 ALTER TABLE `solicitacoes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `solicitacoes_cash_out`
--

DROP TABLE IF EXISTS `solicitacoes_cash_out`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `solicitacoes_cash_out` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `externalreference` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `beneficiaryname` varchar(255) NOT NULL,
  `beneficiarydocument` varchar(255) NOT NULL,
  `pix` varchar(255) NOT NULL,
  `pixkey` varchar(255) NOT NULL,
  `date` datetime NOT NULL,
  `status` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `idTransaction` varchar(255) DEFAULT NULL,
  `taxa_cash_out` decimal(10,2) NOT NULL,
  `cash_out_liquido` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `executor_ordem` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `solicitacoes_cash_out_idtransaction_unique` (`idTransaction`),
  KEY `solicitacoes_cash_out_user_id_foreign` (`user_id`),
  CONSTRAINT `solicitacoes_cash_out_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `solicitacoes_cash_out`
--

LOCK TABLES `solicitacoes_cash_out` WRITE;
/*!40000 ALTER TABLE `solicitacoes_cash_out` DISABLE KEYS */;
/*!40000 ALTER TABLE `solicitacoes_cash_out` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `split_internos`
--

DROP TABLE IF EXISTS `split_internos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `split_internos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_beneficiario_id` bigint(20) unsigned NOT NULL,
  `usuario_pagador_id` bigint(20) unsigned NOT NULL,
  `porcentagem_split` decimal(5,2) NOT NULL,
  `tipo_taxa` enum('deposito','saque_pix') NOT NULL DEFAULT 'deposito',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_por_admin_id` bigint(20) unsigned NOT NULL,
  `data_inicio` timestamp NULL DEFAULT NULL,
  `data_fim` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_split_config` (`usuario_pagador_id`,`usuario_beneficiario_id`,`tipo_taxa`),
  KEY `split_internos_usuario_pagador_id_tipo_taxa_ativo_index` (`usuario_pagador_id`,`tipo_taxa`,`ativo`),
  KEY `split_internos_usuario_beneficiario_id_ativo_index` (`usuario_beneficiario_id`,`ativo`),
  KEY `split_internos_usuario_beneficiario_id_index` (`usuario_beneficiario_id`),
  KEY `split_internos_usuario_pagador_id_index` (`usuario_pagador_id`),
  KEY `split_internos_criado_por_admin_id_index` (`criado_por_admin_id`),
  CONSTRAINT `split_internos_criado_por_admin_id_foreign` FOREIGN KEY (`criado_por_admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `split_internos_usuario_beneficiario_id_foreign` FOREIGN KEY (`usuario_beneficiario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `split_internos_usuario_pagador_id_foreign` FOREIGN KEY (`usuario_pagador_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `split_internos`
--

LOCK TABLES `split_internos` WRITE;
/*!40000 ALTER TABLE `split_internos` DISABLE KEYS */;
/*!40000 ALTER TABLE `split_internos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `split_internos_executados`
--

DROP TABLE IF EXISTS `split_internos_executados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `split_internos_executados` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `split_internos_id` bigint(20) unsigned NOT NULL,
  `solicitacao_id` bigint(20) unsigned DEFAULT NULL,
  `usuario_pagador_id` bigint(20) unsigned NOT NULL,
  `usuario_beneficiario_id` bigint(20) unsigned NOT NULL,
  `valor_taxa_original` decimal(12,2) NOT NULL,
  `valor_split` decimal(12,2) NOT NULL,
  `porcentagem_aplicada` decimal(5,2) NOT NULL,
  `status` enum('pendente','processado','falhado') NOT NULL DEFAULT 'pendente',
  `processado_em` timestamp NULL DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `split_internos_executados_split_internos_id_foreign` (`split_internos_id`),
  KEY `split_internos_executados_solicitacao_id_foreign` (`solicitacao_id`),
  KEY `idx_executados_benef_status_data` (`usuario_beneficiario_id`,`status`,`created_at`),
  KEY `idx_executados_pag_status_data` (`usuario_pagador_id`,`status`,`created_at`),
  CONSTRAINT `split_internos_executados_solicitacao_id_foreign` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `split_internos_executados_split_internos_id_foreign` FOREIGN KEY (`split_internos_id`) REFERENCES `split_internos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `split_internos_executados_usuario_beneficiario_id_foreign` FOREIGN KEY (`usuario_beneficiario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `split_internos_executados_usuario_pagador_id_foreign` FOREIGN KEY (`usuario_pagador_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `split_internos_executados`
--

LOCK TABLES `split_internos_executados` WRITE;
/*!40000 ALTER TABLE `split_internos_executados` DISABLE KEYS */;
/*!40000 ALTER TABLE `split_internos_executados` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `split_payments`
--

DROP TABLE IF EXISTS `split_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `split_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `solicitacao_id` bigint(20) unsigned NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `split_email` varchar(255) NOT NULL,
  `split_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `split_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `split_status` enum('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `split_type` enum('percentage','fixed','partner','affiliate') NOT NULL DEFAULT 'percentage',
  `description` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `split_payments_solicitacao_id_index` (`solicitacao_id`),
  KEY `split_payments_user_id_index` (`user_id`),
  KEY `split_payments_split_status_index` (`split_status`),
  KEY `split_payments_split_type_index` (`split_type`),
  KEY `split_payments_created_at_index` (`created_at`),
  CONSTRAINT `split_payments_solicitacao_id_foreign` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `split_payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `split_payments`
--

LOCK TABLES `split_payments` WRITE;
/*!40000 ALTER TABLE `split_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `split_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `syscoop`
--

DROP TABLE IF EXISTS `syscoop`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `syscoop` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `syscoop`
--

LOCK TABLES `syscoop` WRITE;
/*!40000 ALTER TABLE `syscoop` DISABLE KEYS */;
/*!40000 ALTER TABLE `syscoop` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `price` decimal(20,0) NOT NULL,
  `status` tinyint(4) NOT NULL,
  `reference` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gerente_id` varchar(255) DEFAULT NULL,
  `solicitacao_id` varchar(255) DEFAULT NULL,
  `comission_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `transaction_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `comission_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `transactions_user_id_foreign` (`user_id`),
  CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_adquirente_configs`
--

DROP TABLE IF EXISTS `user_adquirente_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_adquirente_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_adquirente_configs`
--

LOCK TABLES `user_adquirente_configs` WRITE;
/*!40000 ALTER TABLE `user_adquirente_configs` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_adquirente_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `cpf_cnpj` varchar(255) DEFAULT NULL,
  `data_nascimento` varchar(255) DEFAULT NULL,
  `telefone` varchar(255) DEFAULT NULL,
  `saldo` double NOT NULL DEFAULT 0,
  `total_transacoes` int(11) NOT NULL DEFAULT 0,
  `avatar` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_user` varchar(255) DEFAULT NULL,
  `transacoes_aproved` int(11) NOT NULL DEFAULT 0,
  `transacoes_recused` int(11) NOT NULL DEFAULT 0,
  `valor_sacado` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_saque_pendente` decimal(10,2) NOT NULL DEFAULT 0.00,
  `token` char(36) DEFAULT NULL,
  `banido` tinyint(1) NOT NULL DEFAULT 0,
  `cliente_id` varchar(255) NOT NULL,
  `taxa_percentual` decimal(10,2) NOT NULL DEFAULT 5.00,
  `volume_transacional` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_pago_taxa` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_id` varchar(255) DEFAULT NULL,
  `cep` varchar(255) DEFAULT NULL,
  `rua` varchar(255) DEFAULT NULL,
  `estado` varchar(255) DEFAULT NULL,
  `cidade` varchar(255) DEFAULT NULL,
  `bairro` varchar(255) DEFAULT NULL,
  `numero_residencia` varchar(255) DEFAULT NULL,
  `complemento` varchar(255) DEFAULT NULL,
  `foto_rg_frente` varchar(255) DEFAULT NULL,
  `foto_rg_verso` varchar(255) DEFAULT NULL,
  `selfie_rg` varchar(255) DEFAULT NULL,
  `media_faturamento` varchar(255) DEFAULT NULL,
  `indicador_ref` varchar(255) DEFAULT NULL,
  `whitelisted_ip` varchar(255) DEFAULT NULL,
  `ips_saque_permitidos` text DEFAULT NULL,
  `pushcut_pixpago` varchar(255) DEFAULT NULL,
  `twofa_secret` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `permission` int(11) NOT NULL DEFAULT 1,
  `app_token` longtext DEFAULT NULL,
  `integracao_utmfy` varchar(255) DEFAULT NULL,
  `pin` varchar(255) DEFAULT NULL,
  `pin_active` tinyint(1) NOT NULL DEFAULT 0,
  `pin_created_at` timestamp NULL DEFAULT NULL,
  `twofa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `twofa_enabled_at` timestamp NULL DEFAULT NULL,
  `taxas_personalizadas_ativas` tinyint(1) NOT NULL DEFAULT 0,
  `taxa_percentual_deposito` decimal(5,2) DEFAULT NULL,
  `taxa_fixa_deposito` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_minimo_deposito` decimal(10,2) DEFAULT NULL,
  `taxa_percentual_pix` decimal(5,2) DEFAULT NULL,
  `taxa_minima_pix` decimal(10,2) DEFAULT NULL,
  `taxa_fixa_pix` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_minimo_saque` decimal(10,2) DEFAULT NULL,
  `limite_mensal_pf` decimal(12,2) DEFAULT NULL,
  `taxa_saque_api` decimal(5,2) DEFAULT NULL,
  `taxa_saque_crypto` decimal(5,2) DEFAULT NULL,
  `sistema_flexivel_ativo` tinyint(1) NOT NULL DEFAULT 0,
  `valor_minimo_flexivel` decimal(10,2) DEFAULT NULL,
  `taxa_fixa_baixos` decimal(10,2) DEFAULT NULL,
  `taxa_percentual_altos` decimal(5,2) DEFAULT NULL,
  `observacoes_taxas` text DEFAULT NULL,
  `contrato_social` varchar(255) DEFAULT NULL,
  `affiliate_id` bigint(20) unsigned DEFAULT NULL,
  `affiliate_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_affiliate` tinyint(1) NOT NULL DEFAULT 0,
  `affiliate_code` varchar(50) DEFAULT NULL,
  `affiliate_link` varchar(255) DEFAULT NULL,
  `referral_code` varchar(50) DEFAULT NULL,
  `preferred_adquirente_card_billet` varchar(255) DEFAULT NULL,
  `adquirente_card_billet_override` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_user_id_unique` (`user_id`),
  UNIQUE KEY `users_affiliate_code_unique` (`affiliate_code`),
  KEY `users_affiliate_id_foreign` (`affiliate_id`),
  KEY `users_is_affiliate_affiliate_percentage_index` (`is_affiliate`,`affiliate_percentage`),
  CONSTRAINT `users_affiliate_id_foreign` FOREIGN KEY (`affiliate_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users_key`
--

DROP TABLE IF EXISTS `users_key`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_key` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) DEFAULT NULL,
  `secret` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `users_key_user_id_foreign` (`user_id`),
  CONSTRAINT `users_key_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users_key`
--

LOCK TABLES `users_key` WRITE;
/*!40000 ALTER TABLE `users_key` DISABLE KEYS */;
/*!40000 ALTER TABLE `users_key` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `witetec`
--

DROP TABLE IF EXISTS `witetec`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `witetec` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.witetec.net',
  `api_token` varchar(255) DEFAULT NULL,
  `tx_billet_fixed` decimal(10,2) NOT NULL DEFAULT 5.00,
  `tx_billet_percent` decimal(10,2) NOT NULL DEFAULT 5.00,
  `tx_card_fixed` decimal(10,2) NOT NULL DEFAULT 5.00,
  `tx_card_percent` decimal(10,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `witetec`
--

LOCK TABLES `witetec` WRITE;
/*!40000 ALTER TABLE `witetec` DISABLE KEYS */;
/*!40000 ALTER TABLE `witetec` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `woovi`
--

DROP TABLE IF EXISTS `woovi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `woovi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `api_key` varchar(255) NOT NULL,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `url` varchar(255) NOT NULL DEFAULT 'https://api.woovi.com',
  `sandbox` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `woovi`
--

LOCK TABLES `woovi` WRITE;
/*!40000 ALTER TABLE `woovi` DISABLE KEYS */;
/*!40000 ALTER TABLE `woovi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `xdpag`
--

DROP TABLE IF EXISTS `xdpag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `xdpag` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `taxa_pix_cash_in` decimal(5,2) NOT NULL DEFAULT 0.00,
  `taxa_pix_cash_out` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `xdpag`
--

LOCK TABLES `xdpag` WRITE;
/*!40000 ALTER TABLE `xdpag` DISABLE KEYS */;
/*!40000 ALTER TABLE `xdpag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `xgate`
--

DROP TABLE IF EXISTS `xgate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `xgate` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `xgate`
--

LOCK TABLES `xgate` WRITE;
/*!40000 ALTER TABLE `xgate` DISABLE KEYS */;
/*!40000 ALTER TABLE `xgate` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-27  9:49:22
