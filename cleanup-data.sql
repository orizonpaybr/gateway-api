-- Script para limpar dados sensíveis de produção

-- Desabilitar verificação de foreign keys temporariamente
SET FOREIGN_KEY_CHECKS = 0;

-- Limpar transações reais
TRUNCATE TABLE transactions;
TRUNCATE TABLE solicitacoes;
TRUNCATE TABLE solicitacoes_cash_out;
TRUNCATE TABLE pix_deposito;
TRUNCATE TABLE retiradas;
TRUNCATE TABLE confirmar_deposito;
TRUNCATE TABLE checkout_vendas;

-- Limpar logs
TRUNCATE TABLE logs_ip_cash_out;

-- Limpar chaves de API sensíveis (manter estrutura)
UPDATE ad_mercadopago SET access_token = NULL WHERE access_token IS NOT NULL;
UPDATE efi SET client_id = NULL, client_secret = NULL WHERE client_id IS NOT NULL;
UPDATE pagarme SET api_key = NULL WHERE api_key IS NOT NULL;
UPDATE pixup SET client_id = NULL, client_secret = NULL WHERE client_id IS NOT NULL;
UPDATE bspay SET client_id = NULL, client_secret = NULL, token = NULL WHERE client_id IS NOT NULL;
UPDATE woovi SET app_id = NULL WHERE app_id IS NOT NULL;
UPDATE asaas SET api_key = NULL WHERE api_key IS NOT NULL;
UPDATE syscoop SET token_api = NULL WHERE token_api IS NOT NULL;
UPDATE primepay7 SET token = NULL WHERE token IS NOT NULL;
UPDATE xdpag SET token = NULL WHERE token IS NOT NULL;

-- Manter apenas usuários de teste
DELETE FROM users WHERE email NOT LIKE '%@test.com%' AND email NOT LIKE '%@exemplo.com%';

-- Resetar senhas dos usuários de teste para uma senha padrão
-- Senha: teste123 (hash bcrypt)
UPDATE users SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- Reabilitar verificação de foreign keys
SET FOREIGN_KEY_CHECKS = 1;

