# 📚 Documentação da API

> Documentação completa da API Gateway de Pagamentos

---

## 📍 Base URL

```
https://seu-dominio.com/api
```

Para desenvolvimento local:
```
http://localhost:8000/api
```

---

## 🔑 Autenticação

A API usa dois tipos de autenticação:

### 1. JWT Token (Bearer) - Para rotas de usuário

Obtido via login e enviado no header:

```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Fluxo de Autenticação:**

1. **Login:**
```bash
POST /api/auth/login
Content-Type: application/json

{
  "username": "seu_usuario",
  "password": "sua_senha"
}
```

**Resposta com 2FA:**
```json
{
  "success": false,
  "requires_2fa": true,
  "message": "Digite o código de 6 dígitos do seu app autenticador",
  "temp_token": "token_temporario_aqui"
}
```

2. **Verificar 2FA (se necessário):**
```bash
POST /api/auth/verify-2fa
Content-Type: application/json

{
  "temp_token": "token_temporario_do_login",
  "code": "123456"
}
```

3. **Resposta de Sucesso:**
```json
{
  "success": true,
  "data": {
    "user": {...},
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "expires_at": 1234567890
  }
}
```

### 2. Token + Secret - Para transações

Usado para depósitos, saques e outras transações:

```http
Authorization: Bearer {token}
X-User-Secret: {secret}
```

**Onde obter:**
- **Token**: Obtido via login (mesmo JWT)
- **Secret**: Chave secreta gerada no painel (Integrações → Chaves API)

---

## 🔐 Rotas de Autenticação

### POST `/api/auth/login`

Login de usuário.

**Body:**
```json
{
  "username": "seu_usuario",
  "password": "sua_senha"
}
```

### POST `/api/auth/verify-2fa`

Verificar código 2FA.

**Body:**
```json
{
  "temp_token": "token_temporario",
  "code": "123456"
}
```

### POST `/api/auth/register`

Registrar novo usuário.

**Body:**
```json
{
  "name": "Nome Completo",
  "email": "email@exemplo.com",
  "username": "usuario_unico",
  "password": "senha_segura",
  "password_confirmation": "senha_segura",
  "cpf": "12345678900",
  "telefone": "11999999999"
}
```

### POST `/api/auth/logout`

Logout do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

### GET `/api/auth/verify`

Verificar se o token é válido.

**Headers:**
```
Authorization: Bearer {token}
```

---

## 💰 Endpoints de Transações

### PIX IN (Depósito)

#### POST `/api/wallet/deposit/payment`

Cria uma transação de depósito PIX e retorna o QR Code.

**Headers:**
```
Authorization: Bearer {token}
X-User-Secret: {secret}
Content-Type: application/json
```

**Body:**
```json
{
  "token": "seu_token_aqui",
  "secret": "sua_chave_secreta",
  "amount": 100.00,
  "client_name": "João Silva",
  "client_email": "joao@email.com",
  "client_document": "12345678901",
  "client_telefone": "11999999999",
  "description": "Depósito via PIX",
  "split_email": "parceiro@email.com",
  "split_percentage": 10.0,
  "postback": "https://seu-site.com/webhook"
}
```

**Parâmetros:**

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `token` | string | Sim | Token de autenticação |
| `secret` | string | Sim | Chave secreta |
| `amount` | decimal | Sim | Valor do depósito (mín: R$ 1,00) |
| `client_name` | string | Sim | Nome do cliente |
| `client_email` | string | Sim | Email do cliente |
| `client_document` | string | Sim | CPF/CNPJ do cliente |
| `client_telefone` | string | Não | Telefone do cliente |
| `description` | string | Não | Descrição da transação |
| `split_email` | string | Não | Email do destinatário do split |
| `split_percentage` | decimal | Não | Percentual do split (0-100) |
| `postback` | string | Não | URL para receber webhooks |

**Resposta de Sucesso:**
```json
{
  "success": true,
  "data": {
    "idTransaction": "dep_1234567890",
    "status": "pending",
    "amount": 100.00,
    "qr_code": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...",
    "qr_code_text": "00020126580014br.gov.bcb.pix...",
    "expires_at": "2025-01-15T15:30:00Z",
    "created_at": "2025-01-15T15:00:00Z"
  }
}
```

---

### PIX OUT (Saque)

#### POST `/api/pixout`

Processa um saque PIX.

**Headers:**
```
Authorization: Bearer {token}
X-User-Secret: {secret}
Content-Type: application/json
```

**Body:**
```json
{
  "token": "seu_token_aqui",
  "secret": "sua_chave_secreta",
  "amount": 50.00,
  "pix_key": "11999999999",
  "pix_key_type": "PHONE",
  "description": "Saque via PIX"
}
```

**Parâmetros:**

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `token` | string | Sim | Token de autenticação |
| `secret` | string | Sim | Chave secreta |
| `amount` | decimal | Sim | Valor do saque |
| `pix_key` | string | Sim | Chave PIX de destino |
| `pix_key_type` | string | Sim | Tipo: `CPF`, `CNPJ`, `EMAIL`, `PHONE`, `RANDOM` |
| `description` | string | Não | Descrição da transação |

**Resposta de Sucesso:**
```json
{
  "success": true,
  "data": {
    "idTransaction": "out_1234567890",
    "status": "processing",
    "amount": 50.00,
    "pixKey": "11999999999",
    "created_at": "2025-01-15T15:00:00Z"
  }
}
```

---

### 💳 Cartão de Crédito

#### POST `/api/card/payment`

Processa um pagamento com cartão de crédito.

**Headers:**
```
Authorization: Bearer {token}
X-User-Secret: {secret}
Content-Type: application/json
```

**Body:**
```json
{
  "token": "seu_token_aqui",
  "secret": "sua_chave_secreta",
  "amount": 100.00,
  "installments": 1,
  "client_name": "João Silva",
  "client_email": "joao@email.com",
  "client_document": "12345678901",
  "client_phone": "11999999999",
  "card": {
    "hash": "9db439ac1dc4bc059fcb63c24400f90f...",
    "number": "5188146956393080",
    "holder_name": "JOAO SILVA",
    "expiration_month": 12,
    "expiration_year": 2028,
    "cvv": "123"
  },
  "description": "Pagamento via API",
  "return_url": "https://seusite.com/retorno",
  "postback_url": "https://seusite.com/webhook"
}
```

> **⚠️ Importante:** É altamente recomendado usar `card.hash` (cartão tokenizado) ao invés dos dados brutos. Para tokenizar, use a biblioteca JavaScript do PrimePay7.

**Resposta de Sucesso:**
```json
{
  "status": "success",
  "data": {
    "transaction_id": "22368873",
    "external_reference": "API_CARD_67896abc12345",
    "status": "processing",
    "amount": 100.00,
    "amount_net": 96.25,
    "fee": 3.75,
    "installments": 1,
    "created_at": "2025-10-08T15:30:00Z"
  }
}
```

#### GET `/api/card/payment/{transactionId}`

Consulta o status de um pagamento com cartão.

**Query Params:**
```
?token=seu_token&secret=sua_secret
```

---

### 📊 Consultar Status

#### POST `/api/status`

Consulta o status de uma transação.

**Body:**
```json
{
  "idTransaction": "dep_1234567890"
}
```

**Resposta:**
```json
{
  "success": true,
  "data": {
    "idTransaction": "dep_1234567890",
    "status": "paid",
    "amount": 100.00,
    "paid_at": "2025-01-15T15:05:00Z",
    "created_at": "2025-01-15T15:00:00Z"
  }
}
```

---

## 👤 Endpoints de Usuário

### GET `/api/balance`

Obter saldo do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

**Resposta:**
```json
{
  "success": true,
  "data": {
    "current": 1500.00,
    "totalInflows": 5000.00,
    "totalOutflows": 3500.00
  }
}
```

### GET `/api/transactions`

Listar transações do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

**Query Params:**
```
?page=1&per_page=20&status=PAID_OUT&type=deposit
```

### GET `/api/user/profile`

Obter perfil do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

### GET `/api/statement`

Obter extrato detalhado.

**Headers:**
```
Authorization: Bearer {token}
```

### POST `/api/pix/generate-qr`

Gerar QR Code PIX.

**Headers:**
```
Authorization: Bearer {token}
```

---

## 🔔 Notificações

### GET `/api/notifications`

Listar notificações do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

### POST `/api/notifications/register-token`

Registrar token de push notification.

**Headers:**
```
Authorization: Bearer {token}
```

**Body:**
```json
{
  "token": "expo_push_token_aqui",
  "device_type": "ios|android"
}
```

### POST `/api/notifications/{id}/read`

Marcar notificação como lida.

**Headers:**
```
Authorization: Bearer {token}
```

### GET `/api/notification-preferences`

Obter preferências de notificação.

**Headers:**
```
Authorization: Bearer {token}
```

### PUT `/api/notification-preferences`

Atualizar preferências de notificação.

**Headers:**
```
Authorization: Bearer {token}
```

**Body:**
```json
{
  "push_enabled": true,
  "notify_deposits": true,
  "notify_withdrawals": true,
  "notify_transactions": true
}
```

---

## 🎮 Gamificação

### GET `/api/user/level`

Obter nível atual do usuário.

**Headers:**
```
Authorization: Bearer {token}
```

**Resposta:**
```json
{
  "success": true,
  "data": {
    "current_level": {
      "id": 2,
      "nome": "Prata",
      "minimo": 100000.00,
      "maximo": 500000.00
    },
    "progress": 65.5,
    "next_level": {
      "id": 3,
      "nome": "Ouro",
      "minimo": 500000.00,
      "maximo": 1000000.00
    }
  }
}
```

---

## 🔄 Webhooks

O sistema envia notificações em tempo real para sua URL de webhook quando o status de uma transação muda.

### Configuração

Configure sua URL de webhook no campo `postback` ao criar uma transação:

```json
{
  "postback": "https://seudominio.com/webhook/playgame"
}
```

### Payload do Webhook - PIX IN (Depósito Pago)

```json
{
  "idTransaction": "dep_1234567890",
  "status": "paid",
  "typeTransaction": "PIX",
  "amount": 100.00,
  "debtor_name": "João Silva",
  "email": "joao@email.com",
  "debtor_document_number": "12345678901",
  "phone": "11999999999",
  "created_at": "2025-01-15T15:00:00Z",
  "paid_at": "2025-01-15T15:05:00Z",
  "split_processed": true,
  "split_amount": 10.00,
  "split_recipient": "parceiro@email.com"
}
```

### Payload do Webhook - PIX OUT (Saque Processado)

```json
{
  "idTransaction": "out_1234567890",
  "status": "completed",
  "typeTransaction": "PIX",
  "amount": 50.00,
  "pixKey": "11999999999",
  "externalId": "EXT_REF_1234567890",
  "created_at": "2025-01-15T15:00:00Z",
  "completed_at": "2025-01-15T15:10:00Z"
}
```

### Códigos de Status

| Status | Descrição |
|--------|-----------|
| `pending` | Aguardando pagamento |
| `paid` | Pago com sucesso |
| `processing` | Processando |
| `completed` | Concluído |
| `failed` | Falhou |
| `cancelled` | Cancelado |
| `approved` | Aprovado (cartão) |
| `refused` | Recusado (cartão) |

---

## 🔒 Controle de IP

Para maior segurança, configure IPs permitidos para operações de saque.

### Configuração

1. Acesse seu perfil em `/my-profile`
2. Vá para a aba "Credenciais"
3. Adicione os IPs permitidos na seção "IP's Permitidos"

### Formatos Suportados

- **IP Único**: `192.168.1.1`
- **Range CIDR**: `192.168.1.0/24`
- **Wildcard**: `192.168.1.*`

### Comportamento

- **PIX IN**: Sem restrição de IP
- **PIX OUT**: Apenas IPs autorizados podem realizar saques

---

## 💰 Limites e Taxas

### Limites por Transação

- **Depósito Mínimo**: R$ 1,00
- **Depósito Máximo**: R$ 50.000,00
- **Saque Mínimo**: R$ 10,00
- **Saque Máximo**: R$ 20.000,00

### Taxas

As taxas são configuráveis por usuário e podem variar conforme o adquirente:

- **PIX IN**: 1,5% a 3,5%
- **PIX OUT**: 2,0% a 4,0%
- **Cartão**: Variável conforme parcelas

### Sistema de Taxas Flexível

O sistema oferece taxas flexíveis:

- **Valores baixos**: Taxa fixa (ex: R$ 1,20)
- **Valores altos**: Taxa percentual (ex: 3,75%)

**Exemplo:**
- Depósito R$ 3,00: Taxa = R$ 1,20 (taxa fixa)
- Depósito R$ 10,00: Taxa = R$ 0,38 (3,75% de R$ 10,00)

---

## 📦 Exemplos de Integração

### JavaScript (Node.js)

```javascript
const axios = require('axios');

