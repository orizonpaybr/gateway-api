# Documentação - Integração Pagar.me Cartão de Crédito

## 📋 Índice

1. [Configuração Inicial](#configuração-inicial)
2. [Configuração de Credenciais](#configuração-de-credenciais)
3. [Rotas Disponíveis](#rotas-disponíveis)
4. [Exemplos de Requisições](#exemplos-de-requisições)
5. [Fluxo de Teste Completo](#fluxo-de-teste-completo)
6. [Webhooks](#webhooks)
7. [Tokenização de Cartões](#tokenização-de-cartões)
8. [Tratamento de Erros](#tratamento-de-erros)

---

## 🔧 Configuração Inicial

### 1. Rodar Migrations

Primeiro, você precisa rodar as migrations para criar as tabelas necessárias:

```bash
php artisan migrate
```

Isso criará:
- Campos adicionais na tabela `pagarme` (public_key, webhook_secret, card_tx_percent, etc.)
- Tabela `user_cards` para cartões tokenizados

### 2. Verificar Estrutura

Confirme que as migrations foram executadas:

```sql
-- Verificar campos na tabela pagarme
DESCRIBE pagarme;

-- Verificar tabela user_cards
DESCRIBE user_cards;
```

---

## 🔑 Configuração de Credenciais

### Onde Configurar

As credenciais são armazenadas na tabela `pagarme` no banco de dados.

### Configuração via Banco de Dados

```sql
-- Se já existe um registro, atualize:
UPDATE pagarme SET
    secret = 'sk_test_xxxxxxxxxxxxx',              -- Chave secreta da API
    public_key = 'pk_test_xxxxxxxxxxxxx',          -- Chave pública para tokenização
    webhook_secret = 'whsec_xxxxxxxxxxxxx',        -- Secret para validar webhooks
    environment = 'sandbox',                        -- 'sandbox' ou 'production'
    url = 'https://api.pagar.me/core/v5/',         -- URL da API
    url_cash_in = 'https://api.pagar.me/core/v5/orders',
    card_enabled = 1,                              -- Habilitar pagamentos com cartão
    use_3ds = 1,                                   -- Habilitar 3D Secure
    card_tx_percent = 2.99,                        -- Taxa percentual (ex: 2.99%)
    card_tx_fixed = 0.50,                          -- Taxa fixa (ex: R$ 0,50)
    card_days_availability = 30                    -- Dias para disponibilizar o valor
WHERE id = 1;

-- Se não existe registro, insira um novo:
INSERT INTO pagarme (
    secret, public_key, webhook_secret, environment,
    url, url_cash_in, url_cash_out,
    card_enabled, use_3ds,
    card_tx_percent, card_tx_fixed, card_days_availability,
    created_at, updated_at
) VALUES (
    'sk_test_xxxxxxxxxxxxx',
    'pk_test_xxxxxxxxxxxxx',
    'whsec_xxxxxxxxxxxxx',
    'sandbox',
    'https://api.pagar.me/core/v5/',
    'https://api.pagar.me/core/v5/orders',
    'https://api.pagar.me/core/v5/transaction',
    1,  -- card_enabled
    1,  -- use_3ds
    2.99,  -- card_tx_percent
    0.50,  -- card_tx_fixed
    30,    -- card_days_availability
    NOW(),
    NOW()
);
```

### Configuração via Admin (se tiver interface)

Acesse o painel administrativo e configure em: **Ajustes > Adquirentes > Pagar.me**

### Onde Obter as Credenciais

1. **Acesse o Dashboard Pagar.me**: https://dashboard.pagar.me/
2. **Crie uma conta** ou faça login
3. **Vá em Configurações > API Keys**
4. **Copie as chaves**:
   - **Secret Key**: `sk_test_...` (para requisições server-side)
   - **Public Key**: `pk_test_...` (para tokenização no frontend)
   - **Webhook Secret**: Em **Configurações > Webhooks**, copie o secret

---

## 🛣️ Rotas Disponíveis

### Base URL
```
https://seu-dominio.com/api
```

### Autenticação
Todas as rotas (exceto webhooks) requerem autenticação via:
- **Header**: `Authorization: Bearer {token_jwt}` 
- OU via middleware `check.token.secret` com `token` e `secret` no body

---

## 📝 Exemplos de Requisições

### 1. Criar Depósito via Cartão

**Endpoint**: `POST /api/deposit/card`

**Headers**:
```http
Authorization: Bearer seu_token_jwt
Content-Type: application/json
```

**Body - Usando Token de Cartão (Recomendado)**:
```json
{
  "amount": 100.00,
  "debtor_name": "João Silva",
  "email": "joao@email.com",
  "debtor_document": "12345678900",
  "phone": "11999999999",
  "card_token": "tok_xxxxxxxxxxxxx",
  "installments": 1,
  "use_3ds": true,
  "callbackUrl": "https://seu-site.com/callback",
  "save_card": false,
  "description": "Depósito via cartão"
}
```

**Body - Usando Cartão Salvo (card_id)**:
```json
{
  "amount": 150.00,
  "debtor_name": "Maria Santos",
  "email": "maria@email.com",
  "debtor_document": "98765432100",
  "phone": "11988888888",
  "card_id": "card_xxxxxxxxxxxxx",
  "installments": 3,
  "use_3ds": true,
  "callbackUrl": "https://seu-site.com/callback"
}
```

**Body - Usando Dados Completos do Cartão (Não Recomendado - Apenas para Testes)**:
```json
{
  "amount": 200.00,
  "debtor_name": "Pedro Costa",
  "email": "pedro@email.com",
  "debtor_document": "11122233344",
  "phone": "11977777777",
  "card": {
    "number": "4111111111111111",
    "holder_name": "PEDRO COSTA",
    "exp_month": 12,
    "exp_year": 2025,
    "cvv": "123",
    "billing_address": {
      "line_1": "123, Rua Exemplo, Centro",
      "zip_code": "01234567",
      "city": "São Paulo",
      "state": "SP",
      "country": "BR"
    }
  },
  "installments": 1,
  "use_3ds": true,
  "callbackUrl": "https://seu-site.com/callback"
}
```

**Resposta de Sucesso**:
```json
{
  "status": "success",
  "message": "Pagamento processado com sucesso",
  "data": {
    "idTransaction": "or_xxxxxxxxxxxxx",
    "charge_id": "ch_xxxxxxxxxxxxx",
    "status": "paid",
    "amount": 100.00,
    "net_amount": 97.01,
    "fee": 2.99,
    "installments": 1,
    "days_availability": 30,
    "authentication_url": null
  }
}
```

**Resposta com 3D Secure (quando necessário)**:
```json
{
  "status": "success",
  "message": "Pagamento processado com sucesso",
  "data": {
    "idTransaction": "or_xxxxxxxxxxxxx",
    "charge_id": "ch_xxxxxxxxxxxxx",
    "status": "pending",
    "amount": 100.00,
    "net_amount": 97.01,
    "fee": 2.99,
    "installments": 1,
    "days_availability": 30,
    "authentication_url": "https://secure.mundipagg.com/3ds/xxxxx"
  }
}
```

**Resposta de Erro**:
```json
{
  "status": "error",
  "message": "Cartão recusado pela operadora",
  "errors": {
    "card": "Dados do cartão inválidos"
  }
}
```

---

### 2. Listar Cartões Salvos

**Endpoint**: `GET /api/cards`

**Headers**:
```http
Authorization: Bearer seu_token_jwt
```

**Resposta de Sucesso**:
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "card_id": "card_xxxxxxxxxxxxx",
      "brand": "visa",
      "brand_icon": "fab fa-cc-visa",
      "masked_number": "**** **** **** 1234",
      "holder_name": "JOAO SILVA",
      "expiration_date": "12/2025",
      "is_expired": false,
      "is_default": true,
      "label": "Cartão Principal",
      "last_used_at": "2024-01-15 10:30:00"
    }
  ]
}
```

---

### 3. Remover Cartão Salvo

**Endpoint**: `DELETE /api/cards/{cardId}`

**Headers**:
```http
Authorization: Bearer seu_token_jwt
```

**Exemplo**: `DELETE /api/cards/1`

**Resposta de Sucesso**:
```json
{
  "status": "success",
  "message": "Cartão removido com sucesso"
}
```

---

### 4. Definir Cartão como Padrão

**Endpoint**: `POST /api/cards/{cardId}/default`

**Headers**:
```http
Authorization: Bearer seu_token_jwt
```

**Exemplo**: `POST /api/cards/2/default`

**Resposta de Sucesso**:
```json
{
  "status": "success",
  "message": "Cartão definido como padrão"
}
```

---

### 5. Verificar Status de Depósito

**Endpoint**: `POST /api/status`

**Body**:
```json
{
  "idTransaction": "or_xxxxxxxxxxxxx"
}
```

**Resposta**:
```json
{
  "status": "PAID_OUT"
}
```

Status possíveis:
- `WAITING_FOR_APPROVAL` - Aguardando aprovação
- `PROCESSING` - Processando
- `PAID_OUT` - Pago/Aprovado
- `FAILED` - Falhou
- `REFUNDED` - Estornado
- `CHARGEBACK` - Chargeback

---

## 🧪 Fluxo de Teste Completo

### Passo 1: Configurar Credenciais

```bash
# Acesse o banco de dados e configure
mysql -u usuario -p gateway_api

UPDATE pagarme SET
    secret = 'sk_test_sua_chave_aqui',
    public_key = 'pk_test_sua_chave_aqui',
    webhook_secret = 'whsec_sua_chave_aqui',
    card_enabled = 1,
    use_3ds = 1,
    card_tx_percent = 2.99,
    card_tx_fixed = 0.50
WHERE id = 1;
```

### Passo 2: Obter Token JWT

```bash
# Login na API
curl -X POST https://seu-dominio.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "seu-email@email.com",
    "password": "sua-senha"
  }'
```

Copie o `token` da resposta.

### Passo 3: Criar Depósito de Teste

```bash
# Usando cartão de teste da Pagar.me
curl -X POST https://seu-dominio.com/api/deposit/card \
  -H "Authorization: Bearer SEU_TOKEN_AQUI" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 100.00,
    "debtor_name": "João Silva",
    "email": "joao@email.com",
    "debtor_document": "12345678900",
    "phone": "11999999999",
    "card": {
      "number": "4111111111111111",
      "holder_name": "JOAO SILVA",
      "exp_month": 12,
      "exp_year": 2025,
      "cvv": "123"
    },
    "installments": 1,
    "use_3ds": true
  }'
```

### Passo 4: Verificar Status

```bash
# Substitua or_xxxxxxxxxxxxx pelo idTransaction retornado
curl -X POST https://seu-dominio.com/api/status \
  -H "Content-Type: application/json" \
  -d '{
    "idTransaction": "or_xxxxxxxxxxxxx"
  }'
```

---

## 🔔 Webhooks

### Configurar Webhook na Pagar.me

1. **Acesse o Dashboard**: https://dashboard.pagar.me/
2. **Vá em Configurações > Webhooks**
3. **Adicione URL do webhook**:
   ```
   https://seu-dominio.com/pagarme/webhook
   ```
4. **Selecione eventos**:
   - `order.paid` - Pedido pago
   - `order.payment_failed` - Pagamento falhou
   - `charge.refunded` - Cobrança estornada
   - `charge.chargedback` - Chargeback
5. **Copie o Webhook Secret** e configure no banco

### Endpoint do Webhook

**URL**: `POST /pagarme/webhook`

Este endpoint já está configurado em `routes/groups/adquirentes/pagarme.php` e é processado automaticamente pelo `CallbackController::webhookPagarme()`.

### Eventos Tratados

| Evento | Descrição | Ação no Sistema |
|--------|-----------|-----------------|
| `order.paid` | Pagamento aprovado | Credita saldo do usuário, atualiza status para `PAID_OUT` |
| `order.payment_failed` | Pagamento recusado | Atualiza status para `FAILED` |
| `charge.refunded` | Estorno total | Reverte saldo, atualiza status para `REFUNDED` |
| `charge.partial_refunded` | Estorno parcial | Reverte valor parcial, status `PARTIAL_REFUNDED` |
| `charge.chargedback` | Chargeback | Reverte saldo, status `CHARGEBACK` |

### Testar Webhook Localmente

Use o ngrok ou similar:

```bash
# Instalar ngrok
ngrok http 8000

# Configurar URL do ngrok no dashboard Pagar.me
https://xxxxx.ngrok.io/pagarme/webhook
```

---

## 💳 Tokenização de Cartões

### Frontend - Tokenizecard JS

Para compliance PCI DSS, sempre use tokenização no frontend:

```html
<!DOCTYPE html>
<html>
<head>
    <script src="https://assets.pagar.me/checkout/1.1.0/checkout.js"></script>
</head>
<body>
    <form id="payment-form">
        <input type="text" id="card-number" placeholder="Número do cartão">
        <input type="text" id="card-name" placeholder="Nome no cartão">
        <input type="text" id="card-exp-month" placeholder="Mês (MM)">
        <input type="text" id="card-exp-year" placeholder="Ano (YYYY)">
        <input type="text" id="card-cvv" placeholder="CVV">
        <button type="submit">Pagar</button>
    </form>

    <script>
        const publicKey = 'pk_test_sua_public_key_aqui'; // Vem do banco
        
        document.getElementById('payment-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Criar token do cartão
            const token = await PagarMe.checkout.getCardHash({
                cardNumber: document.getElementById('card-number').value,
                cardHolderName: document.getElementById('card-name').value,
                cardExpirationMonth: document.getElementById('card-exp-month').value,
                cardExpirationYear: document.getElementById('card-exp-year').value,
                cardCvv: document.getElementById('card-cvv').value,
                publicKey: publicKey
            });
            
            // Enviar token para sua API
            const response = await fetch('https://seu-dominio.com/api/deposit/card', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer SEU_TOKEN',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    amount: 100.00,
                    debtor_name: 'João Silva',
                    email: 'joao@email.com',
                    debtor_document: '12345678900',
                    phone: '11999999999',
                    card_token: token.id, // Token gerado
                    save_card: true // Salvar cartão para uso futuro
                })
            });
            
            const data = await response.json();
            console.log(data);
        });
    </script>
