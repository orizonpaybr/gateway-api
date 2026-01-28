import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { authenticate, getAuthHeaders, getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload, testAmounts } from '../helpers/data.js';
import { thinkTime } from '../helpers/utils.js';

/**
 * FULL JOURNEY TEST
 * 
 * Objetivo: Simular jornada completa de um usuário real
 * 
 * Fluxo simulado:
 * 1. Usuário acessa a plataforma
 * 2. Faz login
 * 3. Visualiza dashboard e saldo
 * 4. Consulta transações
 * 5. Gera QR Code PIX para depósito
 * 6. Verifica status do depósito
 * 7. Consulta extrato atualizado
 * 8. Navega entre páginas
 * 9. Logout (encerra sessão)
 * 
 * Uso:
 *   k6 run k6/scenarios/full-journey.js
 */

const config = getConfig();

// Métricas da jornada
const journeyDuration = new Trend('journey_total_duration', true);
const stepDuration = new Trend('journey_step_duration', true);
const journeySuccess = new Rate('journey_success_rate');
const completedJourneys = new Counter('completed_journeys');
const failedJourneys = new Counter('failed_journeys');

export const options = {
  scenarios: {
    user_journey: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 10 },
        { duration: '5m', target: 30 },
        { duration: '3m', target: 30 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    'journey_total_duration': ['p(95)<30000'],  // Jornada completa < 30s
    'journey_success_rate': ['rate>0.90'],
    'http_req_failed': ['rate<0.05'],
  },
};