async function criarDeposito(dados) {
  try {
    const response = await axios.post('https://seu-dominio.com/api/wallet/deposit/payment', {
      token: 'seu_token',
      secret: 'sua_chave',
      amount: dados.valor,
      client_name: dados.nome,
      client_email: dados.email,
      client_document: dados.cpf
    });
    
    return response.data;
  } catch (error) {
    console.error('Erro ao criar depósito:', error.response.data);
  }
}
```

### PHP

```php
<?php
function criarDeposito($dados) {
    $url = 'https://seu-dominio.com/api/wallet/deposit/payment';
    
    $payload = [
        'token' => 'seu_token',
        'secret' => 'sua_chave',
        'amount' => $dados['valor'],
        'client_name' => $dados['nome'],
        'client_email' => $dados['email'],
        'client_document' => $dados['cpf']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>
```

### Python

```python
import requests

def criar_deposito(dados):
    url = 'https://seu-dominio.com/api/wallet/deposit/payment'
    
    payload = {
        'token': 'seu_token',
        'secret': 'sua_chave',
        'amount': dados['valor'],
        'client_name': dados['nome'],
        'client_email': dados['email'],
        'client_document': dados['cpf']
    }
    
    response = requests.post(url, json=payload)
    return response.json()
```

---

## ⚠️ Códigos de Erro

| Código | Descrição |
|--------|-----------|
| `400` | Dados inválidos |
| `401` | Não autenticado |
| `403` | IP não autorizado / Sem permissão |
| `404` | Transação não encontrada |
| `422` | Erro de validação |
| `500` | Erro interno do servidor |

---

## 📝 Rate Limiting

Os endpoints possuem limitação de requisições:

- **Transações**: 60 req/min
- **Saques**: 30 req/min
- **Callbacks**: 30 req/min
- **Boletos**: 5 req/min
- **Notificações**: 60 req/min

---

## 🧪 Coleção Insomnia

Uma coleção completa com 137 rotas está disponível em `insomnia-collection.json`.

**Como importar:**
1. Abra o Insomnia
2. Clique em **Application** → **Preferences**
3. Vá para **Data** → **Import/Export** → **Import Data** → **From File**
4. Selecione o arquivo `insomnia-collection.json`

**Configurar variáveis de ambiente:**
```json
{
  "base_url": "http://localhost:8000",
  "token": "seu_token_jwt_aqui",
  "secret": "sua_chave_secreta_aqui"
}
```

---

## 📚 Documentação Adicional

- **Swagger/OpenAPI**: Acesse `/api/documentation` (se configurado)
- **Arquivos OpenAPI**: `openapi.yaml`, `openapi.json`, `openapi-simple.yaml`

---

**Última atualização:** Janeiro 2025
