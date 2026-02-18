#!/bin/bash

# Script para configurar Supervisor (queues Laravel)
# Execute como root ou com sudo

set -e

echo "Configurando Supervisor para queues Laravel..."
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

APP_DIR="/var/www/gateway-api"
CONFIG_FILE="/etc/supervisor/conf.d/gateway-api-queue.conf"

# ─────────────────────────────────────────────────────────────────────────────
# Fila "webhooks" — 4 workers dedicados para PIX (Cash In e Cash Out)
# Alta prioridade: processa fila 'webhooks' antes de 'default'
# ─────────────────────────────────────────────────────────────────────────────
cat > "$CONFIG_FILE" <<EOF
[program:gateway-api-queue]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work --queue=webhooks,default --sleep=1 --tries=3 --max-time=3600 --timeout=60
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=gateway
numprocs=4
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/queue-worker.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=3600
EOF

# Recarregar Supervisor
echo -e "${YELLOW}Recarregando Supervisor...${NC}"
supervisorctl reread
supervisorctl update
supervisorctl restart gateway-api-queue:* 2>/dev/null || supervisorctl start gateway-api-queue:*

echo -e "${GREEN}Supervisor configurado com sucesso!${NC}"
echo ""
echo "Configuracao aplicada:"
echo "  - 4 workers (numprocs=4)"
echo "  - Fila: webhooks,default (PIX tem prioridade)"
echo "  - Sleep: 1s (mais reativo)"
echo "  - Timeout: 60s por job"
echo ""
echo "Comandos uteis:"
echo "   supervisorctl status gateway-api-queue:*"
echo "   supervisorctl restart gateway-api-queue:*"
echo "   supervisorctl stop gateway-api-queue:*"
echo ""
echo "Monitorar workers em tempo real:"
echo "   tail -f $APP_DIR/storage/logs/queue-worker.log"
