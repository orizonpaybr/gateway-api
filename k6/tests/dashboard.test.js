import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { authenticate, getAuthHeaders } from '../helpers/auth.js';
import { credentials } from '../helpers/data.js';
import { thinkTime } from '../helpers/utils.js';

/**
 * Testes de Dashboard e Consultas
 * 
 * Testa endpoints de consulta de saldo, transações, extrato, etc.
 * Estes endpoints devem ser rápidos pois podem ser cacheados.
 */

const config = getConfig();

// Métricas específicas
const querySuccessRate = new Rate('query_success_rate');
const balanceDuration = new Trend('balance_duration', true);
const transactionsDuration = new Trend('transactions_duration', true);
const dashboardDuration = new Trend('dashboard_duration', true);

export const options = {
  scenarios: {
    dashboard_queries: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },
        { duration: '2m', target: 50 },
        { duration: '30s', target: 100 },
        { duration: '2m', target: 100 },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    'http_req_duration{name:balance}': ['p(95)<100', 'p(99)<200'],
    'http_req_duration{name:transactions}': ['p(95)<200', 'p(99)<400'],
    'http_req_duration{name:extrato}': ['p(95)<300', 'p(99)<500'],
    'http_req_duration{name:dashboard_stats}': ['p(95)<200', 'p(99)<400'],
    'query_success_rate': ['rate>0.99'],
    'http_req_failed': ['rate<0.01'],
  },
};

// Cache do token para evitar login em cada iteração
let cachedToken = null;
let tokenExpiry = 0;

export function setup() {
  console.log('=== Iniciando Testes de Dashboard e Consultas ===');
  console.log(`Base URL: ${config.baseUrl}`);
  
  // Faz login inicial para obter token
  const token = authenticate(
    credentials.testUser.username,
    credentials.testUser.password,
    credentials.testUser.totpSecret
  );
  
  if (!token) {
    throw new Error('Não foi possível obter token para testes de dashboard');
  }
  
  return { 
    startTime: Date.now(),
    initialToken: token,
  };
}

function getToken(data) {
  // Usa token cacheado se ainda válido (simula sessão de usuário)
  const now = Date.now();
  if (cachedToken && tokenExpiry > now) {
    return cachedToken;
  }
  
  // Renova token
  cachedToken = authenticate(
    credentials.testUser.username,
    credentials.testUser.password,
    credentials.testUser.totpSecret
  );
  tokenExpiry = now + (23 * 60 * 60 * 1000); // 23 horas
  
  return cachedToken || data.initialToken;
}

export default function (data) {
  const token = getToken(data);
  
  if (!token) {
    console.error('Sem token válido para testes');
    return;
  }
  
  const headers = getAuthHeaders(token);
  
  // Simula navegação típica do usuário no dashboard
  
  // 1. Consulta saldo (primeira coisa que usuário vê)
  group('Balance Check', function () {
    testBalance(headers);
  });
  
  thinkTime(500, 1000);
  
  // 2. Consulta estatísticas do dashboard
  group('Dashboard Stats', function () {
    testDashboardStats(headers);
  });
  
  thinkTime(300, 800);
  
  // 3. Consulta transações recentes
  group('Transactions List', function () {
    testTransactions(headers);
  });
  
  thinkTime(500, 1500);
  
  // 4. Consulta extrato
  group('Extrato', function () {
    testExtrato(headers);
  });
  
  thinkTime(1000, 2000);
}

function testBalance(headers) {
  const startTime = Date.now();
  
  const response = http.get(`${config.baseUrl}/balance`, {
    headers,
    tags: { name: 'balance' },
  });
  
  const duration = Date.now() - startTime;
  balanceDuration.add(duration);
  
  const success = check(response, {
    'balance: status 200': (r) => r.status === 200,
    'balance: has balance value': (r) => {
      try {
        const body = r.json();
        return body.balance !== undefined || 
               body.saldo !== undefined || 
               body.data?.balance !== undefined;
      } catch (e) {
        return false;
      }
    },
    'balance: response time < 200ms': (r) => r.timings.duration < 200,
  });
  
  querySuccessRate.add(success);
  return response;
}

function testDashboardStats(headers) {
  const startTime = Date.now();
  
  const response = http.get(`${config.baseUrl}/dashboard/stats`, {
    headers,
    tags: { name: 'dashboard_stats' },
  });
  
  const duration = Date.now() - startTime;
  dashboardDuration.add(duration);
  
  const success = check(response, {
    'dashboard_stats: status 200': (r) => r.status === 200,
    'dashboard_stats: has data': (r) => {
      try {
        const body = r.json();
        return body && (body.stats || body.data || body.totalDeposits !== undefined);
      } catch (e) {
        return false;
      }
    },
    'dashboard_stats: response time < 400ms': (r) => r.timings.duration < 400,
  });
  
  querySuccessRate.add(success);
  return response;
}

