# 🔒 Segurança CORS - Configuração e Riscos

## ⚠️ Por que `Access-Control-Allow-Origin: *` é PERIGOSO?

### Riscos de segurança:

1. **Cross-Site Request Forgery (CSRF)**

    - Sites maliciosos podem fazer requisições para sua API usando credenciais do usuário logado
    - Exemplo: Um site malicioso pode tentar regenerar o Client Secret de um usuário autenticado

2. **Roubo de Credenciais**

    - Um atacante pode interceptar requisições e obter credenciais sensíveis
    - Especialmente crítico para endpoints de regeneração de secrets

3. **Data Exfiltration**

    - Sites maliciosos podem ler dados da API se o usuário estiver autenticado
    - Dados pessoais, transações, saldos podem ser vazados

4. **Ataques de DDoS Facilitados**
    - Com CORS aberto, qualquer site pode fazer requisições para sua API
    - Facilita ataques coordenados de múltiplas origens

## ✅ Solução Implementada

### Middleware `SecureCors`

O middleware `SecureCors` foi criado para:

1. **Controlar origens permitidas via variáveis de ambiente**

    - Em produção: apenas a URL configurada em `FRONTEND_URL`
    - Em desenvolvimento: permite localhost em várias portas para facilitar testes

2. **Logging de tentativas de acesso não autorizadas**

    - Todas as tentativas de acesso de origens não permitidas são logadas
    - Permite identificar ataques ou configurações incorretas

3. **Suporte a requisições preflight (OPTIONS)**
    - Responde corretamente a requisições CORS preflight
    - Cache de 24 horas para melhor performance

## 📋 Configuração

### Variáveis de Ambiente (.env)

```bash
# Desenvolvimento
FRONTEND_URL=http://localhost:3000

# Produção
FRONTEND_URL=https://app.orizon.com
```

### Como Funciona

1. **Em Produção:**

    - Apenas requisições da URL configurada em `FRONTEND_URL` são aceitas
    - Qualquer outra origem é rejeitada e logada

2. **Em Desenvolvimento:**
    - Permite localhost em portas comuns (3000, 3001, 127.0.0.1)
    - Facilita desenvolvimento sem comprometer segurança em produção

## 🛡️ Rate Limiting Aplicado

Para endurecer ainda mais a segurança, foram aplicados rate limits nas rotas de integração:

-   `GET /integration/credentials`: **60 requisições/minuto**
-   `POST /integration/regenerate-secret`: **5 requisições/minuto** (mais restritivo - ação crítica)
-   `GET /integration/allowed-ips`: **60 requisições/minuto**
-   `POST /integration/allowed-ips`: **20 requisições/minuto**
-   `DELETE /integration/allowed-ips/{ip}`: **20 requisições/minuto**

## 🔍 Monitoramento

O middleware loga automaticamente tentativas de acesso de origens não permitidas:

```php
Log::warning('[CORS] Origem não permitida', [
    'origin' => $origin,
    'allowed_origins' => $allowedOrigins,
    'ip' => request()->ip(),
]);
```

**Recomendação:** Configure alertas para monitorar esses logs em produção.

## 📝 Checklist de Implantação

-   [x] Middleware `SecureCors` criado e registrado
-   [x] Rate limiting aplicado nas rotas de integração
-   [x] Headers CORS manuais removidos do `IntegrationController`
-   [ ] Configurar `FRONTEND_URL` no `.env` de produção
-   [ ] Testar CORS em ambiente de desenvolvimento
-   [ ] Configurar alertas para logs de CORS em produção
-   [ ] Revisar outras rotas da aplicação que usam `Access-Control-Allow-Origin: *`

## 🚨 Outras Rotas Vulneráveis

O código ainda possui muitas rotas com `Access-Control-Allow-Origin: *` hardcoded (ver `routes/api.php`).

**Recomendação:** Aplicar o middleware `secure.cors` em todas as rotas da API ou criar um middleware global.

---

**Última atualização:** $(date)
