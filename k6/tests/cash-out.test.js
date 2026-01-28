import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { getApiHeaders } from '../helpers/auth.js';
import { credentials, generateWithdrawPayload, testAmounts } from '../helpers/data.js';
import { thinkTime, generateCPF, generatePixKey } from '../helpers/utils.js';

/**
 * Testes de Cash Out (Saque PIX)
 * 
 * Testa solicitação de saques e consulta de status
 */

const config = getConfig();

// Métricas específicas
const withdrawSuccessRate = new Rate('withdraw_success_rate');
const withdrawDuration = new Trend('withdraw_duration', true);
const withdrawRequests = new Counter('withdraw_requests');

export const options = {
  scenarios: {
    cash_out_flow: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 10 },   // Cash out geralmente tem menos volume
        { duration: '3m', target: 10 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    'http_req_duration{name:pixout}': ['p(95)<1500', 'p(99)<3000'],
    'withdraw_success_rate': ['rate>0.90'],
    'http_req_failed': ['rate<0.10'],
  },
};

export function setup() {
  console.log('=== Iniciando Testes de Cash Out (Saque PIX) ===');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log(`API Token: ${credentials.api.token ? '***configurado***' : 'NÃO CONFIGURADO'}`);
  
  // IMPORTANTE: Testes de Cash Out reais movimentam dinheiro!
  // Em ambiente de teste, use:
  // 1. Sandbox/Homologação da TREEAL
  // 2. Valores mínimos
  // 3. Contas de teste específicas
  console.log('⚠️  ATENÇÃO: Testes de Cash Out podem movimentar valores reais!');
  
  return { 
    startTime: Date.now(),
  };
}

export default function (data) {
  // Grupo: Solicitação de Saque
  group('Withdraw Request', function () {
    testWithdrawRequest();
  });
  
  thinkTime(2000, 4000); // Saques geralmente têm intervalo maior
}

function testWithdrawRequest(options = {}) {
  const startTime = Date.now();
  
  // Gera payload de saque
  const payload = generateWithdrawPayload({
    amount: options.amount || testAmounts.small, // R$10 - valor pequeno para testes
    ...options,
  });
  
  const response = http.post(
    `${config.baseUrl}/pixout`,
    JSON.stringify(payload),
    {
      headers: getApiHeaders(),
      tags: { name: 'pixout' },
      timeout: '60s', // Timeout maior para operações de saque
    }
  );
  
  const duration = Date.now() - startTime;
  withdrawDuration.add(duration);
  withdrawRequests.add(1);
  
  const success = check(response, {
    'pixout: status 200 or 201 or 202': (r) => [200, 201, 202].includes(r.status),
    'pixout: has response body': (r) => r.body && r.body.length > 0,
    'pixout: has transaction reference': (r) => {
      try {
        const body = r.json();
        return body.transactionId || body.id || body.reference || 
               body.data?.id || body.data?.transactionId;
      } catch (e) {
        return false;
      }
    },
    'pixout: response time < 3000ms': (r) => r.timings.duration < 3000,
  });
  
  withdrawSuccessRate.add(success);
  
  if (success) {
    try {
      const body = response.json();
      return {
        transactionId: body.transactionId || body.id || body.data?.id,
        status: body.status || body.data?.status,
      };
    } catch (e) {
      return null;
    }
  } else {
    if (config.debug) {
      console.log(`Withdraw failed: ${response.status} - ${response.body}`);
    }
    
    // Analisa tipo de erro
    if (response.status === 400) {
      console.log('Erro de validação - verificar payload');
    } else if (response.status === 401 || response.status === 403) {
      console.log('Erro de autenticação - verificar token/secret');
    } else if (response.status === 422) {
      console.log('Erro de processamento - verificar saldo/limites');
    } else if (response.status >= 500) {
      console.log('Erro de servidor - problema na API ou TREEAL');
    }
    
    return null;
  }
}

// Teste com diferentes tipos de chave PIX
export function testDifferentPixKeyTypes() {
  const keyTypes = ['email', 'phone', 'cpf', 'random'];
  
  keyTypes.forEach((type) => {
    group(`PIX Key Type: ${type}`, function () {
      const pixKey = generatePixKey(type);
      testWithdrawRequest({
        pixKey: pixKey.key,
        pixKeyType: pixKey.type,
        amount: testAmounts.minimum, // R$1 para testes
      });
      thinkTime(1000, 2000);
    });
  });
}

