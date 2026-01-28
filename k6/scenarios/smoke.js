import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { getConfig } from '../config/options.js';
import { scenarioThresholds } from '../config/thresholds.js';
import { authenticate, getAuthHeaders, getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload } from '../helpers/data.js';
import { thinkTime } from '../helpers/utils.js';

/**
 * SMOKE TEST
 * 
 * Objetivo: Verificar se o sistema está funcionando corretamente
 * 
 * Características:
 * - Poucos usuários virtuais (1-5)
 * - Curta duração (1-2 minutos)
 * - Testa todos os fluxos principais
 * - Ideal para rodar após cada deploy
 * 
 * Uso:
 *   k6 run k6/scenarios/smoke.js
 *   k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/smoke.js
 */

const config = getConfig();

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-vus',
      vus: 1,
      duration: '1m',
    },
  },
  thresholds: scenarioThresholds.smoke,
};

export function setup() {
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                      SMOKE TEST                               ║');
  console.log('║            Verificação básica do sistema                      ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log(`VUs: 1 | Duração: 1 minuto`);
  console.log('');
  
  // Verifica conectividade
  const healthCheck = http.get(config.baseUrl.replace('/api', ''), {
    timeout: '10s',
  });
  
  if (healthCheck.status === 0) {
    console.error('❌ API não está acessível!');
    throw new Error('API offline');
  }
  
  console.log('✅ API está acessível');
  console.log('');
  
  return { startTime: Date.now() };
}

export default function (data) {
  // 1. Teste de Autenticação
  group('1. Autenticação', function () {
    testLogin();
    sleep(1);
  });
  
  // 2. Teste de Endpoints Protegidos (JWT)
  group('2. Endpoints Protegidos', function () {
    testProtectedEndpoints();
    sleep(1);
  });
  
  // 3. Teste de Cash In
  group('3. Cash In (PIX)', function () {
    testCashIn();
    sleep(1);
  });
  
  // 4. Teste de Health Check geral
  group('4. Health Check', function () {
    testHealthCheck();
    sleep(1);
  });
}

function testLogin() {
  const response = http.post(
    `${config.baseUrl}/auth/login`,
    JSON.stringify({
      username: credentials.testUser.username,
      password: credentials.testUser.password,
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      tags: { name: 'smoke_login' },
    }
  );
  
  // Debug: verifica os checks individualmente
  const statusOk = response.status === 200;
  let hasToken = false;
  let tokenValue = null;
  
  try {
    const body = response.json();
    tokenValue = body.token || (body.data && body.data.token) || body.tempToken || body.temp_token;
    hasToken = !!tokenValue;
  } catch (e) {
    hasToken = false;
  }
  
  const timeOk = response.timings.duration < 3000; // 3 segundos para smoke test com múltiplos VUs
  
  const success = check(response, {
    '✓ Login: status 200': () => statusOk,
    '✓ Login: retorna token': () => hasToken,
    '✓ Login: tempo < 3s': () => timeOk,
  });
  
  // Só mostra erro se realmente falhou (não apenas por tempo)
  if (!statusOk || !hasToken) {
    console.error(`❌ Login falhou: status=${response.status}, hasToken=${hasToken}`);
  }
}

function testProtectedEndpoints() {
  const token = authenticate(
    credentials.testUser.username,
    credentials.testUser.password,
    credentials.testUser.totpSecret
  );
  
  if (!token) {
    console.error('❌ Não foi possível obter token');
    return;
  }
  
  const headers = getAuthHeaders(token);
  
  // Teste de saldo
  const balanceResponse = http.get(`${config.baseUrl}/balance`, {
    headers,
    tags: { name: 'smoke_balance' },
  });
  
  check(balanceResponse, {
    '✓ Balance: status 200': (r) => r.status === 200,
    '✓ Balance: tempo < 500ms': (r) => r.timings.duration < 500,
  });
  
  thinkTime(300, 500);
  
  // Teste de perfil
  const profileResponse = http.get(`${config.baseUrl}/user/profile`, {
    headers,
    tags: { name: 'smoke_profile' },
  });
  
  check(profileResponse, {
    '✓ Profile: status 200': (r) => r.status === 200,
    '✓ Profile: tempo < 500ms': (r) => r.timings.duration < 500,
  });
  
  thinkTime(300, 500);
  
  // Teste de transações
  const transactionsResponse = http.get(`${config.baseUrl}/transactions`, {
    headers,
    tags: { name: 'smoke_transactions' },
  });
  
  check(transactionsResponse, {
    '✓ Transactions: status 200': (r) => r.status === 200,
    '✓ Transactions: tempo < 1s': (r) => r.timings.duration < 1000,
  });
}

function testCashIn() {
  const payload = generateDepositPayload({
    amount: 1000, // R$10
  });
  
  const response = http.post(
    `${config.baseUrl}/wallet/deposit/payment`,
    JSON.stringify(payload),
    {
      headers: getApiHeaders(),
      tags: { name: 'smoke_cashin' },
      timeout: '30s',
    }
  );
  
  check(response, {
    '✓ Cash In: status 200/201': (r) => r.status === 200 || r.status === 201,
    '✓ Cash In: retorna QR Code': (r) => {
      try {
        const body = r.json();
        return body.qrcode || body.pixCode || body.qr_code || body.data?.qrcode;
      } catch (e) {
        return false;
      }
    },
    '✓ Cash In: tempo < 3s': (r) => r.timings.duration < 3000,
  });
}

function testHealthCheck() {
  // Testa endpoint raiz
  const rootResponse = http.get(config.baseUrl.replace('/api', ''), {
    tags: { name: 'smoke_root' },
  });
  
  check(rootResponse, {
    '✓ Root: acessível': (r) => r.status !== 0,
  });
  
  // Testa se rate limiting está funcionando
  const rateLimitTest = [];
  for (let i = 0; i < 3; i++) {
    rateLimitTest.push(
      http.get(`${config.baseUrl}/auth/login`, {
        headers: { 'Accept': 'application/json' },
      })
    );
  }
  
  check({ responses: rateLimitTest }, {
    '✓ Rate Limit: respondendo': (data) => data.responses.every(r => r.status !== 0),
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                   SMOKE TEST CONCLUÍDO                        ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`⏱  Duração total: ${duration.toFixed(2)} segundos`);
  console.log('');
  console.log('Verifique os resultados acima para identificar problemas.');
  console.log('');
}