function testTransactions(headers) {
  const startTime = Date.now();
  
  const response = http.get(`${config.baseUrl}/transactions`, {
    headers,
    tags: { name: 'transactions' },
  });
  
  const duration = Date.now() - startTime;
  transactionsDuration.add(duration);
  
  const success = check(response, {
    'transactions: status 200': (r) => r.status === 200,
    'transactions: has transactions array': (r) => {
      try {
        const body = r.json();
        return Array.isArray(body.transactions) || 
               Array.isArray(body.data) || 
               Array.isArray(body);
      } catch (e) {
        return false;
      }
    },
    'transactions: response time < 400ms': (r) => r.timings.duration < 400,
  });
  
  querySuccessRate.add(success);
  return response;
}

function testExtrato(headers) {
  const response = http.get(`${config.baseUrl}/extrato`, {
    headers,
    tags: { name: 'extrato' },
  });
  
  const success = check(response, {
    'extrato: status 200': (r) => r.status === 200,
    'extrato: response time < 500ms': (r) => r.timings.duration < 500,
  });
  
  querySuccessRate.add(success);
  return response;
}

// Teste de paginação
export function testTransactionsPagination(headers) {
  const pages = [1, 2, 3];
  
  pages.forEach((page) => {
    group(`Transactions Page ${page}`, function () {
      const response = http.get(`${config.baseUrl}/transactions?page=${page}&per_page=20`, {
        headers,
        tags: { name: 'transactions_paginated' },
      });
      
      check(response, {
        [`page_${page}: status 200`]: (r) => r.status === 200,
        [`page_${page}: response time < 500ms`]: (r) => r.timings.duration < 500,
      });
      
      thinkTime(200, 500);
    });
  });
}

// Teste de filtros
export function testTransactionsFilters(headers) {
  const filters = [
    { name: 'type_deposit', params: '?type=deposit' },
    { name: 'type_withdraw', params: '?type=withdraw' },
    { name: 'status_completed', params: '?status=completed' },
    { name: 'date_range', params: '?start_date=2024-01-01&end_date=2024-12-31' },
  ];
  
  filters.forEach((filter) => {
    group(`Filter: ${filter.name}`, function () {
      const response = http.get(`${config.baseUrl}/transactions${filter.params}`, {
        headers,
        tags: { name: `transactions_${filter.name}` },
      });
      
      check(response, {
        [`${filter.name}: status 200`]: (r) => r.status === 200,
        [`${filter.name}: response time < 600ms`]: (r) => r.timings.duration < 600,
      });
      
      thinkTime(300, 600);
    });
  });
}

// Teste de consulta de transação específica
export function testTransactionDetail(headers) {
  // Primeiro busca lista para pegar um ID válido
  const listResponse = http.get(`${config.baseUrl}/transactions?per_page=1`, {
    headers,
  });
  
  try {
    const body = listResponse.json();
    const transactions = body.transactions || body.data || body;
    
    if (Array.isArray(transactions) && transactions.length > 0) {
      const transactionId = transactions[0].id;
      
      const detailResponse = http.get(`${config.baseUrl}/transactions/${transactionId}`, {
        headers,
        tags: { name: 'transaction_detail' },
      });
      
      check(detailResponse, {
        'detail: status 200': (r) => r.status === 200,
        'detail: has transaction data': (r) => {
          const body = r.json();
          return body.id || body.data?.id;
        },
        'detail: response time < 300ms': (r) => r.timings.duration < 300,
      });
    }
  } catch (e) {
    console.log('Não foi possível testar detalhe de transação');
  }
}

// Teste de endpoints adicionais do dashboard
export function testAdditionalEndpoints(headers) {
  const endpoints = [
    { path: '/user/profile', name: 'profile' },
    { path: '/dashboard/transaction-summary', name: 'summary' },
    { path: '/dashboard/interactive-movement', name: 'movement' },
    { path: '/qrcodes', name: 'qrcodes' },
  ];
  
  endpoints.forEach((endpoint) => {
    group(`Endpoint: ${endpoint.name}`, function () {
      const response = http.get(`${config.baseUrl}${endpoint.path}`, {
        headers,
        tags: { name: endpoint.name },
      });
      
      check(response, {
        [`${endpoint.name}: status 200`]: (r) => r.status === 200,
        [`${endpoint.name}: response time < 500ms`]: (r) => r.timings.duration < 500,
      });
      
      thinkTime(200, 400);
    });
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`=== Testes de Dashboard Finalizados ===`);
  console.log(`Duração total: ${duration.toFixed(2)}s`);
}
