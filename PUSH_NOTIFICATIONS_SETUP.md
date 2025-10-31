# 🔔 Sistema de Push Notifications - Guia Completo

Sistema completo de notificações push com preferências de usuário, cache Redis e integração front-end/back-end otimizada.

---

## 📋 O que foi implementado

### ✅ Backend (Laravel)

1. **Banco de Dados**
   - ✅ Tabela `notification_preferences` com campos:
     - `push_enabled` - Habilitar/desabilitar push
     - `notify_transactions` - Notificações de transações
     - `notify_deposits` - Notificações de depósitos
     - `notify_withdrawals` - Notificações de saques
     - `notify_security` - Notificações de segurança
     - `notify_system` - Notificações do sistema

2. **Models e Services**
   - ✅ Model `NotificationPreference` com cache automático
   - ✅ Service `NotificationPreferenceService` com Redis cache
   - ✅ Integração com sistema de push existente

3. **API Endpoints**
   ```
   GET    /api/notification-preferences          - Obter preferências
   PUT    /api/notification-preferences          - Atualizar preferências
   POST   /api/notification-preferences/toggle/{type} - Alternar preferência
   POST   /api/notification-preferences/disable-all   - Desabilitar todas
   POST   /api/notification-preferences/enable-all    - Habilitar todas
   ```

4. **Rate Limiting**
   - ✅ 60 requisições/minuto para endpoints de notificações
   - ✅ 30 requisições/minuto para preferências

5. **Observers Atualizados**
   - ✅ `SolicitacoesObserver` - Respeita preferências de depósito
   - ✅ `SolicitacoesCashOutObserver` - Respeita preferências de saque

### ✅ Frontend (Next.js + React Query)

1. **API Client**
   - ✅ Funções tipadas para todas as operações
   - ✅ Error handling completo
   - ✅ TypeScript interfaces

2. **Hook Customizado**
   - ✅ `useNotificationSettings` com React Query
   - ✅ Cache otimizado (5 min stale, 10 min gc)
   - ✅ Optimistic updates
   - ✅ Retry automático

3. **Componente UI**
   - ✅ `ConfiguracoesNotificacoesTab` totalmente funcional
   - ✅ Estados de loading e erro
   - ✅ UI responsiva e acessível
   - ✅ WhatsApp e Email removidos

---

## 🚀 Como Instalar

### 1. Backend (Laravel)

#### Passo 1: Executar Migration
```bash
cd gateway-backend
php artisan migrate
```

#### Passo 2: Verificar Redis
Certifique-se que Redis está rodando:
```bash
redis-cli ping
# Deve retornar: PONG
```

#### Passo 3: Limpar Cache (opcional)
```bash
php artisan cache:clear
php artisan config:clear
```

### 2. Frontend (Next.js)

#### Passo 1: Instalar Dependências (se necessário)
```bash
cd gateway-web
npm install
# ou
yarn install
```

#### Passo 2: Verificar Variáveis de Ambiente
Arquivo `.env.local`:
```env
NEXT_PUBLIC_API_URL=http://seu-backend-url/api
```

---

## 📖 Como Usar

### Backend - Verificar Preferências

```php
use App\Services\NotificationPreferenceService;

$service = app(NotificationPreferenceService::class);

// Obter preferências do usuário
$preferences = $service->getUserPreferences('username123');

// Verificar se deve notificar
$shouldNotify = $service->shouldNotify('username123', 'deposit');

// Atualizar preferências
$service->updatePreferences('username123', [
    'push_enabled' => true,
    'notify_deposits' => true,
]);

// Desabilitar todas
$service->disableAllNotifications('username123');
```

### Frontend - Usar Hook

```tsx
import { useNotificationSettings } from '@/hooks/useNotificationSettings'

function ConfigComponent() {
  const {
    preferences,
    isLoading,
    togglePreference,
    updatePreferences,
  } = useNotificationSettings()

  if (isLoading) return <div>Carregando...</div>

  return (
    <div>
      <Switch
        checked={preferences?.push_enabled}
        onChange={() => togglePreference('push_enabled')}
      />
    </div>
  )
}
```

---

## 🎯 Fluxo de Notificações

### 1. Quando uma transação é aprovada:

```
Transação Aprovada (Observer detecta)
         ↓
Verifica NotificationPreference (Redis Cache)
         ↓
Se push_enabled = true E notify_deposits = true
         ↓
Envia Push Notification
         ↓
Registra na tabela notifications
```

### 2. Quando usuário altera preferências:

```
Usuário altera toggle no front-end
         ↓
React Query (optimistic update)
         ↓
API PUT /notification-preferences
         ↓
Backend atualiza DB
         ↓
Limpa cache Redis
         ↓
Retorna dados atualizados
         ↓
React Query atualiza cache local
```

