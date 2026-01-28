import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { generateWebhookPayload, cashInStatuses, cashOutStatuses } from '../helpers/data.js';
import { thinkTime } from '../helpers/utils.js';
import crypto from 'k6/crypto';

/**
 * Testes de Webhook TREEAL
 * 
 * Simula callbacks da TREEAL para testar processamento de webhooks.
 * ATENÇÃO: Este teste simula webhooks - não use em produção sem cuidado!
 */

const config = getConfig();

// Métricas específicas
const webhookSuccessRate = new Rate('webhook_success_rate');
const webhookDuration = new Trend('webhook_duration', true);
const webhooksProcessed = new Counter('webhooks_processed');

// Secret do webhook (deve corresponder ao configurado no .env)
const WEBHOOK_SECRET = __ENV.K6_WEBHOOK_SECRET || '';

export const options = {
  scenarios: {
    webhook_processing: {
      executor: 'constant-arrival-rate',
      rate: 10,           // 10 webhooks por segundo
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 20,
      maxVUs: 50,
    },
  },
  thresholds: {
    'http_req_duration{name:webhook_treeal}': ['p(95)<200', 'p(99)<500'],
    'webhook_success_rate': ['rate>0.95'],
    'http_req_failed': ['rate<0.05'],
  },
};

export function setup() {
  console.log('=== Iniciando Testes de Webhook TREEAL ===');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log(`Webhook Secret: ${WEBHOOK_SECRET ? '***configurado***' : 'NÃO CONFIGURADO'}`);
  
  // AVISO: Webhooks em ambiente de teste
  console.log('⚠️  ATENÇÃO: Este teste simula webhooks da TREEAL');
  console.log('⚠️  Certifique-se de estar em ambiente de HOMOLOGAÇÃO');
  
  return { 
    startTime: Date.now(),
  };
}

/**
 * Gera assinatura HMAC para o webhook
 */
function generateSignature(payload) {
  if (!WEBHOOK_SECRET) {
    return '';
  }
  
  const payloadString = typeof payload === 'string' ? payload : JSON.stringify(payload);
  return crypto.hmac('sha256', WEBHOOK_SECRET, payloadString, 'hex');
}

export default function (data) {
  // Alterna entre diferentes tipos de webhook
  const webhookTypes = ['CASH_IN', 'CASH_OUT'];
  const type = webhookTypes[Math.floor(Math.random() * webhookTypes.length)];
  
  group(`Webhook ${type}`, function () {
    if (type === 'CASH_IN') {
      testCashInWebhook();
    } else {
      testCashOutWebhook();
    }
  });
}

function testCashInWebhook() {
  // Escolhe um status aleatório
  const status = cashInStatuses[Math.floor(Math.random() * cashInStatuses.length)];
  const payload = generateWebhookPayload('CASH_IN', status);
  const payloadString = JSON.stringify(payload);
  
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'User-Agent': 'TREEAL-Webhook/1.0',
  };
  
  // Adiciona assinatura se secret estiver configurado
  if (WEBHOOK_SECRET) {
    headers['X-Webhook-Signature'] = generateSignature(payload);
  }
  
  const startTime = Date.now();
  
  const response = http.post(
    `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
    payloadString,
    {
      headers,
      tags: { name: 'webhook_treeal', type: 'cash_in' },
    }
  );
  
  const duration = Date.now() - startTime;
  webhookDuration.add(duration);
  webhooksProcessed.add(1);
  
  const success = check(response, {
    'webhook_cash_in: status 200': (r) => r.status === 200,
    'webhook_cash_in: response time < 500ms': (r) => r.timings.duration < 500,
    'webhook_cash_in: has success response': (r) => {
      try {
        const body = r.json();
        return body.success === true || body.received === true || body.status === 'ok';
      } catch (e) {
        // Alguns webhooks retornam apenas 200 sem body
        return r.status === 200;
      }
    },
  });
  
  webhookSuccessRate.add(success);
  
  if (!success && config.debug) {
    console.log(`Webhook Cash In failed: ${response.status} - ${response.body}`);
  }
  
  return response;
}

function testCashOutWebhook() {
  // Escolhe um status aleatório
  const status = cashOutStatuses[Math.floor(Math.random() * cashOutStatuses.length)];
  const payload = generateWebhookPayload('CASH_OUT', status);
  const payloadString = JSON.stringify(payload);
  
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'User-Agent': 'TREEAL-Webhook/1.0',
  };
  
  // Adiciona assinatura se secret estiver configurado
  if (WEBHOOK_SECRET) {
    headers['X-Webhook-Signature'] = generateSignature(payload);
  }
  
  const startTime = Date.now();
  
  const response = http.post(
    `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
    payloadString,
    {
      headers,
      tags: { name: 'webhook_treeal', type: 'cash_out' },
    }
  );
  
  const duration = Date.now() - startTime;
  webhookDuration.add(duration);
  webhooksProcessed.add(1);
  
  const success = check(response, {
    'webhook_cash_out: status 200': (r) => r.status === 200,
    'webhook_cash_out: response time < 500ms': (r) => r.timings.duration < 500,
  });
  
  webhookSuccessRate.add(success);
  
  if (!success && config.debug) {
    console.log(`Webhook Cash Out failed: ${response.status} - ${response.body}`);
  }
  
  return response;
}

