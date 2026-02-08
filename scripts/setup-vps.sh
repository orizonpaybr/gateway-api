#!/bin/bash

# Script de Setup Inicial da VPS Contabo
# Execute como root ou com sudo

set -e

echo "🚀 Iniciando setup da VPS Contabo para Gateway API..."
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Verificar se está rodando como root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}❌ Por favor, execute como root ou com sudo${NC}"
    exit 1
fi

# Atualizar sistema
echo -e "${YELLOW}📦 Atualizando sistema...${NC}"
apt-get update
apt-get upgrade -y

# Instalar ferramentas básicas
echo -e "${YELLOW}🔧 Instalando ferramentas básicas...${NC}"
apt-get install -y \
    curl \
    wget \
    git \
    unzip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release \
    ufw \
    fail2ban

# Configurar firewall
echo -e "${YELLOW}🔥 Configurando firewall...${NC}"
ufw --force enable
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS
ufw allow 3306/tcp # MySQL (apenas se precisar acesso externo)
ufw status

# Instalar MySQL/MariaDB
echo -e "${YELLOW}🗄️  Instalando MySQL...${NC}"
debconf-set-selections <<< "mysql-server mysql-server/root_password password temp_root_password"
debconf-set-selections <<< "mysql-server mysql-server/root_password_again password temp_root_password"
apt-get install -y mysql-server mysql-client

# Configurar MySQL
echo -e "${YELLOW}⚙️  Configurando MySQL...${NC}"
mysql -u root -ptemp_root_password <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'temp_root_password';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF

# Instalar Redis
echo -e "${YELLOW}📦 Instalando Redis...${NC}"
apt-get install -y redis-server
systemctl enable redis-server
systemctl start redis-server

# Instalar PHP 8.2 e extensões
echo -e "${YELLOW}🐘 Instalando PHP 8.2...${NC}"
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
    php8.2 \
    php8.2-fpm \
    php8.2-cli \
    php8.2-common \
    php8.2-mysql \
    php8.2-xml \
    php8.2-dom \
    php8.2-gd \
    php8.2-zip \
    php8.2-bcmath \
    php8.2-curl \
    php8.2-mbstring \
    php8.2-tokenizer \
    php8.2-pdo \
    php8.2-redis \
    php8.2-opcache \
    php8.2-intl \
    php8.2-fileinfo

# Configurar PHP-FPM
echo -e "${YELLOW}⚙️  Configurando PHP-FPM...${NC}"
sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' /etc/php/8.2/fpm/php.ini
sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/8.2/fpm/php.ini
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 50M/' /etc/php/8.2/fpm/php.ini
sed -i 's/post_max_size = .*/post_max_size = 50M/' /etc/php/8.2/fpm/php.ini
sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/8.2/fpm/php.ini

# Instalar Composer
echo -e "${YELLOW}📦 Instalando Composer...${NC}"
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

# Instalar Nginx
echo -e "${YELLOW}🌐 Instalando Nginx...${NC}"
apt-get install -y nginx
systemctl enable nginx
systemctl start nginx

# Instalar Certbot (Let's Encrypt)
echo -e "${YELLOW}🔒 Instalando Certbot...${NC}"
apt-get install -y certbot python3-certbot-nginx

# Instalar Supervisor (para queues)
echo -e "${YELLOW}👷 Instalando Supervisor...${NC}"
apt-get install -y supervisor

# Criar usuário para aplicação
echo -e "${YELLOW}👤 Criando usuário para aplicação...${NC}"
if ! id "gateway" &>/dev/null; then
    useradd -m -s /bin/bash gateway
    usermod -aG www-data gateway
fi

# Criar diretórios
echo -e "${YELLOW}📁 Criando diretórios...${NC}"
mkdir -p /var/www/gateway-api
mkdir -p /var/www/gateway-api/storage/logs
mkdir -p /var/www/gateway-api/storage/framework/cache
mkdir -p /var/www/gateway-api/storage/framework/sessions
mkdir -p /var/www/gateway-api/storage/framework/views
chown -R gateway:www-data /var/www/gateway-api
chmod -R 775 /var/www/gateway-api/storage
chmod -R 775 /var/www/gateway-api/bootstrap/cache

# Reiniciar serviços
echo -e "${YELLOW}🔄 Reiniciando serviços...${NC}"
systemctl restart php8.2-fpm
systemctl restart nginx
systemctl restart mysql
systemctl restart redis-server

echo ""
echo -e "${GREEN}✅ Setup da VPS concluído com sucesso!${NC}"
echo ""
echo "📝 Próximos passos:"
echo "1. Configure o banco de dados: mysql -u root -p"
echo "2. Clone o repositório: git clone git@github-orizonpaybr:orizonpaybr/gateway-api.git /var/www/gateway-api"
echo "3. Execute o script de deploy: ./scripts/deploy.sh"
echo ""
echo "⚠️  IMPORTANTE: Altere a senha root do MySQL!"
echo "   mysql -u root -ptemp_root_password"
echo "   ALTER USER 'root'@'localhost' IDENTIFIED BY 'sua_senha_segura';"
