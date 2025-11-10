#!/bin/bash

# Script para configurar banco de dados local
# Execute este script no Git Bash ou WSL

echo "=========================================="
echo "🚀 Configuração do Banco de Dados Local"
echo "=========================================="
echo ""

# Cores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configurações do banco
DB_NAME="martinspay_app"
DB_USER="root"
DB_PASS=""
DB_HOST="127.0.0.1"
DB_PORT="3306"

echo -e "${YELLOW}Passo 1:${NC} Verificando conexão com MySQL..."
if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SELECT 1;" > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} MySQL conectado com sucesso!"
else
    echo -e "${RED}✗${NC} Erro ao conectar no MySQL. Verifique se o XAMPP está rodando."
    exit 1
fi

echo ""
echo -e "${YELLOW}Passo 2:${NC} Criando banco de dados '$DB_NAME'..."
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Banco de dados criado com sucesso!"
else
    echo -e "${RED}✗${NC} Erro ao criar banco de dados."
    exit 1
fi

echo ""
echo -e "${YELLOW}Passo 3:${NC} Importando estrutura do banco..."
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" < martinspay-app.sql

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Estrutura importada com sucesso!"
else
    echo -e "${RED}✗${NC} Erro ao importar estrutura."
    exit 1
fi

echo ""
echo -e "${YELLOW}Passo 4:${NC} Limpando dados de produção sensíveis..."
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" <<EOF
-- Limpar transações reais
TRUNCATE TABLE transactions;
TRUNCATE TABLE solicitacoes;
TRUNCATE TABLE solicitacoes_cash_out;
TRUNCATE TABLE pix_depositos;
TRUNCATE TABLE retiradas;
TRUNCATE TABLE confirmar_deposito;
TRUNCATE TABLE pedidos;

-- Limpar logs
TRUNCATE TABLE logs_ip_cash_out;

-- Limpar chaves de API sensíveis (manter estrutura)
UPDATE ad_mercadopago SET access_token = NULL;
UPDATE ad_efi SET client_id = NULL, client_secret = NULL;
UPDATE ad_pagarme SET api_key = NULL;
UPDATE ad_pixup SET client_id = NULL, client_secret = NULL;
UPDATE ad_bspay SET client_id = NULL, client_secret = NULL, token = NULL;
UPDATE ad_woovi SET app_id = NULL;
UPDATE ad_asaas SET api_key = NULL;
UPDATE ad_syscoop SET token_api = NULL;
UPDATE ad_primepay7 SET token = NULL;
UPDATE ad_xdpag SET token = NULL;

-- Manter apenas usuários de teste
DELETE FROM users WHERE email NOT LIKE '%@test.com%' AND email NOT LIKE '%@exemplo.com%';

-- Resetar senhas dos usuários de teste para uma senha padrão
-- Senha: teste123 (hash bcrypt)
UPDATE users SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Dados sensíveis removidos!"
else
    echo -e "${YELLOW}⚠${NC} Aviso: Alguns dados podem não ter sido limpos."
fi

echo ""
echo -e "${YELLOW}Passo 5:${NC} Criando usuário de teste..."
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" <<EOF
-- Inserir usuário de teste se não existir
INSERT IGNORE INTO users (
    id, name, email, password, cpf_cnpj, telefone, 
    status, permission, cliente_id, username, created_at, updated_at
) VALUES (
    1,
    'Usuário Teste',
    'teste@exemplo.com',
    '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '000.000.000-00',
    '(00) 0000-0000',
    1,
    'admin',
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
    'Gateway Orizon',
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
(4, 'Asaas', 'asaas', 0, NOW(), NOW());

EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Usuário de teste criado!"
else
    echo -e "${YELLOW}⚠${NC} Usuário de teste pode já existir."
fi

echo ""
echo -e "${YELLOW}Passo 6:${NC} Testando Redis..."
if command -v docker &> /dev/null; then
    if docker ps | grep -q redis-gateway; then
        echo -e "${GREEN}✓${NC} Redis está rodando no Docker!"
    else
        echo -e "${YELLOW}⚠${NC} Redis não está rodando. Execute: docker start redis-gateway"
    fi
else
    echo -e "${YELLOW}⚠${NC} Docker não detectado. Verifique manualmente se o Redis está rodando."
fi

echo ""
echo "=========================================="
echo -e "${GREEN}✅ Configuração Concluída!${NC}"
echo "=========================================="
echo ""
echo "📝 Informações do Banco Local:"
echo "   • Banco: $DB_NAME"
echo "   • Host: $DB_HOST:$DB_PORT"
echo "   • Usuário: $DB_USER"
echo "   • Senha: (vazia)"
echo ""
echo "👤 Usuário de Teste:"
echo "   • Email: teste@exemplo.com"
echo "   • Senha: teste123"
echo ""
echo "🔧 Próximos passos:"
echo "   1. cd gateway-backend"
echo "   2. php artisan config:clear"
echo "   3. php artisan cache:clear"
echo "   4. php artisan serve"
echo ""
echo "   5. Acesse: http://localhost:8000"
echo ""

