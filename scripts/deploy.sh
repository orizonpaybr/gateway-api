#!/bin/bash

# Script de Deploy do Gateway API
# Execute como usuário gateway ou com sudo -u gateway

set -e

APP_DIR="/var/www/gateway-api"
BRANCH="${1:-main}"

echo "🚀 Iniciando deploy do Gateway API..."
echo "Branch: $BRANCH"
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Verificar se o diretório existe
if [ ! -d "$APP_DIR" ]; then
    echo -e "${RED}❌ Diretório $APP_DIR não encontrado!${NC}"
    echo "Execute primeiro: git clone git@github-coratribr:coratribr/gateway-api.git $APP_DIR"
    exit 1
fi

cd "$APP_DIR"

# Backup do .env atual
if [ -f .env ]; then
    echo -e "${YELLOW}💾 Fazendo backup do .env...${NC}"
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
fi

# Atualizar código
echo -e "${YELLOW}📥 Atualizando código do repositório...${NC}"
git fetch origin
git checkout "$BRANCH"
git pull origin "$BRANCH"

# Instalar/atualizar dependências
echo -e "${YELLOW}📦 Instalando dependências do Composer...${NC}"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Configurar permissões
echo -e "${YELLOW}🔐 Configurando permissões...${NC}"
sudo chown -R gateway:www-data "$APP_DIR"
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 755 "$APP_DIR"

# Verificar se .env existe
if [ ! -f .env ]; then
    echo -e "${YELLOW}⚠️  Arquivo .env não encontrado!${NC}"
    if [ -f .env.example ]; then
        cp .env.example .env
        echo -e "${YELLOW}📝 Arquivo .env criado a partir do .env.example${NC}"
        echo -e "${RED}⚠️  IMPORTANTE: Configure o arquivo .env antes de continuar!${NC}"
        exit 1
    else
        echo -e "${RED}❌ Arquivo .env.example não encontrado!${NC}"
        exit 1
    fi
fi

# Gerar chave da aplicação (se necessário)
if ! grep -q "APP_KEY=base64:" .env; then
    echo -e "${YELLOW}🔑 Gerando chave da aplicação...${NC}"
    php artisan key:generate --force
fi

# Executar migrations
echo -e "${YELLOW}🗄️  Executando migrations...${NC}"
php artisan migrate --force

# Criar link simbólico do storage
if [ ! -L public/storage ]; then
    echo -e "${YELLOW}📁 Criando link simbólico do storage...${NC}"
    php artisan storage:link
fi

# Limpar e otimizar cache
echo -e "${YELLOW}🧹 Limpando cache...${NC}"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Otimizar para produção
echo -e "${YELLOW}⚡ Otimizando para produção...${NC}"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Reiniciar PHP-FPM
echo -e "${YELLOW}🔄 Reiniciando PHP-FPM...${NC}"
sudo systemctl restart php8.2-fpm

echo ""
echo -e "${GREEN}✅ Deploy concluído com sucesso!${NC}"
echo ""
echo "📝 Próximos passos:"
echo "1. Verifique os logs: tail -f storage/logs/laravel.log"
echo "2. Teste a API: curl http://seu-dominio.com/api/health"
echo "3. Configure Supervisor para queues (se necessário)"
