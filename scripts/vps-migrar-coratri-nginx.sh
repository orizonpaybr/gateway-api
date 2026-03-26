#!/usr/bin/env bash
#
# Migra o vhost Nginx da API de api.orizonpay.com para api.coratri.com
# Uso na VPS (root): bash vps-migrar-coratri-nginx.sh
#
# PRÉ-REQUISITOS:
# 1) DNS: registo A (e AAAA se usar) de api.coratri.com -> IP deste servidor
# 2) Ficheiro do site (ajusta o caminho se for diferente no teu servidor)
#
set -euo pipefail

SITE_AVAILABLE="${SITE_AVAILABLE:-/etc/nginx/sites-available/gateway-api}"
SITE_ENABLED="${SITE_ENABLED:-/etc/nginx/sites-enabled/gateway-api}"
OLD_DOMAIN="api.orizonpay.com"
NEW_DOMAIN="api.coratri.com"

if [[ ! -f "$SITE_AVAILABLE" ]]; then
  echo "Erro: não encontrei $SITE_AVAILABLE"
  echo "Lista os sites: ls -la /etc/nginx/sites-available/"
  exit 1
fi

TS=$(date +%Y%m%d-%H%M%S)
cp -a "$SITE_AVAILABLE" "${SITE_AVAILABLE}.bak-coratri-${TS}"
echo "Backup: ${SITE_AVAILABLE}.bak-coratri-${TS}"

sed -i \
  -e "s/${OLD_DOMAIN}/${NEW_DOMAIN}/g" \
  "$SITE_AVAILABLE"

if [[ -L "$SITE_ENABLED" ]] || [[ -f "$SITE_ENABLED" ]]; then
  # sites-enabled costuma ser symlink para sites-available
  if [[ -L "$SITE_ENABLED" ]]; then
    echo "sites-enabled já aponta para sites-available (ok)"
  else
    cp -a "$SITE_ENABLED" "${SITE_ENABLED}.bak-coratri-${TS}" 2>/dev/null || true
    sed -i -e "s/${OLD_DOMAIN}/${NEW_DOMAIN}/g" "$SITE_ENABLED"
  fi
fi

echo ""
echo "=== Teste de configuração ==="
if nginx -t 2>&1; then
  echo ""
  echo "OK: nginx -t passou. Podes: systemctl reload nginx"
else
  echo ""
  echo "FALHOU: provavelmente certificados SSL ainda não existem para ${NEW_DOMAIN}."
  echo "Com DNS já a apontar para este servidor, obtém certificado:"
  echo "  sudo certbot certonly --nginx -d ${NEW_DOMAIN}"
  echo "ou (se precisares de parar o nginx um momento):"
  echo "  sudo systemctl stop nginx && sudo certbot certonly --standalone -d ${NEW_DOMAIN} && sudo systemctl start nginx"
  echo "Depois confirma em ${SITE_AVAILABLE} que as linhas ssl_certificate apontam para:"
  echo "  /etc/letsencrypt/live/${NEW_DOMAIN}/"
  exit 1
fi

echo ""
read -r -p "Recarregar nginx agora? [s/N] " ans
if [[ "${ans:-}" =~ ^[sSyY]$ ]]; then
  systemctl reload nginx
  echo "nginx recarregado."
fi

echo ""
echo "Lembra-te: no Laravel em /var/www/gateway-api/.env deve estar:"
echo "  APP_URL=https://${NEW_DOMAIN}"
echo "  FRONTEND_URL=https://finance.coratri.com   (ou o domínio real do app)"
echo "Depois: cd /var/www/gateway-api && php artisan config:clear && php artisan cache:clear"
