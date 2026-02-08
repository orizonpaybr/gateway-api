#!/bin/bash

# Script de Configuração do Banco de Dados
# Execute como root ou com sudo

set -e

echo "🗄️  Configurando Banco de Dados MySQL..."
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Solicitar informações
read -p "Digite a senha root do MySQL: " MYSQL_ROOT_PASSWORD
read -p "Digite o nome do banco de dados (ex: gateway_api): " DB_NAME
read -p "Digite o usuário do banco de dados (ex: gateway_user): " DB_USER
read -sp "Digite a senha do usuário do banco: " DB_PASSWORD
echo ""

# Criar banco de dados e usuário
echo -e "${YELLOW}📦 Criando banco de dados e usuário...${NC}"

mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Banco de dados '$DB_NAME' criado com sucesso!${NC}"
    echo -e "${GREEN}✅ Usuário '$DB_USER' criado com sucesso!${NC}"
    echo ""
    echo "📝 Informações para o arquivo .env:"
    echo "DB_CONNECTION=mysql"
    echo "DB_HOST=127.0.0.1"
    echo "DB_PORT=3306"
    echo "DB_DATABASE=$DB_NAME"
    echo "DB_USERNAME=$DB_USER"
    echo "DB_PASSWORD=$DB_PASSWORD"
else
    echo -e "${RED}❌ Erro ao criar banco de dados${NC}"
    exit 1
fi

# Otimizar MySQL para produção
echo -e "${YELLOW}⚙️  Otimizando configurações do MySQL...${NC}"

cat >> /etc/mysql/mysql.conf.d/mysqld.cnf <<EOF

# Otimizações para Gateway API
max_connections = 200
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
query_cache_size = 64M
tmp_table_size = 64M
max_heap_table_size = 64M
EOF

systemctl restart mysql

echo -e "${GREEN}✅ Configuração do banco de dados concluída!${NC}"
