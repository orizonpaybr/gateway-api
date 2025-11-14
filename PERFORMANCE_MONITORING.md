# 📊 Guia de Monitoramento de Performance

Este documento explica como usar as ferramentas de monitoramento e otimização implementadas.

## ✅ Implementações Realizadas

### 1. **Observer para Cache Automático** ✅

**Arquivo:** `app/Observers/UserObserver.php`

**O que faz:**
- Invalida cache automaticamente quando saldo ou dados financeiros são atualizados
- Mantém consistência de dados sem intervenção manual

**Como funciona:**
```php
// Quando você atualiza um usuário:
$user->saldo = 1000;
$user->save(); // ✅ Cache é invalidado automaticamente!
```

**Campos monitorados:**
- `saldo`
- `total_transacoes`
- `valor_sacado`
- `status`

**Status:** ✅ **IMPLEMENTADO E ATIVO**

---

### 2. **Query Analyzer Helper** ✅

**Arquivo:** `app/Helpers/QueryAnalyzer.php`

**Uso para análise de queries:**

```php
use App\Helpers\QueryAnalyzer;

// Analisar uma query
$query = User::where('saldo', '>', 0)->orderBy('saldo', 'desc');
$analysis = QueryAnalyzer::analyze($query);

// Resultado:
// [
//     'sql' => 'select * from users where saldo > ? order by saldo desc',
//     'warnings' => [...],
//     'suggestions' => [...]
// ]

// Executar EXPLAIN manualmente
$explain = QueryAnalyzer::explain($query);
```

**Uso em desenvolvimento:**

```php
// No método do Service ou Controller
public function getWallets(array $filters): array
{
    $query = User::query()->where('saldo', '>', 0);
    
    // Em desenvolvimento, analisar a query
    if (app()->environment('local')) {
        $analysis = QueryAnalyzer::analyze($query);
        if (!empty($analysis['warnings'])) {
            Log::info('Análise de query', $analysis);
        }
    }
    
    return $query->get();
}
```

**Status:** ✅ **IMPLEMENTADO - PRONTO PARA USO**

---

### 3. **Middleware para Log de Queries Lentas** ✅

**Arquivo:** `app/Http/Middleware/LogSlowQueries.php`

**Como ativar:**

**Opção 1: Global (todas as rotas)**
```php
// bootstrap/app.php (Laravel 11)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\LogSlowQueries::class);
})
```

**Opção 2: Apenas rotas de API**
```php
// routes/api.php
Route::middleware([LogSlowQueries::class])->group(function () {
    // suas rotas
});
```

**O que faz:**
- Monitora todas as queries executadas
- Loga automaticamente queries que demoram mais de 1 segundo
- Inclui SQL, bindings, tempo e contexto da requisição

**Exemplo de log:**
```
[2025-01-20 10:30:45] WARNING: Query lenta detectada
{
    "sql": "select * from users where saldo > ? order by saldo desc",
    "bindings": [0],
    "time_ms": 1250,
    "url": "https://api.example.com/admin/financial/wallets",
    "method": "GET"
}
```

**Status:** ✅ **IMPLEMENTADO - PRECISA SER ATIVADO**

---

## 🚀 Como Usar

### Ativar Monitoramento de Queries Lentas

1. **Edite `bootstrap/app.php`** (Laravel 11):
```php
use App\Http\Middleware\LogSlowQueries;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        // Adicionar middleware de monitoramento
        $middleware->append(LogSlowQueries::class);
    })
    // ... resto da configuração
```

2. **Ou adicione apenas em rotas específicas:**
```php
// routes/api.php
use App\Http\Middleware\LogSlowQueries;

Route::middleware([LogSlowQueries::class])
    ->prefix('admin/financial')
    ->group(function () {
        Route::get('wallets', [FinancialController::class, 'getWallets']);
    });
```

### Usar Query Analyzer

```php
use App\Helpers\QueryAnalyzer;

// Em qualquer Service ou Controller
public function getWallets(array $filters): array
{
    $query = User::query()
        ->where('saldo', '>', 0)
        ->orderBy('saldo', 'desc');
    
    // Analisar query (apenas em desenvolvimento)
    if (config('app.debug')) {
        $analysis = QueryAnalyzer::analyze($query);
        if (!empty($analysis['warnings'])) {
            Log::info('Query Analysis', $analysis);
        }
    }
    
    return $query->get();
}
```

