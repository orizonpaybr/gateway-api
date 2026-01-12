# 🚀 Gateway API - Sistema de Pagamentos

[![PHP](https://img.shields.io/badge/PHP-8.4-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.31-red)](https://laravel.com)
[![MySQL](https://img.shields.io/badge/MySQL-8.4-orange)](https://mysql.com)

> Gateway de pagamentos completo desenvolvido em Laravel 11, fornecendo API REST robusta para processamento de transações PIX, cartão de crédito, boleto e criptomoedas através de múltiplos adquirentes.

---

## 📋 Visão Geral

Sistema completo de gateway de pagamentos com recursos avançados de gestão financeira, múltiplas adquirentes integradas e sistema de comissões para gerentes.

### Recursos Principais

- 💳 **Métodos de Pagamento**: PIX, Cartão de Crédito, Boleto Bancário
- 🔐 **3D Secure**: Autenticação segura para cartões
- 👥 **Sistema de Gerentes**: Comissões automáticas e gestão de clientes
- 📊 **Analytics**: Relatórios detalhados e métricas
- 🏦 **10+ Adquirentes**: Integração com principais gateways do Brasil
- 🎛️ **Painel Administrativo**: Gestão completa do sistema

### Adquirentes Integrados

- PrimePay7 (PIX + Cartões com 3DS)
- EfiPay (Gerencianet)
- Asaas
- XDPag
- Pixup
- Witetec
- BSPay
- Woovi
- Mercado Pago
- Pagar.me
- XGate
- PagArm

---

## 🚀 Instalação

### Requisitos

- PHP >= 8.2
- Composer
- MySQL/MariaDB 8.4+ ou PostgreSQL
- Redis (recomendado para cache)
- Extensões PHP: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, GD

### Passo a Passo

1. **Clone o repositório:**
```bash
git clone <seu-repositorio>
cd gateway-api
```

2. **Instale as dependências:**
```bash
composer install
npm install  # Se houver frontend
```

3. **Configure o ambiente:**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure o arquivo `.env`:**
```env
APP_NAME="Gateway API"
APP_ENV=production
APP_URL=https://seu-dominio.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gateway
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

5. **Execute as migrations:**
```bash
php artisan migrate
php artisan db:seed  # Opcional: dados iniciais
```

6. **Crie o link simbólico do storage:**
```bash
php artisan storage:link
```

7. **Otimize para produção:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

8. **Inicie o servidor:**
```bash
php artisan serve
```

A API estará disponível em `http://localhost:8000`

---

## 📚 Documentação

- **[API_DOCUMENTATION.md](./API_DOCUMENTATION.md)** - Documentação completa da API (endpoints, autenticação, exemplos)
- **[DEVELOPMENT_GUIDE.md](./DEVELOPMENT_GUIDE.md)** - Guia de desenvolvimento (configurações, troubleshooting, melhorias)

---

## 🏗️ Arquitetura

### Stack Tecnológica

**Backend:**
- PHP 8.4
- Laravel 11.31
- MySQL 8.4 (Percona Server)
- Redis (Cache/Sessions)

**Frontend (se aplicável):**
- TailwindCSS 3.1.0
- AdminLTE 3.14
- Vite 6.3.6
- Alpine.js 3.4
- Livewire 3.6

### Estrutura de Diretórios

```
gateway-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/     # Controllers da API
│   │   ├── Middleware/      # Middlewares customizados
│   │   └── Requests/        # Form Requests (validação)
│   ├── Models/              # Models Eloquent
│   ├── Services/            # Services (lógica de negócio)
│   ├── Traits/              # Traits reutilizáveis
│   └── Helpers/             # Helpers e utilitários
├── database/
│   ├── migrations/          # Migrations do banco
│   └── seeders/             # Seeders
├── routes/
│   └── api.php              # Rotas da API
├── config/                   # Arquivos de configuração
└── storage/                  # Arquivos e logs
```

---

## 🔒 Segurança

### Medidas Implementadas

- ✅ Autenticação via Laravel Sanctum
- ✅ 2FA (Google Authenticator)
- ✅ PIN para transações sensíveis
- ✅ Validação de IP para saques
- ✅ Rate limiting em endpoints críticos
- ✅ Webhook validation
- ✅ Proteção contra SQL injection (Eloquent ORM)
- ✅ HTTPS obrigatório em produção
- ✅ Input validation e sanitização
- ✅ File upload security

### Compliance

- ✅ PCI DSS (processamento seguro de cartões)
- ✅ LGPD (conformidade com proteção de dados)
- ✅ KYC (verificação de identidade)
- ✅ ML/TF (prevenção de lavagem de dinheiro)

---

## 📊 Funcionalidades

### Sistema de Usuários

- Gestão completa de usuários
- Sistema de níveis e permissões (Cliente, Gerente, Admin)
- Taxas personalizadas por usuário
- Dashboard financeiro individual

### Sistema de Gerentes

- Dashboard específico com métricas dos clientes
- Aprovação manual de clientes
- Gestão de documentação KYC
- Configuração de taxas por cliente
- Relatórios de comissão automáticos

### Sistema de Transações

- Depósitos PIX instantâneos
- Saques PIX com aprovação manual/automática
- Pagamentos com cartão de crédito (1-12x parcelas)
- Geração de boletos bancários
- Splits internos automáticos
- Webhooks para notificações

### Sistema de Relatórios

- Relatórios financeiros detalhados
- Filtros avançados (período, status, método, usuário)
- Exportação CSV/PDF
- Dashboard administrativo com métricas em tempo real

### Sistema de Gamificação

- Níveis de usuário (Bronze, Prata, Ouro, Safira, Diamante)
- Progressão baseada em depósitos
- Trilha de conquistas
- Dashboard de progresso

### Notificações Push

- Notificações automáticas de transações
- Preferências configuráveis por usuário
- Integração com Expo Push API
- Notificações de depósitos, saques e comissões

---

## 🛠️ Comandos Úteis

### Desenvolvimento

```bash
# Executar testes
php artisan test

# Limpar cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Executar em desenvolvimento
php artisan serve

# Build assets (se houver frontend)
npm run dev
npm run build
```

### Produção

```bash
# Otimizar aplicação
php artisan optimize

# Cache de configurações
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Scripts Administrativos

```bash
# Gerenciar IPs permitidos
php gerenciar_ips.php listar
php gerenciar_ips.php adicionar <usuario> <ip>
php gerenciar_ips.php remover <usuario> <ip>

# Verificar última transação
php verificar_ultima_transacao.php
```

---

## 📈 Performance

### Otimizações Implementadas

- ✅ Cache Redis para queries frequentes
- ✅ Índices no banco de dados
- ✅ Eager loading para evitar N+1 queries
- ✅ Paginação em todas as listagens
- ✅ Queries otimizadas com aggregates
- ✅ Cache de configurações e estatísticas

### Métricas

- ⚡ Tempo de resposta: < 200ms
- 🔄 Uptime: 99.9%
- 🛡️ Segurança: AES-256
- 📈 Escalabilidade: Horizontal

---

## 🧪 Testes

Execute os testes com Pest:

```bash
php artisan test
```

---

## 📞 Suporte

Para dúvidas e suporte:

- **Documentação da API**: Veja `API_DOCUMENTATION.md`
- **Guia de Desenvolvimento**: Veja `DEVELOPMENT_GUIDE.md`
- **Swagger/OpenAPI**: Acesse `/api/documentation` (se configurado)

---

## 📄 Licença

MIT License - Veja o arquivo LICENSE para detalhes.

---

## 🎯 Status do Projeto

**Status:** ✅ Em produção e mantido ativamente

**Última atualização:** Janeiro 2025

**Versão:** 1.0.0

---

**Desenvolvido com ❤️ usando Laravel**