export function setup() {
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                   FULL JOURNEY TEST                           ║');
  console.log('║          Simulação de jornada completa do usuário             ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log('');
  console.log('🚶 Jornada simulada:');
  console.log('   1. Acesso inicial');
  console.log('   2. Login');
  console.log('   3. Dashboard + Saldo');
  console.log('   4. Consulta transações');
  console.log('   5. Gera QR Code PIX');
  console.log('   6. Verifica status');
  console.log('   7. Consulta extrato');
  console.log('   8. Navegação adicional');
  console.log('');
  
  return { startTime: Date.now() };
}

export default function (data) {
  const journeyStart = Date.now();
  let journeySuccessful = true;
  let token = null;
  let generatedTransaction = null;
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 1: Acesso inicial
  // ═══════════════════════════════════════════════════════════════════════════
  group('1. Acesso Inicial', function () {
    const stepStart = Date.now();
    
    const response = http.get(config.baseUrl.replace('/api', ''), {
      tags: { name: 'journey_landing' },
    });
    
    stepDuration.add(Date.now() - stepStart);
    
    journeySuccessful = journeySuccessful && check(response, {
      'landing: acessível': (r) => r.status !== 0,
    });
    
    // Usuário olha a página inicial
    thinkTime(1000, 2000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 2: Login
  // ═══════════════════════════════════════════════════════════════════════════
  group('2. Login', function () {
    const stepStart = Date.now();
    
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
        tags: { name: 'journey_login' },
      }
    );
    
    stepDuration.add(Date.now() - stepStart);
    
    const loginSuccess = check(response, {
      'login: status 200': (r) => r.status === 200,
      'login: has token': (r) => {
        try {
          const body = r.json();
          // Token pode estar em body.token ou body.data.token
          token = body.token || body.data?.token || null;
          return token !== null || body.tempToken || body.temp_token;
        } catch (e) {
          return false;
        }
      },
    });
    
    journeySuccessful = journeySuccessful && loginSuccess;
    
    if (!token) {
      // Tenta autenticação completa (com possível 2FA)
      token = authenticate(
        credentials.testUser.username,
        credentials.testUser.password,
        credentials.testUser.totpSecret
      );
    }
    
    // Usuário espera carregar dashboard
    thinkTime(500, 1000);
  });
  
  // Se não conseguiu token, interrompe jornada
  if (!token) {
    journeySuccess.add(false);
    failedJourneys.add(1);
    console.log(`[VU${__VU}] Jornada interrompida: falha no login`);
    return;
  }
  
  const headers = getAuthHeaders(token);
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 3: Dashboard e Saldo
  // ═══════════════════════════════════════════════════════════════════════════
  group('3. Dashboard e Saldo', function () {
    const stepStart = Date.now();
    
    // Carrega saldo
    const balanceResponse = http.get(`${config.baseUrl}/balance`, {
      headers,
      tags: { name: 'journey_balance' },
    });
    
    // Carrega stats do dashboard
    const statsResponse = http.get(`${config.baseUrl}/dashboard/stats`, {
      headers,
      tags: { name: 'journey_stats' },
    });
    
    stepDuration.add(Date.now() - stepStart);
    
    journeySuccessful = journeySuccessful && check(balanceResponse, {
      'balance: status 200': (r) => r.status === 200,
    });
    
    journeySuccessful = journeySuccessful && check(statsResponse, {
      'stats: status 200': (r) => r.status === 200,
    });
    
    // Usuário analisa os números
    thinkTime(2000, 4000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 4: Consulta Transações
  // ═══════════════════════════════════════════════════════════════════════════
  group('4. Consulta Transações', function () {
    const stepStart = Date.now();
    
    const response = http.get(`${config.baseUrl}/transactions?per_page=10`, {
      headers,
      tags: { name: 'journey_transactions' },
    });
    
    stepDuration.add(Date.now() - stepStart);
    
    journeySuccessful = journeySuccessful && check(response, {
      'transactions: status 200': (r) => r.status === 200,
    });
    
    // Usuário olha as transações recentes
    thinkTime(2000, 3000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 5: Gera QR Code PIX
  // ═══════════════════════════════════════════════════════════════════════════
  group('5. Gera QR Code PIX', function () {
    const stepStart = Date.now();
    
    const payload = generateDepositPayload({
      amount: testAmounts.medium, // R$100
    });
    
    const response = http.post(
      `${config.baseUrl}/wallet/deposit/payment`,
      JSON.stringify(payload),
      {
        headers: getApiHeaders(),
        tags: { name: 'journey_qrcode' },
        timeout: '30s',
      }
    );
    
    stepDuration.add(Date.now() - stepStart);
    
    const qrSuccess = check(response, {
      'qrcode: status 200/201': (r) => r.status === 200 || r.status === 201,
      'qrcode: has code': (r) => {
        try {
          const body = r.json();
          generatedTransaction = body.transactionId || body.id || body.data?.id;
          return body.qrcode || body.pixCode || body.data?.qrcode;
        } catch (e) {
          return false;
        }
      },
    });
    
    journeySuccessful = journeySuccessful && qrSuccess;
    
    // Usuário copia/visualiza QR Code
    thinkTime(3000, 5000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 6: Verifica Status
  // ═══════════════════════════════════════════════════════════════════════════
  if (generatedTransaction) {
    group('6. Verifica Status', function () {
      const stepStart = Date.now();
      
      const response = http.post(
        `${config.baseUrl}/status`,
        JSON.stringify({ transactionId: generatedTransaction }),
        {
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          tags: { name: 'journey_status' },
        }
      );
      
      stepDuration.add(Date.now() - stepStart);
      
      check(response, {
        'status: responds': (r) => r.status === 200 || r.status === 404,
      });
      
      // Usuário aguarda/verifica status
      thinkTime(2000, 3000);
    });
  }
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 7: Consulta Extrato
  // ═══════════════════════════════════════════════════════════════════════════
  group('7. Consulta Extrato', function () {
    const stepStart = Date.now();
    
    const response = http.get(`${config.baseUrl}/extrato`, {
      headers,
      tags: { name: 'journey_extrato' },
    });
    
    stepDuration.add(Date.now() - stepStart);
    
    journeySuccessful = journeySuccessful && check(response, {
      'extrato: status 200': (r) => r.status === 200,
    });
    
    // Usuário analisa extrato
    thinkTime(2000, 4000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // ETAPA 8: Navegação Adicional
  // ═══════════════════════════════════════════════════════════════════════════
  group('8. Navegação Adicional', function () {
    const stepStart = Date.now();
    
    // Perfil
    const profileResponse = http.get(`${config.baseUrl}/user/profile`, {
      headers,
      tags: { name: 'journey_profile' },
    });
    
    check(profileResponse, {
      'profile: status 200': (r) => r.status === 200,
    });
    
    thinkTime(1000, 2000);
    
    // QR Codes anteriores
    const qrcodesResponse = http.get(`${config.baseUrl}/qrcodes`, {
      headers,
      tags: { name: 'journey_qrcodes' },
    });
    
    check(qrcodesResponse, {
      'qrcodes: status 200': (r) => r.status === 200,
    });
    
    stepDuration.add(Date.now() - stepStart);
    
    // Usuário navega um pouco mais
    thinkTime(1000, 3000);
  });
  
  // ═══════════════════════════════════════════════════════════════════════════
  // FIM DA JORNADA
  // ═══════════════════════════════════════════════════════════════════════════
  
  const totalDuration = Date.now() - journeyStart;
  journeyDuration.add(totalDuration);
  
  if (journeySuccessful) {
    journeySuccess.add(true);
    completedJourneys.add(1);
  } else {
    journeySuccess.add(false);
    failedJourneys.add(1);
  }
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                FULL JOURNEY TEST CONCLUÍDO                    ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`⏱  Duração total: ${(duration / 60).toFixed(2)} minutos`);
  console.log('');
  console.log('📊 Métricas da Jornada:');
  console.log('');
  console.log('O que observar:');
  console.log('');
  console.log('1. JORNADA COMPLETA:');
  console.log('   - journey_total_duration: tempo médio da jornada');
  console.log('   - journey_success_rate: % de jornadas bem-sucedidas');
  console.log('');
  console.log('2. ETAPAS INDIVIDUAIS:');
  console.log('   - journey_step_duration: tempo de cada etapa');
  console.log('   - Identificar gargalos específicos');
  console.log('');
  console.log('3. EXPERIÊNCIA DO USUÁRIO:');
  console.log('   - Jornada < 30s = Bom');
  console.log('   - Jornada 30-60s = Aceitável');
  console.log('   - Jornada > 60s = Precisa otimização');
  console.log('');
  console.log('💡 Este teste é o mais próximo da experiência real do usuário.');
  console.log('   Use-o para validar a experiência end-to-end.');
  console.log('');
}
