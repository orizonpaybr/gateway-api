import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { scenarioThresholds } from '../config/thresholds.js';
import { authenticate, getAuthHeaders, getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload, generateWithdrawPayload } from '../helpers/data.js';
import { thinkTime, randomAmount } from '../helpers/utils.js';

/**
 * LOAD TEST
 * 
 * Objetivo: Validar performance sob carga normal de produção
 * 
 * Características:
 * - Ramp-up gradual até 100 usuários
 * - Duração de 10-15 minutos
 * - Simula padrão de uso real
 * - Valida SLOs de produção
 * 
 * Uso:
 *   k6 run k6/scenarios/load.js
 *   k6 run --vus 50 --duration 5m k6/scenarios/load.js
 */

const config = getConfig();

// Métricas customizadas
const loginTime = new Trend('custom_login_time', true);
const queryTime = new Trend('custom_query_time', true);
const cashInTime = new Trend('custom_cashin_time', true);
const successfulOps = new Counter('successful_operations');
const failedOps = new Counter('failed_operations');
const overallSuccessRate = new Rate('overall_success_rate');

export const options = {
  scenarios: {
    load_test: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 25 },   // Ramp-up inicial
        { duration: '3m', target: 25 },   // Estabiliza
        { duration: '2m', target: 50 },   // Aumenta carga
        { duration: '5m', target: 50 },   // Carga sustentada
        { duration: '2m', target: 75 },   // Pico moderado
        { duration: '3m', target: 75 },   // Mantém pico
        { duration: '2m', target: 50 },   // Reduz
        { duration: '1m', target: 0 },    // Ramp-down
      ],
    },
  },
  thresholds: {
    ...scenarioThresholds.load,
    'custom_login_time': ['p(95)<500'],
    'custom_query_time': ['p(95)<300'],
    'custom_cashin_time': ['p(95)<2000'],
    'overall_success_rate': ['rate>0.95'],
  },
};

// Cache de tokens por VU
const tokenCache = {};

export function setup() {
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                       LOAD TEST                               ║');
  console.log('║           Teste de carga normal de produção                   ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log('Perfil: Ramp-up gradual até 75 VUs');
  console.log('Duração estimada: ~20 minutos');
  console.log('');
  
  // Aquecimento - faz algumas requisições iniciais
  console.log('Aquecendo o sistema...');
  for (let i = 0; i < 5; i++) {
    http.get(config.baseUrl.replace('/api', ''));
  }
  console.log('✅ Sistema aquecido');
  console.log('');
  
  return { startTime: Date.now() };
}

function getOrCreateToken(vuId) {
  if (!tokenCache[vuId] || tokenCache[vuId].expiry < Date.now()) {
    const token = authenticate(
      credentials.testUser.username,
      credentials.testUser.password,
      credentials.testUser.totpSecret
    );
    
    if (token) {
      tokenCache[vuId] = {
        token,
        expiry: Date.now() + (22 * 60 * 60 * 1000), // 22 horas
      };
    }
  }
  
  return tokenCache[vuId]?.token;
}

export default function (data) {
  const vuId = __VU;
  
  // Distribui a carga entre diferentes tipos de operação
  // Simula padrão real de uso
  const rand = Math.random();
  
  if (rand < 0.10) {
    // 10% - Login (usuários novos acessando)
    group('Login Flow', function () {
      performLogin();
    });
  } else if (rand < 0.50) {
    // 40% - Consultas (dashboard, saldo, transações)
    group('Dashboard Queries', function () {
      performQueries(vuId);
    });
  } else if (rand < 0.80) {
    // 30% - Cash In (geração de QR Code)
    group('Cash In Operations', function () {
      performCashIn();
    });
  } else {
    // 20% - Operações diversas
    group('Mixed Operations', function () {
      performMixedOperations(vuId);
    });
  }
  
  // Think time entre operações
  thinkTime(1000, 3000);
}

function performLogin() {
  const startTime = Date.now();
  
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
      tags: { name: 'login' },
    }
  );
  
  loginTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'login: status 200': (r) => r.status === 200,
    'login: has token': (r) => {
      try {
        const body = r.json();
        return body.token || body.data?.token || body.tempToken || body.temp_token;
      } catch (e) {
        return false;
      }
    },
  });
  
  if (success) {
    successfulOps.add(1);
  } else {
    failedOps.add(1);
  }
  overallSuccessRate.add(success);
}