---

## ⚡ Performance e Cache

### Redis Cache
- **TTL**: 1 hora (3600 segundos)
- **Key Pattern**: `notif_pref:{user_id}`
- **Automatic Invalidation**: Ao atualizar preferências

### React Query Cache
- **Stale Time**: 5 minutos
- **GC Time**: 10 minutos
- **Retry**: 2 tentativas
- **Optimistic Updates**: Sim

### Rate Limiting
- **Notificações**: 60 req/min
- **Preferências**: 30 req/min

---

## 🔒 Segurança

### Backend
- ✅ Autenticação via token + secret
- ✅ Rate limiting por IP
- ✅ Validação de inputs
- ✅ CORS configurado
- ✅ Logs detalhados

### Frontend
- ✅ Token armazenado em localStorage
- ✅ Requests autenticadas
- ✅ Error boundaries
- ✅ Validação de tipos (TypeScript)

---

## 🧪 Como Testar

### 1. Testar Preferências via API

```bash
# Obter preferências
curl -X POST http://localhost:8000/api/notification-preferences \
  -H "Content-Type: application/json" \
  -d '{"token":"SEU_TOKEN","secret":"SEU_SECRET"}'

# Atualizar preferências
curl -X PUT http://localhost:8000/api/notification-preferences \
  -H "Content-Type: application/json" \
  -d '{
    "token":"SEU_TOKEN",
    "secret":"SEU_SECRET",
    "push_enabled":true,
    "notify_deposits":true
  }'
```

### 2. Testar Notificação Push

```bash
php artisan notifications:test username123 --type=deposit --amount=100.00
```

### 3. Testar no Frontend

1. Acesse `/dashboard/configuracoes`
2. Vá para aba "Notificações"
3. Altere os toggles
4. Verifique se salva corretamente
5. Recarregue a página e veja se mantém as preferências

---

## 📊 Monitoramento

### Verificar Cache Redis

```bash
# Conectar ao Redis
redis-cli

# Listar todas as chaves de preferências
KEYS notif_pref:*

# Ver preferências de um usuário específico
GET notif_pref:username123

# Ver TTL de uma chave
TTL notif_pref:username123
```

### Verificar Logs

```bash
# Logs do Laravel
tail -f storage/logs/laravel.log | grep -i "notification\|preference"

# Verificar erros
tail -f storage/logs/laravel.log | grep -i "error"
```

### Verificar Banco de Dados

```sql
-- Ver todas as preferências
SELECT * FROM notification_preferences;

-- Ver preferências de um usuário
SELECT * FROM notification_preferences WHERE user_id = 'username123';

-- Contar quantos usuários têm push habilitado
SELECT COUNT(*) FROM notification_preferences WHERE push_enabled = 1;

-- Ver estatísticas
SELECT 
  COUNT(*) as total_users,
  SUM(push_enabled) as push_enabled_count,
  SUM(notify_deposits) as notify_deposits_count
FROM notification_preferences;
```

---

## 🐛 Troubleshooting

### Problema: Preferências não salvam

**Solução:**
1. Verificar se Redis está rodando: `redis-cli ping`
2. Verificar logs: `tail -f storage/logs/laravel.log`
3. Limpar cache: `php artisan cache:clear`
4. Verificar credenciais no front-end (token/secret)

### Problema: Notificações não respeitam preferências

**Solução:**
1. Verificar se migration foi executada: `php artisan migrate:status`
2. Limpar cache do Redis: `redis-cli FLUSHDB`
3. Verificar logs dos Observers: `grep "OBSERVER" storage/logs/laravel.log`

### Problema: Front-end não carrega preferências

**Solução:**
1. Verificar URL da API no `.env.local`
2. Verificar CORS no backend
3. Abrir DevTools > Network e ver erro da requisição
4. Verificar se token/secret estão no localStorage

---

## 📈 Próximas Melhorias (Opcional)

- [ ] Dashboard de analytics de notificações
- [ ] Histórico de notificações enviadas
- [ ] Agendamento de notificações
- [ ] Notificações em lote (batch)
- [ ] Webhooks para eventos de notificação
- [ ] Testes automatizados (PHPUnit + Jest)
- [ ] Documentação Swagger/OpenAPI

---

## 📞 Suporte

Para dúvidas ou problemas:
1. Verificar logs do Laravel
2. Verificar console do navegador
3. Verificar Redis
4. Verificar este documento

---

**Sistema implementado com sucesso! 🎉**

Data: 31 de Outubro de 2025
Versão: 1.0.0

