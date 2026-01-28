import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload, testAmounts } from '../helpers/data.js';
import { thinkTime, generateTransactionId } from '../helpers/utils.js';

/**
 * Testes de Cash In (PIX)
 * 
 * Testa geração de QR Code PIX e consulta de status de depósitos
 */

const config = getConfig();

// Métricas específicas
const depositSuccessRate = new Rate('deposit_success_rate');
const depositDuration = new Trend('deposit_duration', true);
const qrCodeGenerations = new Counter('qrcode_generations');

export const options = {
  scenarios: {
    cash_in_flow: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 20 },
        { duration: '3m', target: 20 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    'http_req_duration{name:generate_qr}': ['p(95)<1000', 'p(99)<2000'],
    'http_req_duration{name:deposit_status}': ['p(95)<200', 'p(99)<400'],
    'deposit_success_rate': ['rate>0.95'],
    'http_req_failed': ['rate<0.05'],
  },
};

export function setup() {
  console.log('=== Iniciando Testes de Cash In (PIX) ===');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log(`API Token: ${credentials.api.token ? '***configurado***' : 'NÃO CONFIGURADO'}`);
  
  return { 
    startTime: Date.now(),
    generatedQRCodes: [],
  };
}

export default function (data) {
  // Grupo: Geração de QR Code PIX
  group('QR Code Generation', function () {
    const qrResult = testGenerateQRCode();
    if (qrResult && qrResult.transactionId) {
      // Armazena para consulta posterior (em cenário real)
      thinkTime(1000, 2000);
      testCheckDepositStatus(qrResult.transactionId);
    }
  });
  
  thinkTime(500, 1500);
  
  // Grupo: Consultas de status
  group('Deposit Status Check', function () {
    testStatusEndpoint();
  });
}

function testGenerateQRCode(options = {}) {
  const startTime = Date.now();
  const payload = generateDepositPayload({
    amount: options.amount || testAmounts.medium, // R$100
    ...options,
  });
  
  const response = http.post(
    `${config.baseUrl}/wallet/deposit/payment`,
    JSON.stringify(payload),
    {
      headers: getApiHeaders(),
      tags: { name: 'generate_qr' },
      timeout: '30s',
    }
  );
  
  const duration = Date.now() - startTime;
  depositDuration.add(duration);
  
  const success = check(response, {
    'generate_qr: status 200 or 201': (r) => r.status === 200 || r.status === 201,
    'generate_qr: has qrcode or pixCode': (r) => {
      try {
        const body = r.json();
        return body.qrcode || body.pixCode || body.qr_code || body.data?.qrcode;
      } catch (e) {
        return false;
      }
    },
    'generate_qr: has transactionId': (r) => {
      try {
        const body = r.json();
        return body.transactionId || body.transaction_id || body.id || body.data?.id;
      } catch (e) {
        return false;
      }
    },
    'generate_qr: response time < 2000ms': (r) => r.timings.duration < 2000,
  });
  
  depositSuccessRate.add(success);
  qrCodeGenerations.add(1);
  
  if (success) {
    try {
      const body = response.json();
      return {
        transactionId: body.transactionId || body.transaction_id || body.id || body.data?.id,
        qrCode: body.qrcode || body.pixCode || body.qr_code || body.data?.qrcode,
        expiresAt: body.expiresAt || body.expires_at,
      };
    } catch (e) {
      return null;
    }
  } else {
    if (config.debug) {
      console.log(`QR Code generation failed: ${response.status} - ${response.body}`);
    }
    return null;
  }
}

function testCheckDepositStatus(transactionId) {
  if (!transactionId) return null;
  
  const payload = JSON.stringify({
    transactionId: transactionId,
  });
  
  const response = http.post(
    `${config.baseUrl}/status`,
    payload,
    {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      tags: { name: 'deposit_status' },
    }
  );
  
  check(response, {
    'status: status 200': (r) => r.status === 200,
    'status: has status field': (r) => {
      try {
        const body = r.json();
        return body.status || body.data?.status;
      } catch (e) {
        return false;
      }
    },
    'status: response time < 400ms': (r) => r.timings.duration < 400,
  });
  
  return response;
}

function testStatusEndpoint() {
  // Testa consulta de status com ID fictício (deve retornar 404 ou status apropriado)
  const response = http.post(
    `${config.baseUrl}/status`,
    JSON.stringify({ transactionId: `k6_test_${Date.now()}` }),
    {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      tags: { name: 'deposit_status_check' },
    }
  );
  
  check(response, {
    'status_check: responds correctly': (r) => r.status === 200 || r.status === 404,
    'status_check: response time < 500ms': (r) => r.timings.duration < 500,
  });
}

// Teste de diferentes valores
export function testDifferentAmounts() {
  const amounts = [
    testAmounts.minimum,
    testAmounts.small,
    testAmounts.medium,
    testAmounts.large,
  ];
  
  amounts.forEach((amount, index) => {
    group(`Amount Test ${index + 1}: R$${(amount/100).toFixed(2)}`, function () {
      testGenerateQRCode({ amount });
      thinkTime(500, 1000);
    });
  });
}

// Teste de carga de geração de QR Codes
export function testQRCodeBurst() {
  // Gera múltiplos QR Codes em sequência rápida
  for (let i = 0; i < 5; i++) {
    testGenerateQRCode();
    sleep(0.2); // 200ms entre cada
  }
}

// Teste de payload inválido
export function testInvalidPayload() {
  const invalidPayloads = [
    { amount: -100 },                    // Valor negativo
    { amount: 0 },                       // Valor zero
    {},                                   // Payload vazio
    { amount: 999999999 },               // Valor muito alto
  ];
  
  invalidPayloads.forEach((payload, index) => {
    const response = http.post(
      `${config.baseUrl}/wallet/deposit/payment`,
      JSON.stringify(payload),
      {
        headers: getApiHeaders(),
        tags: { name: 'generate_qr_invalid' },
      }
    );
    
    check(response, {
      [`invalid_${index}: returns error status`]: (r) => r.status >= 400,
      [`invalid_${index}: has error message`]: (r) => {
        try {
          const body = r.json();
          return body.error || body.message || body.errors;
        } catch (e) {
          return false;
        }
      },
    });
  });
}

// Teste de autenticação inválida
export function testInvalidAuth() {
  const response = http.post(
    `${config.baseUrl}/wallet/deposit/payment`,
    JSON.stringify(generateDepositPayload()),
    {
      headers: getApiHeaders(),
      tags: { name: 'generate_qr_no_auth' },
    }
  );
  
  check(response, {
    'no_auth: returns 401 or 403': (r) => r.status === 401 || r.status === 403,
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`=== Testes de Cash In Finalizados ===`);
  console.log(`Duração total: ${duration.toFixed(2)}s`);
}