// Teste de webhook com assinatura inválida
export function testInvalidSignature() {
  const payload = generateWebhookPayload('CASH_IN', 'CONCLUIDA');
  
  const response = http.post(
    `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
    JSON.stringify(payload),
    {
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Signature': 'invalid_signature_12345',
      },
      tags: { name: 'webhook_invalid_sig' },
    }
  );
  
  // Se validação de assinatura está ativa, deve rejeitar
  check(response, {
    'invalid_sig: handled correctly': (r) => {
      // Se secret não está configurado, aceita qualquer coisa
      if (!WEBHOOK_SECRET) return r.status === 200;
      // Se secret está configurado, deve rejeitar
      return r.status === 401 || r.status === 403;
    },
  });
}

// Teste de webhook com payload inválido
export function testInvalidPayload() {
  const invalidPayloads = [
    {},                                    // Vazio
    { evento: 'INVALIDO' },               // Evento inválido
    { dados: null },                       // Dados nulos
    'not json',                            // Não é JSON
    { evento: 'PIX_RECEBIDO' },           // Sem dados
  ];
  
  invalidPayloads.forEach((payload, index) => {
    const response = http.post(
      `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
      typeof payload === 'string' ? payload : JSON.stringify(payload),
      {
        headers: {
          'Content-Type': 'application/json',
        },
        tags: { name: 'webhook_invalid_payload' },
      }
    );
    
    check(response, {
      [`invalid_payload_${index}: handled gracefully`]: (r) => {
        // Webhook deve retornar erro de validação ou aceitar graciosamente
        return r.status === 200 || r.status === 400 || r.status === 422;
      },
    });
  });
}

// Teste de idempotência (mesmo webhook duas vezes)
export function testIdempotency() {
  const payload = generateWebhookPayload('CASH_IN', 'CONCLUIDA');
  const payloadString = JSON.stringify(payload);
  
  const headers = {
    'Content-Type': 'application/json',
  };
  
  if (WEBHOOK_SECRET) {
    headers['X-Webhook-Signature'] = generateSignature(payload);
  }
  
  // Primeira chamada
  const response1 = http.post(
    `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
    payloadString,
    { headers, tags: { name: 'webhook_idempotency_1' } }
  );
  
  // Segunda chamada com mesmo payload
  const response2 = http.post(
    `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
    payloadString,
    { headers, tags: { name: 'webhook_idempotency_2' } }
  );
  
  check(response1, {
    'idempotency_1: status 200': (r) => r.status === 200,
  });
  
  check(response2, {
    'idempotency_2: status 200 (idempotent)': (r) => r.status === 200,
  });
}

// Teste de carga de webhooks (burst)
export function testWebhookBurst() {
  const burstSize = 20;
  const responses = [];
  
  for (let i = 0; i < burstSize; i++) {
    const type = i % 2 === 0 ? 'CASH_IN' : 'CASH_OUT';
    const payload = generateWebhookPayload(type, 'CONCLUIDA');
    
    const headers = {
      'Content-Type': 'application/json',
    };
    
    if (WEBHOOK_SECRET) {
      headers['X-Webhook-Signature'] = generateSignature(payload);
    }
    
    const response = http.post(
      `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
      JSON.stringify(payload),
      { headers, tags: { name: 'webhook_burst' } }
    );
    
    responses.push(response);
  }
  
  // Verifica taxa de sucesso do burst
  const successCount = responses.filter(r => r.status === 200).length;
  const successRate = successCount / burstSize;
  
  check({ successRate }, {
    'burst: >90% success rate': (data) => data.successRate > 0.9,
  });
  
  console.log(`Burst test: ${successCount}/${burstSize} successful (${(successRate * 100).toFixed(1)}%)`);
}

// Teste de todos os status possíveis
export function testAllStatuses() {
  // Cash In statuses
  group('Cash In Statuses', function () {
    cashInStatuses.forEach((status) => {
      const payload = generateWebhookPayload('CASH_IN', status);
      const response = http.post(
        `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
        JSON.stringify(payload),
        {
          headers: { 'Content-Type': 'application/json' },
          tags: { name: `webhook_status_${status}` },
        }
      );
      
      check(response, {
        [`status_${status}: handled`]: (r) => r.status === 200,
      });
      
      sleep(0.1);
    });
  });
  
  // Cash Out statuses
  group('Cash Out Statuses', function () {
    cashOutStatuses.forEach((status) => {
      const payload = generateWebhookPayload('CASH_OUT', status);
      const response = http.post(
        `${config.baseUrl.replace('/api', '')}/api/treeal/webhook`,
        JSON.stringify(payload),
        {
          headers: { 'Content-Type': 'application/json' },
          tags: { name: `webhook_status_${status}` },
        }
      );
      
      check(response, {
        [`status_${status}: handled`]: (r) => r.status === 200,
      });
      
      sleep(0.1);
    });
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`=== Testes de Webhook Finalizados ===`);
  console.log(`Duração total: ${duration.toFixed(2)}s`);
}
