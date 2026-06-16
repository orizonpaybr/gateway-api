#!/usr/bin/env bash
#
# Hardening de segurança na VPS (Contabo / Ubuntu)
# Execute como root na VPS:
#   cd /var/www/gateway-api && sudo bash scripts/hardening-vps.sh
#
# Variáveis opcionais:
#   TRUSTED_IPS="45.233.86.55 45.169.215.9"   IPs que não levam ban permanente
#   RESTRICT_SSH=yes                            SSH só dos IPs confiáveis
#   KEEP_MYSQL_REMOTE_IP=45.169.215.9          Mantém MySQL aberto só para este IP (não recomendado)
#
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SECURITY_DIR="${SCRIPT_DIR}/security"
NGINX_SITE="${NGINX_SITE:-/etc/nginx/sites-available/gateway-api}"

if [[ "${EUID}" -ne 0 ]]; then
  echo -e "${RED}Execute como root: sudo bash scripts/hardening-vps.sh${NC}"
  exit 1
fi

if [[ ! -d "${SECURITY_DIR}" ]]; then
  echo -e "${RED}Pasta ${SECURITY_DIR} não encontrada. Rode na raiz do repo gateway-api.${NC}"
  exit 1
fi

TRUSTED_IPS="${TRUSTED_IPS:-45.233.86.55}"
RESTRICT_SSH="${RESTRICT_SSH:-no}"
KEEP_MYSQL_REMOTE_IP="${KEEP_MYSQL_REMOTE_IP:-}"

echo -e "${YELLOW}=== Hardening VPS Gateway API ===${NC}"
echo "IPs confiáveis: ${TRUSTED_IPS}"
echo ""

# --- 1. MySQL: só localhost ---
echo -e "${YELLOW}[1/7] MySQL — bind 127.0.0.1${NC}"
MYSQL_CNF="/etc/mysql/mysql.conf.d/mysqld.cnf"
if [[ -f "${MYSQL_CNF}" ]]; then
  cp -a "${MYSQL_CNF}" "${MYSQL_CNF}.bak-hardening-$(date +%Y%m%d)"
  if grep -q '^bind-address' "${MYSQL_CNF}"; then
    sed -i 's/^bind-address\s*=.*/bind-address = 127.0.0.1/' "${MYSQL_CNF}"
  else
    echo "bind-address = 127.0.0.1" >> "${MYSQL_CNF}"
  fi
  systemctl restart mysql
  echo -e "${GREEN}MySQL reiniciado (bind 127.0.0.1)${NC}"
else
  echo -e "${YELLOW}Aviso: ${MYSQL_CNF} não encontrado${NC}"
fi

# --- 2. UFW ---
echo -e "${YELLOW}[2/7] UFW — fechar portas internas${NC}"
ufw --force enable

# Extrai número da regra UFW (formato: "[ 3] 3306/tcp ...")
_ufw_rule_num() {
  local pattern="$1"
  ufw status numbered 2>/dev/null \
    | grep "${pattern}" \
    | head -1 \
    | sed -n 's/.*\[[[:space:]]*\([0-9][0-9]*\)\].*/\1/p'
}

# Remove todas as regras que mencionem uma porta (idempotente).
# set +e local: parsing de regras não deve abortar o script.
_ufw_purge_port() {
  local pattern="$1"
  local rule_num
  set +e
  for _ in $(seq 1 20); do
    rule_num="$(_ufw_rule_num "${pattern}")"
    if [[ -z "${rule_num}" ]]; then
      break
    fi
    ufw --force delete "${rule_num}" >/dev/null 2>&1
  done
  set -e
}

_ufw_purge_port '3306'
_ufw_purge_port '6379'

ufw deny 3306/tcp >/dev/null 2>&1 || true
ufw deny 6379/tcp >/dev/null 2>&1 || true

if [[ -n "${KEEP_MYSQL_REMOTE_IP}" ]]; then
  echo -e "${YELLOW}Aviso: mantendo MySQL acessível para ${KEEP_MYSQL_REMOTE_IP} (menos seguro)${NC}"
  sed -i 's/^bind-address\s*=.*/bind-address = 0.0.0.0/' "${MYSQL_CNF}"
  ufw allow from "${KEEP_MYSQL_REMOTE_IP}" to any port 3306 proto tcp
  systemctl restart mysql
fi

if [[ "${RESTRICT_SSH}" == "yes" ]]; then
  echo -e "${YELLOW}Restringindo SSH aos IPs confiáveis${NC}"
  ufw delete allow 22/tcp 2>/dev/null || true
  ufw delete allow 22 2>/dev/null || true
  for ip in ${TRUSTED_IPS}; do
    ufw allow from "${ip}" to any port 22 proto tcp
  done
else
  ufw allow 22/tcp
fi

ufw allow 80/tcp
ufw allow 443/tcp
ufw reload
ufw status verbose

# --- 3. Rate limit no kernel (UFW before.rules) ---
echo -e "${YELLOW}[3/7] UFW — rate limit por IP (hashlimit)${NC}"
BEFORE_RULES="/etc/ufw/before.rules"
MARKER="# gateway-api hashlimit"
if ! grep -q "${MARKER}" "${BEFORE_RULES}"; then
  cp -a "${BEFORE_RULES}" "${BEFORE_RULES}.bak-hardening-$(date +%Y%m%d)"
  sed -i "/^COMMIT$/i\\