</body>
</html>
```

### Salvar Cartão para Uso Futuro

Ao criar um depósito, inclua `"save_card": true` no body. O cartão será automaticamente salvo na tabela `user_cards` e poderá ser reutilizado posteriormente usando `card_id`.

---

## ⚠️ Tratamento de Erros

### Códigos de Status HTTP

| Código | Descrição |
|--------|-----------|
| 200 | Sucesso |
| 400 | Dados inválidos / Pagamento recusado |
| 401 | Não autenticado |
| 422 | Erro de validação |
| 500 | Erro interno |

### Erros Comuns

#### Cartão Recusado
```json
{
  "status": "error",
  "message": "Cartão recusado pela operadora"
}
```

**Possíveis causas**:
- Cartão sem saldo/limite
- Dados incorretos (CVV, validade)
- Cartão bloqueado
- Operadora recusou

#### Credenciais Inválidas
```json
{
  "status": "error",
  "message": "Pagamentos com cartão não estão habilitados"
}
```

**Solução**: Verifique se `card_enabled = 1` na tabela `pagarme`.

#### Valor Mínimo
```json
{
  "status": "error",
  "message": "O valor mínimo de depósito é de R$ 10,00"
}
```

**Solução**: Ajuste o valor mínimo no perfil do usuário ou configurações globais.

---

## 📊 Cartões de Teste Pagar.me

Use estes cartões para testar diferentes cenários:

| Número | Cenário | Resultado |
|--------|---------|-----------|
| `4111111111111111` | Aprovado | Pagamento aprovado imediatamente |
| `4000000000000010` | 3D Secure | Solicita autenticação 3DS |
| `4000000000009995` | Recusado | Pagamento recusado |
| `4000000000000002` | Falha genérica | Erro genérico |

**Dados de teste**:
- CVV: Qualquer 3 dígitos (ex: `123`)
- Validade: Qualquer data futura (ex: `12/2025`)
- Nome: Qualquer nome

---

## 🔍 Consultas Úteis no Banco

### Ver Transações Criadas

```sql
SELECT 
    id,
    user_id,
    idTransaction,
    amount,
    deposito_liquido,
    taxa_cash_in,
    status,
    method,
    created_at
