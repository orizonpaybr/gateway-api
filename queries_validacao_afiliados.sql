-- ============================================
-- QUERIES DE VALIDAÇÃO - SISTEMA DE AFILIADOS
-- ============================================

-- 1. Verificar estrutura da tabela de comissões
DESCRIBE affiliate_commissions;

-- 2. Verificar vínculo pai-filho
SELECT 
    u1.id as pai_id,
    u1.username as pai_username,
    u1.affiliate_code as pai_code,
    u1.affiliate_link as pai_link,
    u2.id as filho_id,
    u2.username as filho_username,
    u2.affiliate_id as filho_affiliate_id,
    u2.affiliate_code as filho_code
FROM users u1
LEFT JOIN users u2 ON u2.affiliate_id = u1.id
WHERE u1.username = 'pai_afiliado'
ORDER BY u2.created_at DESC;

-- 3. Verificar todas as comissões registradas
SELECT 
    ac.id,
    ac.user_id as filho_user_id,
    u_filho.username as filho_username,
    ac.affiliate_id as pai_id,
    u_pai.username as pai_username,
    ac.transaction_type,
    ac.commission_value,
    ac.transaction_amount,
    ac.status,
    ac.solicitacao_id,
    ac.solicitacao_cash_out_id,
    ac.created_at
FROM affiliate_commissions ac
JOIN users u_filho ON u_filho.user_id = ac.user_id
JOIN users u_pai ON u_pai.id = ac.affiliate_id
ORDER BY ac.created_at DESC;

-- 4. Resumo de comissões por pai afiliado
SELECT 
    u_pai.id as pai_id,
    u_pai.username as pai_username,
    COUNT(ac.id) as total_comissoes,
    SUM(CASE WHEN ac.transaction_type = 'cash_in' THEN 1 ELSE 0 END) as comissoes_cash_in,
    SUM(CASE WHEN ac.transaction_type = 'cash_out' THEN 1 ELSE 0 END) as comissoes_cash_out,
    SUM(ac.commission_value) as total_recebido,
    SUM(CASE WHEN ac.status = 'paid' THEN ac.commission_value ELSE 0 END) as total_pago,
    SUM(CASE WHEN ac.status = 'pending' THEN ac.commission_value ELSE 0 END) as total_pendente
FROM users u_pai
LEFT JOIN affiliate_commissions ac ON ac.affiliate_id = u_pai.id
WHERE u_pai.affiliate_code IS NOT NULL
GROUP BY u_pai.id, u_pai.username
ORDER BY total_recebido DESC;

-- 5. Verificar transações de depósito do filho
SELECT 
    s.id,
    s.user_id,
    u.username,
    s.idTransaction,
    s.amount as valor_bruto,
    s.deposito_liquido,
    s.taxa_cash_in,
    s.status,
    s.created_at,
    -- Verificar se tem comissão processada
    (SELECT COUNT(*) 
     FROM affiliate_commissions ac 
     WHERE ac.solicitacao_id = s.id 
     AND ac.transaction_type = 'cash_in') as comissao_processada
FROM solicitacoes s
JOIN users u ON u.user_id = s.user_id
WHERE s.user_id = 'filho_afiliado'
ORDER BY s.created_at DESC;

-- 6. Verificar transações de saque do filho
SELECT 
    sc.id,
    sc.user_id,
    u.username,
    sc.idTransaction,
    sc.amount as valor_solicitado,
    sc.taxa_cash_out,
    sc.cash_out_liquido,
    sc.status,
    sc.created_at,
    -- Verificar se tem comissão processada
    (SELECT COUNT(*) 
     FROM affiliate_commissions ac 
     WHERE ac.solicitacao_cash_out_id = sc.id 
     AND ac.transaction_type = 'cash_out') as comissao_processada
FROM solicitacoes_cash_out sc
JOIN users u ON u.user_id = sc.user_id
WHERE sc.user_id = 'filho_afiliado'
ORDER BY sc.created_at DESC;