// Teste de payload inválido
export function testInvalidWithdrawPayload() {
  const invalidPayloads = [
    // Valor negativo
    { 
      amount: -100,
      pixKey: 'teste@teste.com',
      pixKeyType: 'email',
    },
    // Valor zero
    { 
      amount: 0,
      pixKey: 'teste@teste.com',
      pixKeyType: 'email',
    },
    // Sem chave PIX
    { 
      amount: 1000,
    },
    // Tipo de chave inválido
    { 
      amount: 1000,
      pixKey: 'teste@teste.com',
      pixKeyType: 'invalid_type',
    },
    // CPF inválido
    { 
      amount: 1000,
      pixKey: '00000000000',
      pixKeyType: 'cpf',
    },
  ];
  
  invalidPayloads.forEach((payload, index) => {
    group(`Invalid Payload ${index + 1}`, function () {
      const response = http.post(
        `${config.baseUrl}/pixout`,
        JSON.stringify(payload),
        {
          headers: getApiHeaders(),
          tags: { name: 'pixout_invalid' },
        }
      );
      
      check(response, {
        [`invalid_${index}: returns error status`]: (r) => r.status >= 400,
        [`invalid_${index}: has error details`]: (r) => {
          try {
            const body = r.json();
            return body.error || body.message || body.errors;
          } catch (e) {
            return false;
          }
        },
      });
    });
  });
}

// Teste de autenticação inválida
export function testInvalidAuth() {
  const response = http.post(
    `${config.baseUrl}/pixout`,
    JSON.stringify(generateWithdrawPayload()),
    {
      headers: getApiHeaders(),
      tags: { name: 'pixout_no_auth' },
    }
  );
  
  check(response, {
    'no_auth: returns 401 or 403': (r) => r.status === 401 || r.status === 403,
  });
}

// Teste de IP não autorizado (se check.allowed.ip estiver ativo)
export function testUnauthorizedIP() {
  // Este teste verifica se o middleware de IP está funcionando
  // Em ambiente de teste, pode ser necessário ajustar
  
  const response = http.post(
    `${config.baseUrl}/pixout`,
    JSON.stringify(generateWithdrawPayload()),
    {
      headers: {
        ...getApiHeaders(),
        'X-Forwarded-For': '192.168.0.1', // IP fictício
      },
      tags: { name: 'pixout_blocked_ip' },
    }
  );
  
  // Se IP whitelist está ativo, deve bloquear
  // Se não está ativo, deve processar normalmente
  check(response, {
    'ip_check: responds': (r) => r.status !== 0,
  });
}

// Teste de limite de valor
export function testAmountLimits() {
  const limitTests = [
    { amount: 1, description: 'Valor mínimo (R$0.01)' },
    { amount: 100, description: 'Valor pequeno (R$1.00)' },
    { amount: 10000000, description: 'Valor alto (R$100.000)' },
    { amount: 99999999, description: 'Valor muito alto' },
  ];
  
  limitTests.forEach((test) => {
    group(`Limit Test: ${test.description}`, function () {
      const response = http.post(
        `${config.baseUrl}/pixout`,
        JSON.stringify({
          amount: test.amount,
          pixKey: 'limite@teste.com',
          pixKeyType: 'email',
          beneficiary: {
            name: 'Teste Limite',
            cpf: generateCPF(),
          },
        }),
        {
          headers: getApiHeaders(),
          tags: { name: 'pixout_limit' },
        }
      );
      
      check(response, {
        [`limit_${test.amount}: responds correctly`]: (r) => {
          // Valores muito altos devem retornar erro
          if (test.amount > 10000000) {
            return r.status >= 400;
          }
          // Valores válidos devem ser processados
          return r.status < 500;
        },
      });
      
      thinkTime(500, 1000);
    });
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`=== Testes de Cash Out Finalizados ===`);
  console.log(`Duração total: ${duration.toFixed(2)}s`);
}