---

## 📈 Métricas de Cache Redis ✅

**Status:** ✅ **IMPLEMENTADO**

**Arquivo:** `app/Services/CacheMetricsService.php`

**O que faz:**
- Coleta métricas do Redis (hit/miss, memória, comandos)
- Conta chaves de cache do sistema
- Calcula taxa de acerto (hit rate)
- Métricas específicas de cache financeiro

**Endpoint:** `GET /api/admin/dashboard/cache-metrics`

**Resposta:**
```json
{
  "success": true,
  "data": {
    "general": {
      "redis_connected": true,
      "total_commands_processed": 12345,
      "keyspace_hits": 10000,
      "keyspace_misses": 500,
      "used_memory_human": "2.5MB",
      "hit_rate": 95.24
    },
    "financial": {
      "total_financial_keys": 15,
      "wallets_keys": 5,
      "stats_keys": 10
    }
  }
}
```

**Como integrar no painel admin:**

**NÃO precisa de dashboard separado!** As métricas podem ser exibidas no **Dashboard Admin existente**.

**Opções de integração:**

1. **Adicionar cards no Dashboard Admin atual:**
   - Card "Taxa de Acerto do Cache" (hit_rate)
   - Card "Memória Redis Usada" (used_memory_human)
   - Card "Total de Chaves" (cache_keys_count)

2. **Criar seção "Métricas de Performance" no Dashboard:**
   - Adicionar uma nova seção no Dashboard Admin
   - Exibir métricas em tempo real
   - Atualizar a cada 30-60 segundos

3. **Usar apenas logs (mais simples):**
   - As métricas já estão disponíveis via endpoint
   - Pode ser consultado manualmente quando necessário
   - Ou criar um script de monitoramento

**Endpoint disponível:**
```
GET /api/admin/dashboard/cache-metrics
```

**Exemplo de uso no front-end:**
```typescript
// No Dashboard Admin
const { data: cacheMetrics } = useQuery({
  queryKey: ['admin-cache-metrics'],
  queryFn: () => api.get('/admin/dashboard/cache-metrics'),
  refetchInterval: 60000, // Atualizar a cada 60s
});
```

---

## 🎯 Resumo

| Implementação | Status | Dificuldade | Impacto |
|--------------|--------|-------------|---------|
| **Observer para Cache** | ✅ Ativo | Fácil | ⭐⭐⭐ Alto |
| **Query Analyzer** | ✅ Pronto | Fácil | ⭐⭐ Médio |
| **Log Queries Lentas** | ✅ Ativo | Fácil | ⭐⭐⭐ Alto |
| **Métricas Cache Redis** | ✅ Implementado | Fácil | ⭐⭐⭐ Alto |

---

## 💡 Dicas

1. **Observer já está ativo** - Não precisa fazer nada, funciona automaticamente ✅
2. **Query Analyzer** - Use em desenvolvimento para otimizar queries ✅
3. **Log Queries Lentas** - Já está ativo, monitora automaticamente ✅
4. **Métricas de Cache** - Endpoint disponível, pode integrar no Dashboard Admin ✅

---

## 🔍 Verificar se está funcionando

### Observer:
```php
// Atualizar saldo de um usuário
$user = User::find(1);
$user->saldo = 5000;
$user->save();

// Verificar logs - deve aparecer:
// "Campo financeiro alterado no User"
// Cache deve ser invalidado automaticamente
```

### Query Analyzer:
```php
// Em tinker ou controller
use App\Helpers\QueryAnalyzer;
$query = User::where('saldo', '>', 0);
$result = QueryAnalyzer::analyze($query);
dd($result);
```

### Log Queries Lentas:
- ✅ Já está ativo automaticamente
- Faça uma requisição lenta
- Verifique `storage/logs/laravel.log` por "Query lenta detectada"

### Métricas de Cache:
```bash
# Testar endpoint
curl -H "Authorization: Bearer {token}" \
  http://localhost:8000/api/admin/dashboard/cache-metrics

# Ou no front-end
GET /api/admin/dashboard/cache-metrics
```

