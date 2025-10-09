# 🔒 Configuração de Proteção de IP para Saques

## 📋 **Problema Identificado**

O sistema de cassino está criando saques diretamente no banco, bypassando as proteções de segurança da PlayGame:

- ❌ **Sem verificação de IP**: Saques podem ser feitos de qualquer IP
- ❌ **Sem verificação de token/secret**: Sem autenticação adequada
- ❌ **Sem verificação de PIN**: Sem proteção adicional do usuário

## ✅ **Solução: Integração Correta**

### 🔐 **Middlewares de Proteção Ativos**

A rota `/api/pixout` tem **3 middlewares de proteção**:

1. **`check.token.secret`** - Verifica token e secret válidos
2. **`check.allowed.ip`** - **Verifica se o IP está autorizado**
3. **`check.pin`** - Verifica PIN do usuário

### 🛠️ **Como Configurar IPs Autorizados**

#### 1. **Via Banco de Dados (Recomendado)**

```sql
-- Atualizar usuário com IPs permitidos
UPDATE users 
SET ips_saque_permitidos = '["192.168.1.100", "10.0.0.50", "201.23.45.67"]'
WHERE user_id = 'seu_usuario_id';
```

#### 2. **Formatos Suportados**

```json
// Formato JSON (recomendado)
["192.168.1.100", "10.0.0.50", "201.23.45.67"]

// Formato CSV
192.168.1.100,10.0.0.50,201.23.45.67

// Formato linha por linha
192.168.1.100
10.0.0.50
201.23.45.67
```

### 🚀 **Fluxo Correto de Integração**

```
1. Usuário solicita saque no cassino
2. Cassino chama API PlayGame: POST /api/pixout
3. PlayGame verifica:
   - ✅ Token/Secret válidos
   - ✅ IP autorizado (check.allowed.ip)
   - ✅ PIN do usuário (check.pin)
   - ✅ Saldo suficiente
4. PlayGame cria saque e envia para F.E.I pay
5. F.E.I pay processa e envia callback
6. PlayGame atualiza status e envia webhook para cassino
```

### 📝 **Exemplo de Requisição**

```bash
curl -X POST "https://playgameoficial.com.br/api/pixout" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "SEU_TOKEN",
    "secret": "SEU_SECRET",
    "amount": 100.00,
    "pixKey": "17865551746",
    "pixKeyType": "telefone",
    "baasPostbackUrl": "web"
  }'
```

### 🔍 **Verificação de IPs**

#### **Headers Verificados (em ordem):**

1. `HTTP_CF_CONNECTING_IP` - Cloudflare
2. `HTTP_X_FORWARDED_FOR` - Load balancer/proxy
3. `HTTP_X_FORWARDED` - Proxy
4. `HTTP_X_CLUSTER_CLIENT_IP` - Cluster
5. `HTTP_FORWARDED_FOR` - Proxy
6. `HTTP_FORWARDED` - Proxy
7. `REMOTE_ADDR` - IP direto

#### **Logs de Verificação:**

```php
// Log quando IP é autorizado
[IP_CHECK] IP autorizado para saque
user_id: 123
client_ip: 192.168.1.100

// Log quando IP é negado
[IP_CHECK] IP não autorizado para saque
user_id: 123
client_ip: 192.168.1.200
allowed_ips: ["192.168.1.100", "10.0.0.50"]
```

### ⚠️ **Importante**

1. **Nunca criar saques diretamente no banco** - Sempre usar a API
2. **Sempre verificar IPs** - Configurar `ips_saque_permitidos`
3. **Usar tokens válidos** - Configurar `token` e `secret`
4. **Implementar PIN** - Configurar PIN do usuário
5. **Usar callback unificado** - Sempre usar `baasPostbackUrl: "web"`

### 🎯 **Próximos Passos**

1. **Configurar IPs autorizados** no banco de dados
2. **Integrar cassino com API PlayGame** usando o exemplo fornecido
3. **Testar proteções** com IPs não autorizados
4. **Monitorar logs** para verificar funcionamento

### 📞 **Suporte**

Se precisar de ajuda com a integração, consulte:
- Arquivo: `exemplo_integracao_cassino.php`
- Logs: `storage/logs/laravel.log`
- Middleware: `app/Http/Middleware/CheckAllowedIP.php`
