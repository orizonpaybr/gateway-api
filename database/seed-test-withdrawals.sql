-- Ajuste de configuração: habilitar saque automático e definir limite de R$ 5.000,00
UPDATE app SET saque_automatico = 1, limite_saque_automatico = 5000.00;

-- Mocks de saques na tabela solicitacoes_cash_out para testes de filtros/paginação
-- Observações:
-- - descricao_transacao = 'WEB' (é o que a listagem filtra)
-- - executor_ordem NULL => Manual | NÃO NULL => Automático
-- - status variados: PENDING, COMPLETED, CANCELLED
-- - user_id NULL para evitar dependência de usuários

DELETE FROM solicitacoes_cash_out WHERE externalreference LIKE 'MOCK-WD-%';

INSERT INTO solicitacoes_cash_out
  (user_id, externalreference, amount, beneficiaryname, beneficiarydocument, pix, pixkey, date, status, type, idTransaction, taxa_cash_out, cash_out_liquido, descricao_transacao, executor_ordem, created_at, updated_at)
VALUES
  -- Pendentes (hoje)
  (NULL, 'MOCK-WD-001', 150.00, 'Maria Souza', '12345678901', 'cpf', '123.456.789-01', NOW(), 'PENDING', 'PIX', NULL, 2.50, 147.50, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-002', 4800.00, 'João Lima', '98765432100', 'cpf', '987.654.321-00', NOW(), 'PENDING', 'PIX', NULL, 30.00, 4770.00, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-003', 5200.00, 'Empresa XYZ LTDA', '11222333000199', 'cnpj', '11.222.333/0001-99', NOW(), 'PENDING', 'PIX', NULL, 35.00, 5165.00, 'WEB', NULL, NOW(), NOW()),

  -- Aprovados (hoje, automáticos)
  (NULL, 'MOCK-WD-004', 300.00, 'Carlos Pereira', '11122233344', 'cpf', '111.222.333-44', NOW(), 'COMPLETED', 'PIX', 'GATE-1004', 2.00, 298.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),
  (NULL, 'MOCK-WD-005', 4900.00, 'Ana Paula', '55566677788', 'cpf', '555.666.777-88', NOW(), 'COMPLETED', 'PIX', 'GATE-1005', 28.00, 4872.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),

  -- Rejeitados (hoje)
  (NULL, 'MOCK-WD-006', 200.00, 'Pedro Henrique', '22233344455', 'cpf', '222.333.444-55', NOW(), 'CANCELLED', 'PIX', NULL, 2.00, 198.00, 'WEB', NULL, NOW(), NOW()),

  -- Pendentes (últimos 7 dias)
  (NULL, 'MOCK-WD-007', 50.00, 'Luiza Ramos', '33344455566', 'cpf', '333.444.555-66', DATE_SUB(NOW(), INTERVAL 2 DAY), 'PENDING', 'PIX', NULL, 1.00, 49.00, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-008', 750.00, 'Ricardo Alves', '44455566677', 'cpf', '444.555.666-77', DATE_SUB(NOW(), INTERVAL 5 DAY), 'PENDING', 'PIX', NULL, 5.00, 745.00, 'WEB', NULL, NOW(), NOW()),

  -- Aprovados (últimos 7 dias)
  (NULL, 'MOCK-WD-009', 1200.00, 'Fernanda Dias', '55566677799', 'cpf', '555.666.777-99', DATE_SUB(NOW(), INTERVAL 3 DAY), 'COMPLETED', 'PIX', 'GATE-1009', 8.00, 1192.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),
  (NULL, 'MOCK-WD-010', 5200.00, 'Tech Soluções ME', '00998877000155', 'cnpj', '00.998.877/0001-55', DATE_SUB(NOW(), INTERVAL 6 DAY), 'COMPLETED', 'PIX', 'GATE-1010', 40.00, 5160.00, 'WEB', NULL, NOW(), NOW()),

  -- Rejeitados (últimos 7 dias)
  (NULL, 'MOCK-WD-011', 80.00, 'Gabriel Costa', '66677788899', 'cpf', '666.777.888-99', DATE_SUB(NOW(), INTERVAL 4 DAY), 'CANCELLED', 'PIX', NULL, 1.00, 79.00, 'WEB', NULL, NOW(), NOW()),

  -- Aprovados (últimos 30 dias)
  (NULL, 'MOCK-WD-012', 500.00, 'Marcos Silva', '77788899900', 'cpf', '777.888.999-00', DATE_SUB(NOW(), INTERVAL 10 DAY), 'COMPLETED', 'PIX', 'GATE-1012', 3.00, 497.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),
  (NULL, 'MOCK-WD-013', 3200.00, 'Julia Martins', '88899900011', 'cpf', '888.999.000-11', DATE_SUB(NOW(), INTERVAL 15 DAY), 'COMPLETED', 'PIX', 'GATE-1013', 20.00, 3180.00, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-014', 4999.99, 'Eduardo Rocha', '99900011122', 'cpf', '999.000.111-22', DATE_SUB(NOW(), INTERVAL 20 DAY), 'COMPLETED', 'PIX', 'GATE-1014', 29.99, 4970.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),

  -- Rejeitados (últimos 30 dias)
  (NULL, 'MOCK-WD-015', 6000.00, 'Comércio ABC', '12345067000120', 'cnpj', '12.345.067/0001-20', DATE_SUB(NOW(), INTERVAL 18 DAY), 'CANCELLED', 'PIX', NULL, 45.00, 5955.00, 'WEB', NULL, NOW(), NOW()),

  -- Diversos (datas espalhadas)
  (NULL, 'MOCK-WD-016', 999.90, 'Patricia Freitas', '00011122233', 'cpf', '000.111.222-33', DATE_SUB(NOW(), INTERVAL 8 DAY), 'PENDING', 'PIX', NULL, 5.00, 994.90, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-017', 250.00, 'Roberto Nunes', '11122233300', 'cpf', '111.222.333-00', DATE_SUB(NOW(), INTERVAL 27 DAY), 'COMPLETED', 'PIX', 'GATE-1017', 2.00, 248.00, 'WEB', 'AUTO:SYSTEM', NOW(), NOW()),
  (NULL, 'MOCK-WD-018', 50.00, 'Thiago Santos', '22233344400', 'cpf', '222.333.444-00', DATE_SUB(NOW(), INTERVAL 29 DAY), 'CANCELLED', 'PIX', NULL, 1.00, 49.00, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-019', 7000.00, 'Loja Teste 123', '33445566000177', 'cnpj', '33.445.566/0001-77', DATE_SUB(NOW(), INTERVAL 1 DAY), 'PENDING', 'PIX', NULL, 50.00, 6950.00, 'WEB', NULL, NOW(), NOW()),
  (NULL, 'MOCK-WD-020', 120.00, 'Cliente Exemplo', '44455566600', 'cpf', '444.555.666-00', DATE_SUB(NOW(), INTERVAL 12 DAY), 'PENDING', 'PIX', NULL, 1.50, 118.50, 'WEB', NULL, NOW(), NOW());


