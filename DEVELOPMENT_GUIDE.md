# 🛠️ Guia de Desenvolvimento

> Guia completo para desenvolvimento, configuração, troubleshooting e melhorias do Gateway API

---

## 📋 Índice

1. [Configuração de Adquirentes](#configuração-de-adquirentes)
2. [Troubleshooting](#troubleshooting)
3. [Melhorias e Otimizações](#melhorias-e-otimizações)
4. [Sistemas Implementados](#sistemas-implementados)
5. [Boas Práticas](#boas-práticas)

---

## 🔧 Configuração de Adquirentes

### PagArm

#### Checklist de Credenciais Necessárias

- ✅ **Client ID** - ID do cliente fornecido pelo PagArm
- ✅ **Client Secret** - Secret do cliente fornecido pelo PagArm
- ✅ **API Key** - Chave de API para autenticação
- ✅ **Webhook Secret** - Secret para validar webhooks
- ✅ **Ambiente** - Sandbox ou Production

#### Configuração no .env

```env
# Configurações do PagArm
PAGARM_BASE_URL=https://sandbox-api.pagarm.com.br/v1
PAGARM_CLIENT_ID=seu_client_id_aqui
PAGARM_CLIENT_SECRET=seu_client_secret_aqui
PAGARM_API_KEY=sua_api_key_aqui
PAGARM_ENVIRONMENT=sandbox
PAGARM_WEBHOOK_SECRET=seu_webhook_secret_aqui
PAGARM_MERCHANT_ID=
PAGARM_ACCOUNT_ID=
```

#### Passos de Configuração

1. **Adicionar variáveis ao `.env`** (veja acima)
2. **Executar migrations:**
```bash
php artisan migrate
```

3. **Configurar webhooks no dashboard PagArm:**
   - Depósitos: `https://seudominio.com.br/api/pagarm/callback/deposit`
   - Saques: `https://seudominio.com.br/api/pagarm/callback/withdraw`

4. **Ativar PagArm no sistema:**
```bash
php artisan tinker
$pagarm = App\Models\PagArm::first();
$pagarm->status = true;
$pagarm->save();
exit
```

5. **Limpar cache:**
```bash
php artisan config:clear
php artisan cache:clear
```

#### Taxas do PagArm

- **Entradas (PIX IN)**: 0,50%
- **Saídas (PIX OUT)**: 0,50%

---

## 🐛 Troubleshooting

### Erro: "Usuário não autenticado"

**Causas comuns:**

1. **Token JWT não enviado no header correto**
   - ❌ ERRADO: Sem header Authorization
   - ✅ CORRETO: `Authorization: Bearer {token}`

2. **Token expirado**
   - Solução: Fazer login novamente para obter novo token

3. **Formato do token incorreto**
   - O token deve ser um JWT válido retornado pelo endpoint de login

**Como resolver:**

1. Verificar o header:
```
Authorization: Bearer {{ token }}
```

2. Fazer login novamente:
```bash
POST /api/auth/login
{
  "username": "admin",
  "password": "senha"
}
```

3. Verificar o token:
```bash
GET /api/auth/verify
Authorization: Bearer {token}
```

**Rotas que usam VerifyJWT:**
- `/api/notifications`
- `/api/balance`
- `/api/user/profile`
- `/api/transactions`
- `/api/dashboard/stats`

### Erro: "PagArm não configurado ou inativo"

**Solução:** Ative o PagArm via tinker (veja seção de configuração acima)

### Erro: "Webhook secret inválido"

**Solução:**
1. Verifique se `PAGARM_WEBHOOK_SECRET` no `.env` corresponde ao configurado no dashboard
2. Execute: `php artisan config:clear`

### Erro: "Erro ao gerar token PagArm"

**Solução:**
1. Verifique se as credenciais no `.env` estão corretas
2. Confirme se está usando o ambiente correto (sandbox vs production)
3. Verifique logs: `tail -f storage/logs/laravel.log | grep "PagArmService"`

### Webhooks não estão sendo recebidos

**Solução:**
1. Teste se o endpoint está acessível: `curl -X POST https://seudominio.com.br/api/pagarm/callback/deposit`
2. Verifique se o servidor tem SSL válido (https)
3. Confirme se a URL no dashboard PagArm está correta
4. Verifique firewall/whitelist de IPs

### Preferências de notificação não salvam

**Solução:**
1. Verificar se Redis está rodando: `redis-cli ping`
2. Verificar logs: `tail -f storage/logs/laravel.log`
3. Limpar cache: `php artisan cache:clear`
4. Verificar credenciais no front-end (token/secret)

### Notificações não respeitam preferências

**Solução:**
1. Verificar se migration foi executada: `php artisan migrate:status`
2. Limpar cache do Redis: `redis-cli FLUSHDB`
3. Verificar logs dos Observers: `grep "OBSERVER" storage/logs/laravel.log`

---

## ⚡ Melhorias e Otimizações

### Performance

#### Cache Redis

O sistema usa Redis para cache de:
- Estatísticas do dashboard (TTL: 5-10 minutos)
- Dados de usuários (TTL: 1 hora)
- Configurações globais (TTL: 10 minutos)
- Preferências de notificação (TTL: 1 hora)

**Verificar cache:**
```bash
redis-cli
KEYS notif_pref:*
GET notif_pref:username123
TTL notif_pref:username123
```

#### Otimizações de Queries

**N+1 Query Problem - Resolvido:**
- Antes: 40+ queries para 20 usuários
- Depois: 2-3 queries (independente do número de usuários)

**Índices adicionados:**
```sql
-- Busca de usuários
CREATE INDEX idx_users_search ON users(name, email, username);

-- Vendas 7 dias
CREATE INDEX idx_solicitacoes_user_status_date ON solicitacoes(user_id, status, date);

-- Adquirentes
CREATE INDEX idx_adquirentes_status_ref ON adquirentes(status, referencia);
```

#### Query Analyzer

Helper para análise de queries em desenvolvimento:

```php
use App\Helpers\QueryAnalyzer;

$query = User::where('saldo', '>', 0)->orderBy('saldo', 'desc');
$analysis = QueryAnalyzer::analyze($query);
```

#### Log de Queries Lentas

Middleware que loga automaticamente queries que demoram mais de 1 segundo:

**Ativar em `bootstrap/app.php`:**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\LogSlowQueries::class);
})
```

### Clean Code

#### Padrões Implementados

- ✅ **Service Layer Pattern**: Lógica de negócio em Services
- ✅ **Form Request Validation**: Validação centralizada
- ✅ **Dependency Injection**: Injeção de dependências via constructor
- ✅ **Repository Pattern**: Abstração de acesso a dados (quando aplicável)
- ✅ **Event-Driven Architecture**: Eventos e Listeners para side effects
- ✅ **API Resources**: Formatação consistente de respostas

#### Constants e Enums

Magic numbers foram substituídos por constants:

```php
// Antes
'status' => $data['status'] ?? 5;

// Depois
use App\Constants\UserStatus;
'status' => $data['status'] ?? UserStatus::PENDING;
```

**Constants disponíveis:**
- `UserStatus` - Status de usuários
- `UserPermission` - Permissões de usuários

#### Cache Keys Centralizadas

Todas as cache keys são gerenciadas pelo `CacheKeyService`:

```php
use App\Services\CacheKeyService;

$cacheKey = CacheKeyService::adminUser($userId, true);
Cache::forget($cacheKey);
```

### Segurança

#### Validação de Entrada

- ✅ Form Requests para validação robusta
- ✅ Sanitização de dados de busca
- ✅ Validação de tipos e valores permitidos
- ✅ Proteção contra SQL injection (Eloquent ORM)

#### Middleware de Autorização

Middleware `EnsureAdminPermission` para verificação de admin:

```php
Route::middleware(['ensure.admin'])->group(function () {
    Route::get('admin/dashboard/stats', ...);
});
```

---

## 🎯 Sistemas Implementados

### Sistema de Aprovação de Saques

Sistema completo de aprovação manual e automática de saques.

**Funcionalidades:**
- Dashboard com estatísticas em tempo real
- Filtros por status, tipo e data
- Aprovação/rejeição manual
- Exportação para Excel
- Modal de detalhes completo

**Acesso:**
- URL: `/dashboard/admin/aprovar-saques`
- Permissão: Administrador (permission = 3)

**Endpoints:**
- `GET /api/admin/withdrawals` - Listar saques
- `GET /api/admin/withdrawals/{id}` - Detalhes
- `POST /api/admin/withdrawals/{id}/approve` - Aprovar
- `POST /api/admin/withdrawals/{id}/reject` - Rejeitar
- `GET /api/admin/withdrawals/stats` - Estatísticas

### Sistema de Gamificação

Sistema de níveis baseado em depósitos.

**Níveis:**
- Bronze
- Prata
- Ouro
- Safira
- Diamante

**Funcionalidades:**
- Dashboard admin para editar níveis
- Visualização de progresso do usuário
- Trilha de conquistas
- Próxima meta calculada dinamicamente

**Endpoints:**
- `GET /api/admin/levels` - Listar níveis
- `PUT /api/admin/levels/{id}` - Atualizar nível
- `GET /api/user/level` - Nível atual do usuário

### Sistema de Notificações Push

Sistema completo de notificações push com preferências de usuário.

**Funcionalidades:**
- Notificações automáticas de transações
- Preferências configuráveis por usuário
- Cache Redis para performance
- Integração com Expo Push API

**Endpoints:**
- `POST /api/notifications/register-token` - Registrar token
- `GET /api/notifications` - Listar notificações
- `POST /api/notifications/{id}/read` - Marcar como lida
- `GET /api/notification-preferences` - Obter preferências
- `PUT /api/notification-preferences` - Atualizar preferências

**Testar notificação:**
```bash
php artisan notifications:test {user_id} --type=deposit --amount=100.00
```

### Sistema de Armazenamento de Arquivos

**Como funciona:**
- Arquivos são salvos em `storage/app/public/uploads/`
- Banco armazena apenas o caminho (`/storage/uploads/documentos/arquivo.png`)
- Symlink criado: `public/storage -> storage/app/public`

**Configuração:**
```bash
php artisan storage:link
```

**Arquivos não aparecem no Git:**
- `.gitignore` configurado para ignorar `storage/app/public/uploads/`

---

## 📊 Monitoramento

### Métricas de Cache Redis

Endpoint para verificar métricas de cache:

```bash
GET /api/admin/dashboard/cache-metrics
```

**Resposta:**
```json
{
  "success": true,
  "data": {
    "general": {
      "redis_connected": true,
      "hit_rate": 95.24,
      "used_memory_human": "2.5MB"
    },
    "financial": {
      "total_financial_keys": 15
    }
  }
}
```

### Logs

**Verificar logs do Laravel:**
```bash
tail -f storage/logs/laravel.log
```

**Filtrar por tipo:**
```bash
# Notificações
tail -f storage/logs/laravel.log | grep -i notification

# PagArm
tail -f storage/logs/laravel.log | grep -i pagarm

# Erros
tail -f storage/logs/laravel.log | grep -i error

# Queries lentas
tail -f storage/logs/laravel.log | grep "Query lenta detectada"
```

### Verificar Transações

**Via SQL:**
```sql
-- Depósitos PagArm
SELECT * FROM solicitacoes
WHERE adquirente = 'pagarm'
ORDER BY created_at DESC
LIMIT 50;

-- Saques PagArm
SELECT * FROM solicitacoes_cash_out
WHERE adquirente = 'pagarm'
ORDER BY created_at DESC
LIMIT 50;
```

---

## ✅ Checklist de Qualidade

### Clean Code
- [x] Nomes descritivos
- [x] Funções pequenas e focadas
- [x] Sem código duplicado (DRY)
- [x] PHPDoc completo

### Performance
- [x] Cache Redis implementado
- [x] Queries otimizadas
- [x] Eager loading para evitar N+1
- [x] Índices no banco de dados
- [x] Select específico (não SELECT *)

### Segurança
- [x] Validação de entrada
- [x] Sanitização de dados
- [x] Autenticação/autorização
- [x] SQL injection prevention
- [x] XSS prevention

### Manutenibilidade
- [x] Service Layer Pattern
- [x] Separação de responsabilidades
- [x] Constants para valores mágicos
- [x] Logging estruturado
- [x] Tratamento de erros consistente

### Type Safety
- [x] Type hints em métodos públicos
- [x] Type hints em métodos privados
- [x] Return types explícitos

---

## 🚀 Próximos Passos Recomendados

### Curto Prazo
- [ ] Implementar testes unitários
- [ ] Adicionar API documentation (Swagger)
- [ ] Implementar rate limiting por endpoint

### Médio Prazo
- [ ] Adicionar monitoring (Sentry, Bugsnag)
- [ ] Implementar feature flags
- [ ] Dashboard de analytics de notificações

### Longo Prazo
- [ ] Implementar notificações agendadas
- [ ] Adicionar webhooks para eventos de notificação
- [ ] Implementar testes automatizados (PHPUnit + Jest)

---

## 📚 Referências

- [Laravel Best Practices](https://github.com/alexeymezenin/laravel-best-practices)
- [SOLID Principles](https://laravel-news.com/solid-principles)
- [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)
- [Laravel Form Request Validation](https://laravel.com/docs/validation#form-request-validation)
- [Redis Caching Best Practices](https://redis.io/docs/manual/patterns/)

---

**Última atualização:** Janeiro 2025
