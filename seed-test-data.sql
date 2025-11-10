-- Script para criar dados de teste

-- Inserir usuário de teste se não existir
INSERT IGNORE INTO users (
    id, name, email, password, cpf_cnpj, telefone, 
    status, permission, cliente_id, username, created_at, updated_at
) VALUES (
    1,
    'Usuário Teste Admin',
    'admin@exemplo.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '000.000.000-00',
    '(00) 0000-0000',
    1,
    'admin',
    'cliente_admin',
    'admin',
    NOW(),
    NOW()
);

INSERT IGNORE INTO users (
    id, name, email, password, cpf_cnpj, telefone, 
    status, permission, cliente_id, username, created_at, updated_at
) VALUES (
    2,
    'Usuário Teste',
    'teste@exemplo.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '111.111.111-11',
    '(11) 1111-1111',
    1,
    'user',
    'cliente_teste',
    'usuario_teste',
    NOW(),
    NOW()
);

-- Inserir configuração padrão da app se não existir
INSERT IGNORE INTO app (
    id, nome_aplicacao, created_at, updated_at
) VALUES (
    1,
    'Gateway Orizon - Local',
    NOW(),
    NOW()
);

-- Inserir níveis de gamificação
INSERT IGNORE INTO niveis (id, nome, minimo, maximo, created_at, updated_at) VALUES
(1, 'Bronze', 0.00, 100000.00, NOW(), NOW()),
(2, 'Prata', 100001.00, 500000.00, NOW(), NOW()),
(3, 'Ouro', 500001.00, 1000000.00, NOW(), NOW()),
(4, 'Safira', 1000001.00, 5000000.00, NOW(), NOW()),
(5, 'Diamante', 5000001.00, 10000000.00, NOW(), NOW());

-- Inserir adquirentes padrão
INSERT IGNORE INTO adquirentes (id, nome, referencia, is_default, created_at, updated_at) VALUES
(1, 'Pixup', 'pixup', 0, NOW(), NOW()),
(2, 'BSPay', 'bspay', 0, NOW(), NOW()),
(3, 'Woovi', 'woovi', 0, NOW(), NOW()),
(4, 'Asaas', 'asaas', 0, NOW(), NOW()),
(5, 'Syscoop', 'syscoop', 0, NOW(), NOW()),
(6, 'PrimePay7', 'primepay7', 0, NOW(), NOW()),
(7, 'XDPag', 'xdpag', 0, NOW(), NOW());

-- Inserir algumas transações de teste para visualização
INSERT IGNORE INTO transactions (
    id, order_id, amount, status, payment_method, user_id, created_at, updated_at
) VALUES 
(1, 'TEST001', 100.00, 'completed', 'pix', 1, NOW(), NOW()),
(2, 'TEST002', 250.00, 'completed', 'pix', 1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(3, 'TEST003', 50.00, 'pending', 'pix', 2, NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 2 HOUR);

