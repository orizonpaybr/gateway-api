-- =====================================================
-- Script de Configuração Pagar.me - Cartão de Crédito
-- =====================================================
-- Execute este script após rodar as migrations
-- IMPORTANTE: Substitua as credenciais abaixo pelas suas chaves reais

-- 1. Verificar se existe registro na tabela pagarme
SELECT * FROM pagarme LIMIT 1;

-- 2. Inserir ou Atualizar configuração Pagar.me
-- Este comando cria um registro se não existir, ou atualiza se já existir
INSERT INTO pagarme (
    id,
    secret,
    public_key,
    webhook_secret,
    environment,
    url,
    url_cash_in,
    url_cash_out,
    card_enabled,
    use_3ds,
    card_tx_percent,
    card_tx_fixed,
    card_days_availability,
    created_at,
    updated_at
) VALUES (
    1,  -- id fixo
    -- ============================================
    -- CONFIGURE SUAS CREDENCIAIS AQUI
    -- ============================================
    'sk_test_SUA_CHAVE_SECRETA_AQUI',      -- Substitua pela sua chave secreta
    'pk_test_SUA_CHAVE_PUBLICA_AQUI',     -- Substitua pela sua chave pública
    'whsec_SEU_WEBHOOK_SECRET_AQUI',      -- Substitua pelo seu webhook secret
    
    -- ============================================
    -- Configurações de Ambiente
    -- ============================================
    'sandbox',  -- Use 'sandbox' para testes, 'production' para produção
    'https://api.pagar.me/core/v5/',
    'https://api.pagar.me/core/v5/orders',
    'https://api.pagar.me/core/v5/transaction',
    
    -- ============================================
    -- Configurações de Cartão de Crédito
    -- ============================================
    1,          -- card_enabled: 1 = Habilitado, 0 = Desabilitado
    1,          -- use_3ds: 1 = Habilitar 3D Secure, 0 = Desabilitar
    
    -- ============================================
    -- Taxas de Cartão (ajuste conforme necessário)
    -- ============================================
    2.99,       -- card_tx_percent: Taxa percentual (ex: 2.99 = 2.99%)
    0.50,       -- card_tx_fixed: Taxa fixa em reais (ex: 0.50 = R$ 0,50)
    30,         -- card_days_availability: Dias para disponibilizar o valor
    
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    -- Se já existe registro com id = 1, atualiza apenas os campos abaixo
    secret = VALUES(secret),
    public_key = VALUES(public_key),
    webhook_secret = VALUES(webhook_secret),
    environment = VALUES(environment),
    url = VALUES(url),
    url_cash_in = VALUES(url_cash_in),
    url_cash_out = VALUES(url_cash_out),
    card_enabled = VALUES(card_enabled),
    use_3ds = VALUES(use_3ds),
    card_tx_percent = VALUES(card_tx_percent),
    card_tx_fixed = VALUES(card_tx_fixed),
    card_days_availability = VALUES(card_days_availability),
    updated_at = NOW();

-- 3. Verificar configuração salva
SELECT 
    id,
    CASE WHEN secret IS NOT NULL THEN 'Configurado' ELSE 'Não configurado' END as secret_status,
    CASE WHEN public_key IS NOT NULL THEN 'Configurado' ELSE 'Não configurado' END as public_key_status,
    CASE WHEN webhook_secret IS NOT NULL THEN 'Configurado' ELSE 'Não configurado' END as webhook_status,
    environment,
    card_enabled,
    use_3ds,
    card_tx_percent,
    card_tx_fixed,
    card_days_availability,
    created_at,
    updated_at
FROM pagarme
WHERE id = 1;

-- =====================================================
-- Consultas Úteis
-- =====================================================

-- Ver todas as transações de cartão
-- NOTA: Certifique-se de rodar a migration add_card_fields_to_solicitacoes_table antes
SELECT 
    id,
    user_id,
    idTransaction,
    charge_id,
    amount,
    deposito_liquido,
    taxa_cash_in,
    status,
    method,
    installments,
    created_at
FROM solicitacoes
WHERE method = 'card'
ORDER BY created_at DESC
LIMIT 10;

-- Ver cartões salvos dos usuários
SELECT 
    uc.id,
    uc.user_id,
    u.username,
    uc.card_id,
    uc.brand,
    CONCAT('**** **** **** ', uc.last_four_digits) as masked_number,
    uc.first_six_digits,
    uc.last_four_digits,
    uc.holder_name,
    CONCAT(LPAD(uc.exp_month, 2, '0'), '/', uc.exp_year) as expiration_date,
    uc.is_default,
    uc.status,
    uc.label,
    uc.created_at
FROM user_cards uc
JOIN users u ON uc.user_id = u.id
WHERE uc.deleted_at IS NULL
ORDER BY uc.created_at DESC;

-- Verificar se há transações pendentes
SELECT 
    COUNT(*) as total_pendentes,
    SUM(amount) as valor_total_pendente
FROM solicitacoes
WHERE method = 'card'
AND status IN ('WAITING_FOR_APPROVAL', 'PROCESSING');

-- =====================================================
-- RESET DE TESTES (Use com cuidado!)
-- =====================================================

-- Limpar cartões de teste (apenas para desenvolvimento)
-- DELETE FROM user_cards WHERE deleted_at IS NOT NULL;

-- Limpar transações de teste (apenas para desenvolvimento)
-- DELETE FROM solicitacoes WHERE method = 'card' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