${MARKER}\\
-A ufw-before-input -p tcp --dport 80 -m conntrack --ctstate NEW -m hashlimit --hashlimit-name http_rl --hashlimit-mode srcip --hashlimit-above 40/sec -j DROP\\
-A ufw-before-input -p tcp --dport 443 -m conntrack --ctstate NEW -m hashlimit --hashlimit-name https_rl --hashlimit-mode srcip --hashlimit-above 40/sec -j DROP\\
" "${BEFORE_RULES}"
  ufw reload
  echo -e "${GREEN}hashlimit aplicado em ${BEFORE_RULES}${NC}"
else
  echo "hashlimit já configurado"
fi

# --- 4. Sysctl ---
echo -e "${YELLOW}[4/7] Sysctl hardening${NC}"
cp "${SECURITY_DIR}/sysctl-hardening.conf" /etc/sysctl.d/99-gateway-hardening.conf
sysctl --system >/dev/null
echo -e "${GREEN}sysctl aplicado${NC}"

# --- 5. Fail2ban ---
echo -e "${YELLOW}[5/7] Fail2ban${NC}"
apt-get install -y fail2ban >/dev/null

cp "${SECURITY_DIR}/fail2ban-nginx-sensitive.conf" /etc/fail2ban/filter.d/nginx-sensitive.conf
cp "${SECURITY_DIR}/fail2ban-nginx-4xx-abuse.conf" /etc/fail2ban/filter.d/nginx-4xx-abuse.conf

TRUSTED_IPS_F2B=""
for ip in ${TRUSTED_IPS}; do
  TRUSTED_IPS_F2B="${TRUSTED_IPS_F2B} ${ip}"
done

sed "s/__TRUSTED_IPS__/${TRUSTED_IPS_F2B}/" "${SECURITY_DIR}/fail2ban-jail.local" > /etc/fail2ban/jail.local

touch /var/log/nginx/sensitive.log
chown www-data:adm /var/log/nginx/sensitive.log 2>/dev/null || chown www-data:www-data /var/log/nginx/sensitive.log

systemctl enable fail2ban >/dev/null 2>&1 || true
if systemctl restart fail2ban; then
  sleep 2
  fail2ban-client status || true
  echo -e "${GREEN}Fail2ban ativo${NC}"
else
  echo -e "${RED}Fail2ban não iniciou — verifique: journalctl -u fail2ban -n 30${NC}"
  echo -e "${YELLOW}Continuando demais passos...${NC}"
fi

# --- 6. Nginx ---
echo -e "${YELLOW}[6/7] Nginx — Cloudflare real_ip + rate limit + paths sensíveis${NC}"
cp "${SECURITY_DIR}/nginx-cloudflare-real-ip.conf" /etc/nginx/conf.d/cloudflare-real-ip.conf
cp "${SECURITY_DIR}/nginx-rate-limit.conf" /etc/nginx/conf.d/rate-limit.conf
cp "${SECURITY_DIR}/nginx-gateway-security.conf" /etc/nginx/snippets/gateway-security.conf

if [[ ! -f "${NGINX_SITE}" ]]; then
  echo -e "${RED}Site Nginx não encontrado: ${NGINX_SITE}${NC}"
  echo "Ajuste NGINX_SITE=... e rode de novo."
  exit 1
fi

cp -a "${NGINX_SITE}" "${NGINX_SITE}.bak-hardening-$(date +%Y%m%d)"

if ! grep -q 'snippets/gateway-security.conf' "${NGINX_SITE}"; then
  sed -i '/server {/a\    include /etc/nginx/snippets/gateway-security.conf;' "${NGINX_SITE}"
fi

if nginx -t 2>&1; then
  systemctl reload nginx
  echo -e "${GREEN}Nginx recarregado${NC}"
else
  echo -e "${RED}nginx -t falhou — restaurando backup${NC}"
  LATEST_BAK=$(ls -t "${NGINX_SITE}.bak-hardening-"* 2>/dev/null | head -1)
  if [[ -n "${LATEST_BAK}" ]]; then
    cp -a "${LATEST_BAK}" "${NGINX_SITE}"
  fi
  exit 1
fi

# --- 7. SSH básico ---
echo -e "${YELLOW}[7/7] SSH — endurecimento leve${NC}"
SSHD_CFG="/etc/ssh/sshd_config"
cp -a "${SSHD_CFG}" "${SSHD_CFG}.bak-hardening-$(date +%Y%m%d)"
for opt in MaxAuthTries LoginGraceTime ClientAliveInterval; do
  sed -i "/^${opt}/d" "${SSHD_CFG}"
done
{
  echo "MaxAuthTries 3"
  echo "LoginGraceTime 30"
  echo "ClientAliveInterval 300"
} >> "${SSHD_CFG}"

if sshd -t 2>&1; then
  systemctl reload sshd
  echo -e "${GREEN}sshd recarregado${NC}"
fi

echo ""
echo -e "${GREEN}=== Hardening concluído ===${NC}"
echo ""
echo "Verificações:"
echo "  grep real_ip /etc/nginx/conf.d/cloudflare-real-ip.conf | head -3"
echo "  ss -tlnp | grep -E '3306|6379'"
echo "  fail2ban-client status"
echo "  fail2ban-client status nginx-sensitive"
echo "  ufw status verbose"
echo ""
echo "Teste ban (de outro IP, NÃO do teu):"
echo "  curl -I https://api.coratri.com/.env"
echo ""
echo "Para restringir SSH só aos teus IPs:"
echo "  RESTRICT_SSH=yes TRUSTED_IPS=\"teu.ip.aqui\" bash scripts/hardening-vps.sh"