-- 7. Verificar saldos dos usuários
SELECT 
    u.id,
    u.username,
    u.affiliate_id,
    u.affiliate_code,
    u.saldo,
    -- Comissões recebidas (como pai)
    COALESCE((
        SELECT SUM(ac.commission_value) 
        FROM affiliate_commissions ac 
        WHERE ac.affiliate_id = u.id 
        AND ac.status = 'paid'
    ), 0) as total_comissoes_recebidas,
    -- Comissões pagas (como filho)
    COALESCE((
        SELECT SUM(ac.commission_value) 
        FROM affiliate_commissions ac 
        WHERE ac.user_id = u.user_id 
        AND ac.status = 'paid'
    ), 0) as total_comissoes_pagas
FROM users u
WHERE u.username IN ('pai_afiliado', 'filho_afiliado')
ORDER BY u.username;

-- 8. Verificar se há comissões duplicadas (idempotência)
SELECT 
    ac.solicitacao_id,
    ac.solicitacao_cash_out_id,
    ac.user_id,
    ac.affiliate_id,
    COUNT(*) as quantidade
FROM affiliate_commissions ac
WHERE (ac.solicitacao_id IS NOT NULL OR ac.solicitacao_cash_out_id IS NOT NULL)
GROUP BY ac.solicitacao_id, ac.solicitacao_cash_out_id, ac.user_id, ac.affiliate_id
HAVING COUNT(*) > 1;

-- 9. Verificar hierarquia (confirmar que não há cascata)
-- O pai do filho NÃO deve ganhar dos netos
SELECT 
    u_avo.id as avo_id,
    u_avo.username as avo_username,
    u_pai.id as pai_id,
    u_pai.username as pai_username,
    u_filho.id as filho_id,
    u_filho.username as filho_username,
    -- Verificar se o avô tem comissões do neto (NÃO DEVE TER)
    (SELECT COUNT(*) 
     FROM affiliate_commissions ac 
     WHERE ac.affiliate_id = u_avo.id 
     AND ac.user_id = u_filho.user_id) as avo_tem_comissao_do_neto
FROM users u_avo
JOIN users u_pai ON u_pai.affiliate_id = u_avo.id
JOIN users u_filho ON u_filho.affiliate_id = u_pai.id
WHERE u_avo.username = 'pai_afiliado';

-- 10. Estatísticas gerais do sistema de afiliados
SELECT 
    'Total de usuários com código de afiliado' as metrica,
    COUNT(*) as valor
FROM users
WHERE affiliate_code IS NOT NULL

UNION ALL

SELECT 
    'Total de vínculos pai-filho' as metrica,
    COUNT(*) as valor
FROM users
WHERE affiliate_id IS NOT NULL

UNION ALL

SELECT 
    'Total de comissões processadas' as metrica,
    COUNT(*) as valor
FROM affiliate_commissions
WHERE status = 'paid'

UNION ALL

SELECT 
    'Total de comissões pendentes' as metrica,
    COUNT(*) as valor
FROM affiliate_commissions
WHERE status = 'pending'

UNION ALL

SELECT 
    'Total de comissões de cash-in' as metrica,
    COUNT(*) as valor
FROM affiliate_commissions
WHERE transaction_type = 'cash_in' AND status = 'paid'

UNION ALL

SELECT 
    'Total de comissões de cash-out' as metrica,
    COUNT(*) as valor
FROM affiliate_commissions
WHERE transaction_type = 'cash_out' AND status = 'paid'

UNION ALL

SELECT 
    'Valor total de comissões pagas' as metrica,
    SUM(commission_value) as valor
FROM affiliate_commissions
WHERE status = 'paid';

-- 11. Limpar dados de teste (CUIDADO: Use apenas em ambiente de desenvolvimento!)
-- Descomente para limpar dados de teste
/*
DELETE FROM affiliate_commissions WHERE user_id IN (
    SELECT user_id FROM users WHERE username LIKE '%_afiliado'
);

UPDATE users SET affiliate_id = NULL WHERE username LIKE '%_afiliado';

UPDATE users SET affiliate_code = NULL, affiliate_link = NULL WHERE username LIKE '%_afiliado';
*/
