#!/bin/bash

# Script para configurar Supervisor (queues Laravel)
# Execute como root ou com sudo

set -e

echo "👷 Configurando Supervisor para queues Laravel..."
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

APP_DIR="/var/www/gateway-api"

# Criar configuração do Supervisor
CONFIG_FILE="/etc/supervisor/conf.d/gateway-api-queue.conf"

cat > "$CONFIG_FILE" <<EOF
[program:gateway-api-queue]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=gateway
numprocs=2
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/queue-worker.log
stopwaitsecs=3600
EOF

# Recarregar Supervisor
echo -e "${YELLOW}🔄 Recarregando Supervisor...${NC}"
supervisorctl reread
supervisorctl update
supervisorctl start gateway-api-queue:*

echo -e "${GREEN}✅ Supervisor configurado com sucesso!${NC}"
echo ""
echo "📝 Comandos úteis:"
echo "   supervisorctl status gateway-api-queue:*"
echo "   supervisorctl restart gateway-api-queue:*"
echo "   supervisorctl stop gateway-api-queue:*"
