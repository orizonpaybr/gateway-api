# Conferência Final — HeartPay API Reference vs Implementação Coratri

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

**Doc HeartPay (API Gateway):** 10.000 req / 15 min. Headers: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`.

| Item | Documentação HeartPay | Implementação Coratri | Status |
|------|------------------------|----------------------|--------|
| Limite API Gateway | 10.000 req / 15 min | `bootstrap/app.php` → throttle 10000/15 min para pix-in, pix-out, status-check (alinhado à doc HeartPay) | OK |
| Webhook (entrada) | — | `heartpay.php` → throttle 2000/min (~33 req/s) para evitar 429 em picos | OK |
| Headers resposta | RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset | `logRateLimitHeaders()` loga esses headers nas respostas da HeartPay | OK |
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

### 6.1 Eventos e payload (doc HeartPay)

Referência única: documentação oficial HeartPay. O gateway aceita os nomes de evento em ambos os formatos (ex.: `PayOutCompleted` e `payout.completed`) para compatibilidade.

| Evento HeartPay | Descrição | Job Coratri |
|-----------------|-----------|------------|
| PayInCreated | Cobrança PIX criada, aguardando pagamento | ProcessHeartPayCashInJob |
| PayInCompleted / charge.paid | Pagamento confirmado, creditado | ProcessHeartPayCashInJob |
| PayInCancelled / charge.expired | Cobrança cancelada ou expirada | ProcessHeartPayCashInJob |
| PayInRefunded / charge.refunded | Reembolso processado | ProcessHeartPayRefundJob |
| PAYOUT_CREATED | Nova solicitação de saque criada | ProcessHeartPayCashOutJob (log) |
| PayOutCompleted / PAYOUT_COMPLETED / payout.completed | Saque concluído com sucesso | ProcessHeartPayCashOutJob |
| PayOutFailed / PAYOUT_FAILED / payout.failed | Falha na transferência (valor devolvido ao saldo) | ProcessHeartPayCashOutJob |
| PAYOUT_APPROVED | Saque aprovado pelo admin (modo manual) | ProcessHeartPayCashOutJob |
| PAYOUT_REJECTED | Saque rejeitado pelo admin | ProcessHeartPayCashOutJob |
| PayOutRefunded | Destinatário devolveu o PIX; valor creditado de volta | ProcessHeartPayRefundJob |
| DisputeCreated | Disputa/MED aberta; valor bloqueado | ProcessHeartPayDisputeJob |
| DisputeCanceled | Disputa resolvida a favor do seller | ProcessHeartPayDisputeJob |

**Estrutura do payload:** o evento vem em `event` (raiz) e os dados em `data.data` (aninhado). O controller passa `data` para o job; o job usa `$inner = $this->data['data'] ?? $this->data` para ler os campos (correlationID, referenceCode, value em centavos, amount em reais, recipientName, recipientDocument, errorMessage em PayOutFailed, etc.).

**Headers:** `X-HeartPay-Signature`, `X-HeartPay-Timestamp`, `X-HeartPay-Algorithm`, `X-HeartPay-Event`, `Content-Type: application/json`. Resposta esperada: HTTP 2xx em até 5 segundos.

---

## 7. Reembolsos (POST /refunds)

| Regra | Documentação | Implementação | Status |
|------|--------------|----------------|--------|
| correlationID | Obrigatório | Enviado sempre em `createRefund()` | OK |
| value | Opcional (omitido = total) | Enviado só se `$amountReais !== null` | OK |
| comment | Opcional | Enviado se informado | OK |
| Cobrança | Apenas COMPLETED | Validado pela HeartPay (regra de negócio lado deles) | OK |

---

## 8. Troubleshooting — PayOutFailed "saldo insuficiente"

Quando o webhook **PayOutFailed** traz `errorMessage: "Não há saldo suficiente para efetuar este pagamento (HTTP 400)"` mas o painel HeartPay mostra **Saldo disponível** alto (ex.: R$ 65k), o débito do saque via API pode estar usando **outro saldo** que não o exibido na tela. Saques de R$ 1 podem concluir e de R$ 10 falhar se houver limite por transação ou contas diferentes.

**O que conferir no painel HeartPay:**

| Onde | O que verificar |
|------|------------------|
| **Carteira / Saldo** | Se existe mais de um saldo: "Saldo disponível" vs "Saldo para saque via API" ou "Conta operacional". O valor grande pode ser de uma conta; o payout pode debitar de outra (ex.: conta Woovi). |
| **API / Integrações** | Se a API Key usada no gateway (`HEARTPAY_API_KEY`) é da **mesma conta** em que aparece o saldo. Contas diferentes = saldos separados. |
| **Limites** | Se há "Limite por transação" ou "Limite para saque via API" (ex.: R$ 1 ou R$ 5) que expliquem R$ 1 passar e R$ 10 falhar. |
| **Suporte HeartPay** | Enviar um payout ID que falhou (ex.: `PAYOUT_API_7uIN9vUvZkqkaNg05u`) e perguntar: *"Qual conta/saldo é debitado nos saques via API? O painel mostra R$ X disponível; por que o retorno é saldo insuficiente?"* |

**No gateway:** o motivo exato vem no webhook (`data.data.errorMessage`). Para inspecionar na VPS: `webhook_logs.payload` do registro com `event = PayOutFailed` e `referenceCode` do saque.

---

## 9. Resumo

- **URL base, autenticação, endpoints usados pelo gateway e valores em centavos** estão alinhados com a API Reference.
- **Rate limit** (10k/15min) e tratamento de **429** (incl. Retry-After) estão cobertos.
- **Webhooks**: validação HMAC (timestamp.payload), eventos mapeados, resposta 200 e processamento assíncrono com repasse ao cliente final.
- **Reembolsos**: POST /refunds com correlationID, value opcional e comment opcional.
- **Endpoints Woovi-only** (pix-keys, qr-codes, GET transactions/:id) não implementados de propósito; podem ser adicionados depois se necessário.

Conferência final: **implementação compatível com a API Reference HeartPay** para o escopo utilizado (Gateway Coratri).
