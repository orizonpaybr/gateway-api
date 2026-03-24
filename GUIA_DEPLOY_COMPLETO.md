# 🚀 Guia Completo de Deploy - Gateway API na Contabo VPS

## 📋 Índice

1. [Decisões de Arquitetura](#decisões-de-arquitetura)
2. [Pré-requisitos](#pré-requisitos)
3. [Passo 1: Criar VPS na Contabo](#passo-1-criar-vps-na-contabo)
4. [Passo 2: Setup Inicial da VPS](#passo-2-setup-inicial-da-vps)
5. [Passo 3: Configurar Banco de Dados](#passo-3-configurar-banco-de-dados)
6. [Passo 4: Deploy do Código](#passo-4-deploy-do-código)
7. [Passo 5: Configurar Nginx](#passo-5-configurar-nginx)
8. [Passo 6: Configurar SSL/HTTPS](#passo-6-configurar-sslhttps)
9. [Passo 7: Configurar Queues](#passo-7-configurar-queues)
10. [Passo 8: Configurar Cron Jobs](#passo-8-configurar-cron-jobs)
11. [Troubleshooting](#troubleshooting)

---

## 🎯 Decisões de Arquitetura

### **Recomendação: Opção 1 - Tudo na Mesma VPS**

**VPS Recomendada:** Contabo VPS M
- 6 vCores
- 16GB RAM
- 400GB SSD
- ~€8.99/mês

**Stack:**
- Ubuntu 22.04 LTS
- Nginx
- PHP 8.2 + PHP-FPM
- MySQL 8.0
- Redis 7.0
- Supervisor (queues)

---

## ✅ Pré-requisitos

- [ ] VPS Contabo criada
- [ ] Acesso SSH configurado
- [ ] Domínio configurado (ex: api.seudominio.com.br)
- [ ] DNS apontando para IP da VPS
- [ ] Portas 22, 80, 443 liberadas no firewall

---

## 📝 Passo 1: Criar VPS na Contabo

1. Acesse: https://contabo.com/pt/vps/
2. Escolha: **VPS M** (6 vCores, 16GB RAM, 400GB SSD)
3. Sistema Operacional: **Ubuntu 22.04 LTS**
4. Região: Escolha a mais próxima do Brasil
5. Complete o pedido e aguarde o email de confirmação

**Anote:**
- IP da VPS: `XXX.XXX.XXX.XXX`
- Senha root inicial

---

## 🔧 Passo 2: Setup Inicial da VPS

### 2.1. Conectar via SSH

```bash
ssh root@SEU_IP_VPS
# Digite a senha quando solicitado
```

### 2.2. Executar Script de Setup

```bash
# Clonar repositório temporariamente para obter os scripts
cd /tmp
git clone git@github-coratribr:coratribr/gateway-api.git temp-repo
cd temp-repo

# Tornar scripts executáveis
chmod +x scripts/*.sh

# Executar setup inicial
sudo ./scripts/setup-vps.sh
```

**O que o script faz:**
- ✅ Atualiza o sistema
- ✅ Instala ferramentas básicas
- ✅ Configura firewall (UFW)
- ✅ Instala MySQL
- ✅ Instala Redis
- ✅ Instala PHP 8.2 + extensões
- ✅ Instala Composer
- ✅ Instala Nginx
- ✅ Instala Certbot (SSL)
- ✅ Instala Supervisor
- ✅ Cria usuário `gateway`
- ✅ Cria diretórios necessários

**Tempo estimado:** 10-15 minutos

### 2.3. Alterar Senha Root do MySQL

```bash
mysql -u root -ptemp_root_password
```

No MySQL:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'SUA_SENHA_SEGURA_AQUI';
FLUSH PRIVILEGES;
EXIT;
```

**⚠️ IMPORTANTE:** Anote essa senha! Você precisará dela no próximo passo.

---

## 🗄️ Passo 3: Configurar Banco de Dados

```bash
# Executar script de configuração do banco
sudo ./scripts/setup-database.sh
```

**Informações solicitadas:**
- Senha root do MySQL (que você acabou de criar)
- Nome do banco: `gateway_api` (ou outro de sua escolha)
- Usuário do banco: `gateway_user` (ou outro de sua escolha)
- Senha do usuário: (crie uma senha forte)

**Anote essas informações!** Você precisará delas para o arquivo `.env`.

---

## 📦 Passo 4: Deploy do Código

### 4.1. Clonar Repositório

```bash
# Como usuário gateway
sudo -u gateway git clone git@github-coratribr:coratribr/gateway-api.git /var/www/gateway-api
```

**Se der erro de SSH:**
- Configure a chave SSH no servidor ou use HTTPS:
```bash
sudo -u gateway git clone https://github.com/coratribr/gateway-api.git /var/www/gateway-api
```

### 4.2. Configurar Arquivo .env

```bash
cd /var/www/gateway-api
sudo -u gateway cp .env.example .env
sudo -u gateway nano .env
```

**Configure as seguintes variáveis:**

```env
APP_NAME="Gateway API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.seudominio.com.br

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gateway_api
DB_USERNAME=gateway_user
DB_PASSWORD=sua_senha_aqui

CACHE_DRIVER=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Fila: database (padrão) ou redis (melhor performance em alto volume)
QUEUE_CONNECTION=database
# Se usar redis para fila, descomente e reinicie os workers (Passo 7):
# QUEUE_CONNECTION=redis
# REDIS_QUEUE_CONNECTION=queue
# REDIS_QUEUE_DB=2

SESSION_DRIVER=redis
```

**Salve:** `Ctrl+O`, `Enter`, `Ctrl+X`

### 4.3. Executar Deploy

```bash
cd /var/www/gateway-api
sudo -u gateway ./scripts/deploy.sh main
```

**O que o script faz:**
- ✅ Atualiza código do Git
- ✅ Instala dependências do Composer
- ✅ Configura permissões
- ✅ Gera chave da aplicação
- ✅ Executa migrations
- ✅ Cria link simbólico do storage
- ✅ Limpa e otimiza cache
- ✅ Reinicia PHP-FPM

---

## 🌐 Passo 5: Configurar Nginx

```bash
cd /var/www/gateway-api
sudo ./scripts/nginx-config.sh
```

**Informações solicitadas:**
- Domínio: `api.seudominio.com.br` (seu domínio)
- Caminho da aplicação: `/var/www/gateway-api` (padrão)

**Verificar:**
```bash
# Testar configuração
sudo nginx -t

# Ver status
sudo systemctl status nginx
```

---

## 🔒 Passo 6: Configurar SSL/HTTPS

**⚠️ IMPORTANTE:** Certifique-se de que o DNS do domínio está apontando para o IP da VPS antes de continuar!

```bash
cd /var/www/gateway-api
sudo ./scripts/setup-ssl.sh
```

**Informações solicitadas:**
- Domínio: `api.seudominio.com.br`
- Email: `seu-email@exemplo.com`

**Verificar:**
```bash
# Testar SSL
curl -I https://api.seudominio.com.br

# Ver certificado
sudo certbot certificates
```

---

## 👷 Passo 7: Configurar Queues

```bash
cd /var/www/gateway-api
sudo ./scripts/setup-supervisor.sh
```

O script configura **4 workers** para a fila `webhooks` (PIX) e `default`. Confirme que os 4 processos estão ativos.

**Verificar:**
```bash
sudo supervisorctl status gateway-api-queue:*
```

**Fila com Redis (opcional, mais performance):** Se quiser usar Redis em vez de database para a fila (recomendado em alto volume), no `.env` defina `QUEUE_CONNECTION=redis` e `REDIS_QUEUE_CONNECTION=queue`. A aplicação usa o Redis DB 2 para a fila (cache em DB 1, sessão em DB 0). Depois **reinicie os workers**: `sudo supervisorctl restart gateway-api-queue:*`.

**Comandos úteis:**
```bash
# Ver logs
tail -f /var/www/gateway-api/storage/logs/queue-worker.log

# Reiniciar workers
sudo supervisorctl restart gateway-api-queue:*

# Parar workers
sudo supervisorctl stop gateway-api-queue:*
```

---

## ⏰ Passo 8: Configurar Cron Jobs

```bash
sudo crontab -u gateway -e
```

**Adicione:**

```cron
* * * * * cd /var/www/gateway-api && php artisan schedule:run >> /dev/null 2>&1
```

**Verificar:**
```bash
sudo crontab -u gateway -l
```

---

## ✅ Verificação Final

### Testar API

```bash
# Health check (se tiver rota)
curl https://api.seudominio.com.br/api/health

# Ver logs
tail -f /var/www/gateway-api/storage/logs/laravel.log
```

### Checklist

- [ ] API respondendo em HTTPS
- [ ] Banco de dados conectado
- [ ] Redis funcionando
- [ ] Queues rodando (Supervisor)
- [ ] Cron configurado
- [ ] SSL válido
- [ ] Logs sendo gerados

---

## 🔄 Atualizações Futuras

Para atualizar o código:

```bash
cd /var/www/gateway-api
sudo -u gateway ./scripts/deploy.sh main
```

---

## 🆘 Troubleshooting

### Erro: "Permission denied"
```bash
sudo chown -R gateway:www-data /var/www/gateway-api
sudo chmod -R 775 /var/www/gateway-api/storage
```

### Erro: "Class not found"
```bash
cd /var/www/gateway-api
sudo -u gateway composer dump-autoload
sudo -u gateway php artisan optimize:clear
```

### Erro: "Connection refused" no MySQL
```bash
sudo systemctl status mysql
sudo systemctl restart mysql
```

### Erro: "502 Bad Gateway"
```bash
sudo systemctl status php8.2-fpm
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

### Ver logs
```bash
# Laravel
tail -f /var/www/gateway-api/storage/logs/laravel.log

# Nginx
sudo tail -f /var/log/nginx/error.log

# PHP-FPM
sudo tail -f /var/log/php8.2-fpm.log
```

---

## 📞 Suporte

Em caso de problemas:
1. Verifique os logs
2. Verifique status dos serviços
3. Consulte a documentação do Laravel
4. Verifique configurações do .env

---

## 🎉 Pronto!

Seu Gateway API está rodando em produção! 🚀

**URL:** https://api.seudominio.com.br
