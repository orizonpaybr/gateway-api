# Gateway Backend - API REST

![Laravel](https://img.shields.io/badge/Laravel-11.31-red?style=flat-square&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-blue?style=flat-square&logo=php)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

> Sistema de Gateway de Pagamentos - Backend API puro (Front-end removido)

## 📋 Sobre

Este é um gateway de pagamentos completo desenvolvido em Laravel 11, fornecendo uma API REST robusta para processamento de transações PIX, cartão de crédito, boleto e criptomoedas através de múltiplos adquirentes.

**Status:** ✅ Limpo e auditado para segurança (09/10/2025)

## ⚡ Recursos Principais

### 💳 Métodos de Pagamento

-   **PIX** - Depósitos e saques
-   **Cartão de Crédito** - Processamento completo
-   **Boleto Bancário** - Geração e validação
-   **Criptomoedas** - Integração via gateways

### 🔌 Adquirentes Integrados

-   Pixup
-   BSPay
-   Asaas
-   PrimePay7
-   XDPag
-   Woovi
-   Mercado Pago
-   Pagar.me
-   Efi (Gerencianet)
-   XGate
-   Witetec

### 🔒 Segurança

-   ✅ Autenticação via Laravel Sanctum
-   ✅ 2FA (Google Authenticator)
-   ✅ PIN para transações sensíveis
-   ✅ Validação de IP para saques
-   ✅ Rate limiting em endpoints críticos
-   ✅ Webhook validation
-   ✅ Proteção contra SQL injection (Eloquent ORM)

### 📊 Funcionalidades

-   Gestão completa de usuários
-   Sistema de níveis e permissões
-   Taxas personalizadas por usuário
-   Sistema de afiliados com comissões
-   Relatórios financeiros detalhados
-   Splits internos automáticos
-   Gestão de carteiras
-   Webhooks para notificações

## 🚀 Instalação

### Requisitos

-   PHP >= 8.2
-   Composer
-   MySQL/MariaDB ou PostgreSQL
-   Extensões PHP: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, GD

### Passo a Passo

1. **Clone o repositório:**

```bash
git clone <seu-repositorio>
cd gateway-backend
```

2. **Instale as dependências:**

```bash
composer install
```

3. **Configure o ambiente:**

```bash
cp env_example.txt .env
```

Edite o `.env` e configure:

-   Banco de dados
-   URL da aplicação
-   Credenciais dos adquirentes
-   Configurações de email
-   Outras variáveis necessárias

4. **Gere a chave da aplicação:**

```bash
php artisan key:generate
```

5. **Execute as migrations:**

```bash
php artisan migrate
```

6. **Crie o link simbólico do storage:**

```bash
php artisan storage:link
```

7. **Inicie o servidor:**

```bash
php artisan serve
```

A API estará disponível em `http://localhost:8000`

## 📚 Documentação da API

### Swagger UI

Acesse a documentação interativa da API:

```
http://localhost:8000/api/documentation
```

### OpenAPI Specs

Os arquivos de especificação estão disponíveis:

-   `openapi.yaml` - Formato YAML
-   `openapi.json` - Formato JSON
-   `openapi-simple.yaml` - Versão simplificada
-   `openapi-simple.json` - Versão simplificada JSON

### Principais Endpoints

#### Autenticação

```
POST   /api/auth/login              - Login de usuário
POST   /api/auth/verify-2fa         - Verificar código 2FA
POST   /api/auth/logout             - Logout
GET    /api/auth/verify             - Verificar token
```

#### Transações

```
GET    /api/balance                 - Obter saldo
GET    /api/transactions            - Listar transações
GET    /api/transactions/{id}       - Detalhes da transação
POST   /api/wallet/deposit/payment  - Criar depósito PIX
POST   /api/pixout                  - Criar saque PIX
POST   /api/card/payment            - Pagamento com cartão
POST   /api/billet/charge           - Gerar boleto
```

#### Usuário

```
GET    /api/user/profile            - Perfil do usuário
GET    /api/statement               - Extrato detalhado
POST   /api/pix/generate-qr         - Gerar QR Code PIX
```

#### Callbacks (Webhooks)

```
POST   /api/pixup/callback/deposit      - Callback Pixup depósito
POST   /api/bspay/callback/withdraw     - Callback BSPay saque
POST   /api/asaas/callback/deposit      - Callback Asaas
POST   /api/primepay7/callback          - Callback PrimePay7 unificado
POST   /api/xdpag/callback/deposit      - Callback XDPag
POST   /api/woovi/callback              - Callback Woovi
```

## 🔑 Autenticação

A API usa **Laravel Sanctum** para autenticação.

### Fluxo de Autenticação:

1. **Login:**

```bash
POST /api/auth/login
Content-Type: application/json

{
  "username": "seu_usuario",
  "password": "sua_senha"
}
```

Se 2FA estiver ativado, retornará um `temp_token`.

2. **Verificar 2FA (se necessário):**

```bash
POST /api/auth/verify-2fa
Content-Type: application/json

{
  "temp_token": "token_temporario",
  "code": "123456"
}
```

3. **Usar o token:**

```bash
GET /api/balance
Authorization: Bearer SEU_TOKEN_AQUI
X-User-Secret: SUA_SECRET_KEY
```

### Chaves de API

Para transações, você precisa de duas chaves:

-   **Bearer Token** - Obtido no login
-   **X-User-Secret** - Chave secreta do usuário (gerada no cadastro)

## 🛡️ Segurança

### Validação de IP para Saques

Configure IPs permitidos para saques:

```bash
php gerenciar_ips.php adicionar usuario 192.168.1.100
php gerenciar_ips.php listar
```

### PIN de Transação

Usuários podem configurar um PIN adicional para transações críticas.

### Rate Limiting

Os endpoints possuem limitação de requisições:

-   Transações: 60 req/min
-   Saques: 30 req/min
-   Callbacks: 30 req/min
-   Boletos: 5 req/min

## 📊 Estrutura do Banco de Dados

O projeto possui 96 migrations organizadas:

-   Usuários e autenticação
-   Transações (depósitos e saques)
-   Adquirentes e configurações
-   Splits e comissões
-   Sistema de afiliados
-   Notificações push
-   Logs e auditoria

## 🔧 Scripts Administrativos

### Gerenciar IPs Permitidos

```bash
php gerenciar_ips.php listar
php gerenciar_ips.php adicionar <usuario> <ip>
php gerenciar_ips.php remover <usuario> <ip>
```

### Verificar Transações

```bash
php verificar_ultima_transacao.php
```

### Comandos Artisan Personalizados

```bash
php artisan list
```

## 🧪 Testes

Execute os testes com Pest:

```bash
php artisan test
```

## 📝 Variáveis de Ambiente Importantes

```env
# Aplicação
APP_NAME="Gateway API"
APP_ENV=production
APP_KEY=
APP_URL=https://seu-dominio.com

# Banco de Dados
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gateway
DB_USERNAME=root
DB_PASSWORD=

# Adquirentes (exemplo)
PIXUP_API_URL=
PIXUP_API_KEY=
PIXUP_WEBHOOK_TOKEN=

BSPAY_API_URL=
BSPAY_API_KEY=

ASAAS_API_KEY=
ASAAS_ENVIRONMENT=sandbox

# Email
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=

# 2FA
GOOGLE2FA_ENABLED=true
```

## 📦 Deploy

### Recomendações de Produção:

1. Use HTTPS (SSL/TLS)
2. Configure firewall adequadamente
3. Use cache Redis/Memcached
4. Configure queue workers
5. Habilite logs detalhados
6. Configure backup automático
7. Use supervisor para processos

### Otimização:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

## 🤝 Suporte

Para dúvidas e suporte:

-   Documentação: `/api/documentation`
-   OpenAPI: `openapi.yaml`

## 📄 Licença

MIT License - Veja o arquivo LICENSE para detalhes.

## 🔍 Análise de Segurança

Este projeto passou por auditoria completa de segurança. Veja o relatório completo em:
`RELATORIO_ANALISE_SEGURANCA_E_LIMPEZA.md`

**Status:** ✅ Aprovado - Nenhuma vulnerabilidade crítica encontrada

---

**Desenvolvido com ❤️ usando Laravel**
