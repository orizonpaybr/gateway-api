-- Script MySQL para atualizar os níveis de gamificação
-- Execute este script no banco martinspay_app para atualizar os níveis

-- Atualizar níveis existentes
UPDATE `niveis` SET 
    `minimo` = 0.00, 
    `maximo` = 100000.00 
WHERE `id` = 1 AND `nome` = 'Bronze';

UPDATE `niveis` SET 
    `minimo` = 100001.00, 
    `maximo` = 500000.00 
WHERE `id` = 2 AND `nome` = 'Prata';

UPDATE `niveis` SET 
    `minimo` = 500001.00, 
    `maximo` = 1000000.00 
WHERE `id` = 3 AND `nome` = 'Ouro';

-- Atualizar Safira (já existe no banco)
UPDATE `niveis` SET 
    `minimo` = 1000001.00, 
    `maximo` = 5000000.00 
WHERE `id` = 4 AND `nome` = 'Safira';

-- Atualizar Diamante (já existe no banco)
UPDATE `niveis` SET 
    `minimo` = 5000001.00, 
    `maximo` = 10000000.00 
WHERE `id` = 5 AND `nome` = 'Diamante';

-- Verificar se o sistema de níveis está ativo
UPDATE `app` SET `niveis_ativo` = 1 WHERE `id` = 1;

-- Verificar os níveis atualizados
SELECT * FROM `niveis` ORDER BY `id`;
