import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { login, authenticate, getAuthHeaders, register } from '../helpers/auth.js';
import { credentials, generateRegistrationData } from '../helpers/data.js';
import { thinkTime, customMetrics } from '../helpers/utils.js';

/**
 * Testes de Autenticação
 * 
 * Testa os endpoints de login, 2FA, registro e verificação de token
 */

const config = getConfig();

// Métricas específicas
const loginSuccessRate = new Rate('login_success_rate');
const loginDuration = new Trend('login_duration', true);

export const options = {
  scenarios: {
    auth_flow: {
      executor: 'constant-vus',
      vus: 10,
      duration: '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:login}': ['p(95)<300', 'p(99)<500'],
    'http_req_duration{name:verify_token}': ['p(95)<100', 'p(99)<200'],
    'login_success_rate': ['rate>0.95'],
    'http_req_failed': ['rate<0.05'],
  },
};

export function setup() {
  console.log('=== Iniciando Testes de Autenticação ===');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log(`Usuário de teste: ${credentials.testUser.username}`);
  
  // Verifica se a API está acessível
  const healthCheck = http.get(`${config.baseUrl}/auth/login`, {
    headers: { 'Accept': 'application/json' },
  });
  
  if (healthCheck.status === 0) {
    throw new Error('API não está acessível');
  }
  
  return { startTime: Date.now() };
}

export default function (data) {
  // Grupo: Login básico
  group('Login Flow', function () {
    testLogin();
    thinkTime(500, 1000);
  });
  
  // Grupo: Verificação de token
  group('Token Verification', function () {
    testTokenVerification();
    thinkTime(300, 500);
  });
  
  // Grupo: Endpoints protegidos
  group('Protected Endpoints', function () {
    testProtectedEndpoints();
    thinkTime(500, 1000);
  });
}

function testLogin() {
  const startTime = Date.now();
  
  const payload = JSON.stringify({
    username: credentials.testUser.username,
    password: credentials.testUser.password,
  });
  
  const response = http.post(`${config.baseUrl}/auth/login`, payload, {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    tags: { name: 'login' },
  });
  
  const duration = Date.now() - startTime;
  loginDuration.add(duration);
  
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
    'login: response time < 500ms': (r) => r.timings.duration < 500,
  });
  
  loginSuccessRate.add(success);
  customMetrics.loginDuration.add(duration);
  
  if (success) {
    customMetrics.successfulLogins.add(1);
  } else {
    customMetrics.failedLogins.add(1);
    if (config.debug) {
      console.log(`Login failed: ${response.status} - ${response.body}`);
    }
  }
  
  return response;
}

function testTokenVerification() {
  // Primeiro faz login para obter token
  const token = authenticate(
    credentials.testUser.username,
    credentials.testUser.password,
    credentials.testUser.totpSecret
  );
  
  if (!token) {
    console.log('Não foi possível obter token para verificação');
    return;
  }
  
  // Verifica o token
  const response = http.get(`${config.baseUrl}/auth/verify`, {
    headers: getAuthHeaders(token),
    tags: { name: 'verify_token' },
  });
  
  check(response, {
    'verify: status 200': (r) => r.status === 200,
    'verify: valid true': (r) => {
      try {
        const body = r.json();
        return body.valid === true || body.authenticated === true;
      } catch (e) {
        return false;
      }
    },
    'verify: response time < 200ms': (r) => r.timings.duration < 200,
  });
}

function testProtectedEndpoints() {
  const token = authenticate(
    credentials.testUser.username,
    credentials.testUser.password,
    credentials.testUser.totpSecret
  );
  
  if (!token) {
    return;
  }
  
  const headers = getAuthHeaders(token);
  
  // Testa endpoint de perfil
  const profileResponse = http.get(`${config.baseUrl}/user/profile`, {
    headers,
    tags: { name: 'profile' },
  });
  
  check(profileResponse, {
    'profile: status 200': (r) => r.status === 200,
    'profile: has user data': (r) => {
      try {
        const body = r.json();
        return body.user || body.data || body.email;
      } catch (e) {
        return false;
      }
    },
  });
  
  thinkTime(200, 500);
  
  // Testa endpoint de saldo
  const balanceResponse = http.get(`${config.baseUrl}/balance`, {
    headers,
    tags: { name: 'balance' },
  });
  
  check(balanceResponse, {
    'balance: status 200': (r) => r.status === 200,
    'balance: response time < 200ms': (r) => r.timings.duration < 200,
  });
}

// Teste de registro (executar separadamente para não criar muitos usuários)
export function testRegistration() {
  const userData = generateRegistrationData();
  
  const response = http.post(
    `${config.baseUrl}/auth/register`,
    JSON.stringify(userData),
    {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      tags: { name: 'register' },
    }
  );
  
  check(response, {
    'register: status 200 or 201': (r) => r.status === 200 || r.status === 201,
    'register: response time < 1000ms': (r) => r.timings.duration < 1000,
  });
  
  return response;
}

// Teste de tentativas de login inválidas (para verificar rate limiting e segurança)
export function testInvalidLogin() {
  const response = http.post(
    `${config.baseUrl}/auth/login`,
    JSON.stringify({
      email: 'invalido@teste.com',
      password: 'senhaerrada',
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      tags: { name: 'login_invalid' },
    }
  );
  
  check(response, {
    'invalid login: status 401 or 422': (r) => r.status === 401 || r.status === 422,
    'invalid login: has error message': (r) => {
      try {
        const body = r.json();
        return body.error || body.message || body.errors;
      } catch (e) {
        return false;
      }
    },
  });
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`=== Testes de Autenticação Finalizados ===`);
  console.log(`Duração total: ${duration.toFixed(2)}s`);
}
