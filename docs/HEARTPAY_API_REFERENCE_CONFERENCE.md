# Conferência Final — HeartPay API Reference vs Implementação Orizon

Referência: API Reference HeartPay BaaS (URL Base `https://app.heartpag.com/api/v1/client`).

---

## 1. URL Base e Autenticação

| Item | Documentação | Implementação | Status |
|------|--------------|---------------|--------|
| URL Base | `https://app.heartpag.com/api/v1/client` | `config/heartpay.php` → `HEARTPAY_API_URL` (default igual) | OK |
| Autenticação | `Authorization: Bearer hpay_xxxxxxxxxxxxxxxxxxxxx` | `HeartPayService::http()` → `Authorization: Bearer {api_key}` | OK |
| API Key | Formato `hpay_` + 32 chars | Configurada via `HEARTPAY_API_KEY` | OK |

---

## 2. Endpoints API Gateway (Integração Externa)

| Método | Endpoint | Documentação | Implementação | Status |
|--------|----------|--------------|----------------|--------|
| GET | `/balance` | Consultar saldo | `HeartPayService::getBalance()` | OK |
| POST | `/charges` | Criar cobrança PIX | `HeartPayService::createCharge()` | OK |
| GET | `/charges` | Listar cobranças | `HeartPayService::listCharges()` | OK |
| GET | `/charges/:correlationID` | Buscar por correlationID | `HeartPayService::getCharge()` | OK |
| GET | `/charges/e2e/:endToEndId` | Buscar por E2E | `HeartPayService::getChargeByE2E()` | OK |
| DELETE | `/charges/:correlationID` | Cancelar cobrança (Woovi) | `HeartPayService::cancelCharge()` | OK |
| POST | `/customers` | Cadastrar cliente (Woovi) | `HeartPayService::createCustomer()` | OK |
| GET | `/customers` | Listar clientes (Woovi) | `HeartPayService::listCustomers()` | OK |
| POST | `/payouts` | Criar saque/transferência | `HeartPayService::createPayout()` | OK |
| GET | `/payouts` | Listar saques | `HeartPayService::listPayouts()` | OK |
| GET | `/payouts/:identifier` | Status (reference_code ou correlationID) | `HeartPayService::getPayout()` | OK |
| GET | `/payouts/:correlationID/receipt` | Comprovante PNG base64 | `HeartPayService::getPayoutReceipt()` | OK |
| GET | `/transactions` | Listar extrato | `HeartPayService::listTransactions()` | OK |
| POST | `/refunds` | Criar reembolso (Woovi/Venit) | `HeartPayService::createRefund()` | OK |

**Não implementados (opcionais / Woovi apenas):**

- `GET /transactions/:id` — Woovi apenas
- `GET/POST/DELETE /pix-keys` — Woovi apenas
- `GET/POST /qr-codes` — Woovi apenas  

Não necessários para o fluxo atual do gateway (depósito, saque, reembolso, webhooks).

---

## 3. Valores Monetários e Fuso

| Regra | Documentação | Implementação | Status |
|------|--------------|----------------|--------|
| Valores | Centavos (inteiros); R$ 1,00 = 100 | `HeartPayService::toCents()` / `toReais()` em todos os pontos | OK |
| Fuso | Brasília (UTC-3), ISO 8601 | Datas enviadas/tratadas em ISO 8601 | OK |

---

## 4. Rate Limiting

| Item | Documentação | Implementação | Status |
|------|--------------|----------------|--------|
| Limite | 10.000 req / 15 min | `bootstrap/app.php` → throttle 10000/15 min para pix-in, pix-out, status-check | OK |
| Headers resposta | RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset | `logRateLimitHeaders()` usa esses headers | OK |
| 429 | Retry-After | `handleResponse()` loga `Retry-After` (e fallback para RateLimit-Reset) | OK |

---

## 5. Códigos de Resposta HTTP

| Código | Documentação | Implementação | Status |
|--------|--------------|---------------|--------|
| 200 | OK | `handleResponse()` → success | OK |
| 201 | Created | Tratado como success | OK |
| 400 | Bad Request | Retorno com `success: false`, message/code | OK |
| 401 | Unauthorized | Idem | OK |
| 403 | Forbidden | Idem | OK |
| 404 | Not Found | Idem | OK |
| 429 | Too Many Requests | Log específico + Retry-After | OK |
| 500 | Server Error | Retry em 5xx (shouldRetry) | OK |

Formato de erro padrão (error, message, code, details): corpo completo é logado; `code` e `message` são extraídos para resposta interna.

---

## 6. Webhooks

| Item | Documentação | Implementação | Status |
|------|--------------|----------------|--------|
| Headers | X-HeartPay-Signature, X-HeartPay-Timestamp, X-HeartPay-Algorithm, X-HeartPay-Event | `ValidateWebhook::validateHeartPayWebhook()` usa Signature + Timestamp | OK |
| Assinatura | HMAC-SHA256: `timestamp.payload`, chave = Webhook Token | Payload assinado = `timestamp . rawBody`; validação com `HEARTPAY_WEBHOOK_SECRET` | OK |
| Replay | — | Timestamp > 5 min rejeitado | OK |
| Eventos | PayInCreated, PayInCompleted, PayInCancelled, PayInRefunded, PayOut*, Dispute* | `CallbackController::webhookHeartPay()` + Jobs (CashIn, CashOut, Refund, Dispute) | OK |
| Resposta | HTTP 2xx para confirmar | 200 imediato; processamento assíncrono via Jobs | OK |
| Retentativas | 1ª imediata, 2ª 1 min, 3ª 5 min | Idempotência por WebhookLog; 200 evita reenvio desnecessário | OK |

---

## 7. Reembolsos (POST /refunds)

| Regra | Documentação | Implementação | Status |
|------|--------------|----------------|--------|
| correlationID | Obrigatório | Enviado sempre em `createRefund()` | OK |
| value | Opcional (omitido = total) | Enviado só se `$amountReais !== null` | OK |
| comment | Opcional | Enviado se informado | OK |
| Cobrança | Apenas COMPLETED | Validado pela HeartPay (regra de negócio lado deles) | OK |

---

## 8. Resumo

- **URL base, autenticação, endpoints usados pelo gateway e valores em centavos** estão alinhados com a API Reference.
- **Rate limit** (10k/15min) e tratamento de **429** (incl. Retry-After) estão cobertos.
- **Webhooks**: validação HMAC (timestamp.payload), eventos mapeados, resposta 200 e processamento assíncrono com repasse ao cliente final.
- **Reembolsos**: POST /refunds com correlationID, value opcional e comment opcional.
- **Endpoints Woovi-only** (pix-keys, qr-codes, GET transactions/:id) não implementados de propósito; podem ser adicionados depois se necessário.

Conferência final: **implementação compatível com a API Reference HeartPay** para o escopo utilizado (Gateway Orizon).