FROM solicitacoes
WHERE method = 'card'
ORDER BY created_at DESC
LIMIT 10;
```

### Ver Cartões Salvos

```sql
SELECT 
    uc.*,
    u.username
FROM user_cards uc
JOIN users u ON uc.user_id = u.id
WHERE uc.deleted_at IS NULL
ORDER BY uc.created_at DESC;
```

### Ver Configuração Pagar.me

```sql
SELECT 
    card_enabled,
    use_3ds,
    card_tx_percent,
    card_tx_fixed,
    card_days_availability,
    environment
FROM pagarme
WHERE id = 1;
```

---

## ✅ Checklist de Implementação

- [ ] Migrations rodadas (`php artisan migrate`)
- [ ] Credenciais configuradas na tabela `pagarme`
- [ ] `card_enabled = 1` habilitado
- [ ] Webhook configurado no dashboard Pagar.me
- [ ] Webhook Secret configurado no banco
- [ ] Taxas configuradas (`card_tx_percent`, `card_tx_fixed`)
- [ ] Testado depósito com cartão de teste
- [ ] Testado webhook (usando ngrok se necessário)
- [ ] Tokenização JS integrada no frontend (se aplicável)

---

## 📞 Suporte

Para mais informações:
- **Documentação Pagar.me**: https://docs.pagar.me/
- **Dashboard**: https://dashboard.pagar.me/
- **Suporte**: suporte@pagar.me

---

**Última atualização**: Janeiro 2024
**Versão da API Pagar.me**: V5 (2021-09-01)
