# 🏦 Configuração do Adquirente PagArm

Guia prático para configurar o PagArm no Gateway Orizon após receber as credenciais do cliente.

---

## 📋 Checklist de Credenciais Necessárias

Antes de começar, você precisa ter:

-   ✅ **Client ID** - ID do cliente fornecido pelo PagArm
-   ✅ **Client Secret** - Secret do cliente fornecido pelo PagArm
-   ✅ **API Key** - Chave de API para autenticação
-   ✅ **Webhook Secret** - Secret para validar webhooks (gerar se não fornecido)
-   ✅ **Merchant ID** - ID do comerciante (opcional)
-   ✅ **Account ID** - ID da conta (opcional)
-   ✅ **Ambiente** - Sandbox ou Production

---

## 🔧 Passo 1: Configurar Variáveis no .env

Adicione ao arquivo `.env`:

```env
# =============================================
# Configurações do PagArm
# =============================================

# URL Base da API
# Sandbox: https://sandbox-api.pagarm.com.br/v1
# Produção: https://api.pagarm.com.br/v1
PAGARM_BASE_URL=https://sandbox-api.pagarm.com.br/v1

# Credenciais de Autenticação
PAGARM_CLIENT_ID=seu_client_id_aqui
PAGARM_CLIENT_SECRET=seu_client_secret_aqui
PAGARM_API_KEY=sua_api_key_aqui

# Ambiente (sandbox ou production)
PAGARM_ENVIRONMENT=sandbox

# Webhook Secret (para validar callbacks)
PAGARM_WEBHOOK_SECRET=seu_webhook_secret_aqui

# IDs Opcionais
PAGARM_MERCHANT_ID=
PAGARM_ACCOUNT_ID=
```

**Após adicionar, execute:**

```bash
php artisan config:clear
php artisan cache:clear
```

---

## 🗄️ Passo 2: Executar Migrations

```bash
cd gateway-backend
php artisan migrate
```

Isso criará:

-   Tabela `pagarm` para configurações
-   Entrada na tabela `adquirentes`

---

## 🌐 Passo 3: Configurar Webhooks no Dashboard PagArm

Acesse o dashboard do PagArm e configure os webhooks:

### Webhook de Depósitos (PIX IN)

```
URL: https://seudominio.com.br/api/pagarm/callback/deposit
Método: POST
Eventos: payment.completed, payment.approved, pix.received
Secret: [Use o mesmo valor de PAGARM_WEBHOOK_SECRET]
```

### Webhook de Saques (PIX OUT)

```
URL: https://seudominio.com.br/api/pagarm/callback/withdraw
Método: POST
Eventos: withdraw.completed, withdraw.failed, pix.sent
Secret: [Use o mesmo valor de PAGARM_WEBHOOK_SECRET]
```

**💡 Dica:** Para gerar um webhook secret seguro:

```bash
openssl rand -hex 32
```

---

## ⚙️ Passo 4: Ativar PagArm no Sistema

### Via Tinker (Recomendado)

```bash
php artisan tinker

# Dentro do tinker:
$pagarm = App\Models\PagArm::first();
$pagarm->status = true;
$pagarm->save();
exit
```

### Via Painel Admin (Alternativa)

1. Acesse: **Configurações** → **Adquirentes**
2. Localize o PagArm na lista
3. Ative o toggle de status

---

## 🧪 Passo 5: Testar Integração

### Verificar Rotas

```bash
php artisan route:list | grep pagarm
```

**Rotas esperadas:**

-   `POST /api/pagarm/callback/deposit`
-   `POST /api/pagarm/callback/withdraw`

### Verificar Logs

```bash
tail -f storage/logs/laravel.log | grep PAGARM
```

### Testar Depósito (via API)

```bash
curl -X POST https://seudominio.com.br/api/wallet/deposit/payment \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 100.00,
    "metodo": "pix",
    "adquirente": "pagarm"
  }'
```

---

## 💰 Taxas do PagArm

### Taxas que o PagArm cobra do Gateway:

-   **Entradas (PIX IN)**: 0,50%
-   **Saídas (PIX OUT)**: 0,50%

### Taxas que o Gateway cobra do Cliente:

Configuráveis no painel admin em **Ajustes** → **Gerais** → **Taxas**

**Padrão:**

-   Taxa percentual: 5%
-   Taxa fixa: R$ 1,00
-   Taxa mínima: R$ 1,00

**💡 Nota:** O sistema usa automaticamente taxas globais ou personalizadas do usuário (se configuradas).

---

## 🐛 Troubleshooting

### Erro: "PagArm não configurado ou inativo"

**Solução:** Ative o PagArm via tinker (Passo 4)

### Erro: "Webhook secret inválido"

**Solução:**

1. Verifique se `PAGARM_WEBHOOK_SECRET` no `.env` corresponde ao configurado no dashboard PagArm
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

---

## 📊 Verificar Transações

### Via SQL

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

## ✅ Checklist Final

Antes de considerar concluído:

-   [ ] Variáveis do `.env` configuradas
-   [ ] Migrations executadas
-   [ ] Webhooks configurados no dashboard PagArm
-   [ ] PagArm ativado no sistema
-   [ ] Rotas verificadas (`php artisan route:list | grep pagarm`)
-   [ ] Teste de depósito funcionando
-   [ ] Teste de saque funcionando
-   [ ] Logs sem erros
-   [ ] SSL válido e funcionando

---

## 📞 Suporte

**PagArm:**

-   Email: suporte@pagarm.com.br
-   Documentação: https://docs.pagarm.com.br

**Gateway Orizon:**

-   Verifique logs: `tail -f storage/logs/laravel.log | grep PAGARM`

---

**Versão:** 1.0.0  
**Última atualização:** 02/01/2025
