#!/bin/bash

# Script para configurar SSL com Let's Encrypt
# Execute como root ou com sudo

set -e

echo "🔒 Configurando SSL com Let's Encrypt..."
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Solicitar domínio
read -p "Digite o domínio da API (ex: api.seudominio.com.br): " DOMAIN
read -p "Digite o email para notificações do Let's Encrypt: " EMAIL

# Verificar se o domínio está apontando para o servidor
echo -e "${YELLOW}🔍 Verificando DNS...${NC}"
SERVER_IP=$(curl -s ifconfig.me)
DOMAIN_IP=$(dig +short $DOMAIN | tail -n1)

if [ "$DOMAIN_IP" != "$SERVER_IP" ]; then
    echo -e "${YELLOW}⚠️  Atenção: O domínio pode não estar apontando para este servidor${NC}"
    echo "   IP do servidor: $SERVER_IP"
    echo "   IP do domínio: $DOMAIN_IP"
    read -p "Deseja continuar mesmo assim? (s/N): " CONTINUE
    if [ "$CONTINUE" != "s" ] && [ "$CONTINUE" != "S" ]; then
        exit 1
    fi
fi

# Obter certificado SSL
echo -e "${YELLOW}📜 Obtendo certificado SSL...${NC}"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$EMAIL"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Certificado SSL instalado com sucesso!${NC}"
    
    # Configurar renovação automática
    echo -e "${YELLOW}🔄 Configurando renovação automática...${NC}"
    systemctl enable certbot.timer
    systemctl start certbot.timer
    
    echo ""
    echo -e "${GREEN}✅ SSL configurado com sucesso!${NC}"
    echo ""
    echo "🌐 Acesse: https://$DOMAIN"
    echo ""
    echo "📝 O certificado será renovado automaticamente a cada 90 dias"
else
    echo -e "${RED}❌ Erro ao obter certificado SSL${NC}"
    exit 1
fi