function performQueries(vuId) {
  const token = getOrCreateToken(vuId);
  
  if (!token) {
    failedOps.add(1);
    overallSuccessRate.add(false);
    return;
  }
  
  const headers = getAuthHeaders(token);
  const startTime = Date.now();
  
  // Consulta saldo
  const balanceResponse = http.get(`${config.baseUrl}/balance`, {
    headers,
    tags: { name: 'balance' },
  });
  
  queryTime.add(Date.now() - startTime);
  
  let success = check(balanceResponse, {
    'balance: status 200': (r) => r.status === 200,
  });
  
  thinkTime(200, 500);
  
  // Consulta transações
  const txResponse = http.get(`${config.baseUrl}/transactions?per_page=10`, {
    headers,
    tags: { name: 'transactions' },
  });
  
  success = success && check(txResponse, {
    'transactions: status 200': (r) => r.status === 200,
  });
  
  thinkTime(300, 700);
  
  // Dashboard stats
  const statsResponse = http.get(`${config.baseUrl}/dashboard/stats`, {
    headers,
    tags: { name: 'dashboard_stats' },
  });
  
  success = success && check(statsResponse, {
    'dashboard: status 200': (r) => r.status === 200,
  });
  
  if (success) {
    successfulOps.add(3);
  } else {
    failedOps.add(1);
  }
  overallSuccessRate.add(success);
}

function performCashIn() {
  const startTime = Date.now();
  
  const payload = generateDepositPayload({
    amount: randomAmount(500, 50000), // R$5 a R$500
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
  
  cashInTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'cashin: status 200/201': (r) => r.status === 200 || r.status === 201,
    'cashin: has qrcode': (r) => {
      try {
        const body = r.json();
        return body.qrcode || body.pixCode || body.data?.qrcode;
      } catch (e) {
        return false;
      }
    },
  });
  
  if (success) {
    successfulOps.add(1);
  } else {
    failedOps.add(1);
  }
  overallSuccessRate.add(success);
}

function performMixedOperations(vuId) {
  const token = getOrCreateToken(vuId);
  
  if (!token) {
    failedOps.add(1);
    overallSuccessRate.add(false);
    return;
  }
  
  const headers = getAuthHeaders(token);
  
  // Perfil do usuário
  const profileResponse = http.get(`${config.baseUrl}/user/profile`, {
    headers,
    tags: { name: 'profile' },
  });
  
  let success = check(profileResponse, {
    'profile: status 200': (r) => r.status === 200,
  });
  
  thinkTime(500, 1000);
  
  // Extrato
  const extratoResponse = http.get(`${config.baseUrl}/extrato`, {
    headers,
    tags: { name: 'extrato' },
  });
  
  success = success && check(extratoResponse, {
    'extrato: status 200': (r) => r.status === 200,
  });
  
  thinkTime(300, 600);
  
  // QR Codes
  const qrcodesResponse = http.get(`${config.baseUrl}/qrcodes`, {
    headers,
    tags: { name: 'qrcodes' },
  });
  
  success = success && check(qrcodesResponse, {
    'qrcodes: status 200': (r) => r.status === 200,
  });
  
  if (success) {
    successfulOps.add(3);
  } else {
    failedOps.add(1);
  }
  overallSuccessRate.add(success);
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                   LOAD TEST CONCLUÍDO                         ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`⏱  Duração total: ${(duration / 60).toFixed(2)} minutos`);
  console.log('');
  console.log('📊 Métricas principais estão no relatório acima.');
  console.log('');
  console.log('🎯 SLOs de Produção:');
  console.log('   - P95 < 500ms para consultas');
  console.log('   - P95 < 2s para Cash In');
  console.log('   - Taxa de sucesso > 95%');
  console.log('');
}
