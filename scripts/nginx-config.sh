#!/bin/bash

# Script para criar configuração do Nginx
# Execute como root ou com sudo

set -e

echo "🌐 Configurando Nginx para Gateway API..."
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Solicitar domínio
read -p "Digite o domínio da API (ex: api.seudominio.com.br): " DOMAIN
read -p "Digite o caminho da aplicação [/var/www/gateway-api]: " APP_DIR
APP_DIR=${APP_DIR:-/var/www/gateway-api}

# Criar configuração do Nginx
CONFIG_FILE="/etc/nginx/sites-available/gateway-api"

cat > "$CONFIG_FILE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Otimizações
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml font/truetype font/opentype application/vnd.ms-fontobject image/svg+xml;

    # Limites de upload
    client_max_body_size 50M;
    client_body_timeout 300s;
}
EOF

# Criar link simbólico
if [ ! -L /etc/nginx/sites-enabled/gateway-api ]; then
    ln -s "$CONFIG_FILE" /etc/nginx/sites-enabled/
fi

# Remover default se existir
if [ -L /etc/nginx/sites-enabled/default ]; then
    rm /etc/nginx/sites-enabled/default
fi

# Testar configuração
echo -e "${YELLOW}🧪 Testando configuração do Nginx...${NC}"
nginx -t

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Configuração válida!${NC}"
    systemctl reload nginx
    echo -e "${GREEN}✅ Nginx recarregado!${NC}"
    echo ""
    echo "📝 Próximos passos:"
    echo "1. Configure o DNS do domínio $DOMAIN para apontar para o IP da VPS"
    echo "2. Execute o script SSL: ./scripts/setup-ssl.sh"
else
    echo -e "${RED}❌ Erro na configuração do Nginx!${NC}"
    exit 1
fi
