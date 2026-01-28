import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { scenarioThresholds } from '../config/thresholds.js';
import { authenticate, getAuthHeaders, getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload } from '../helpers/data.js';
import { thinkTime, randomAmount } from '../helpers/utils.js';

/**
 * SPIKE TEST
 * 
 * Objetivo: Testar comportamento com picos repentinos de tráfego
 * 
 * Características:
 * - Picos instantâneos de carga
 * - Simula eventos virais, campanhas, promoções
 * - Verifica elasticidade do sistema
 * - Testa auto-scaling (se houver)
 * 
 * Uso:
 *   k6 run k6/scenarios/spike.js
 */

const config = getConfig();

// Métricas de spike
const spikeResponseTime = new Trend('spike_response_time', true);
const spikeErrors = new Counter('spike_errors');
const spikeSuccessRate = new Rate('spike_success_rate');
const recoveryTime = new Trend('spike_recovery_time', true);

export const options = {
  scenarios: {
    spike_test: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        // Baseline - carga normal
        { duration: '1m', target: 20 },
        { duration: '2m', target: 20 },
        
        // SPIKE 1 - Pico moderado
        { duration: '10s', target: 100 },   // Subida rápida!
        { duration: '1m', target: 100 },    // Mantém pico
        { duration: '10s', target: 20 },    // Volta ao normal
        { duration: '1m', target: 20 },     // Recuperação
        
        // SPIKE 2 - Pico alto
        { duration: '10s', target: 200 },   // Subida rápida!
        { duration: '1m', target: 200 },    // Mantém pico
        { duration: '10s', target: 20 },    // Volta ao normal
        { duration: '1m', target: 20 },     // Recuperação
        
        // SPIKE 3 - Pico extremo
        { duration: '5s', target: 300 },    // Subida muito rápida!
        { duration: '30s', target: 300 },   // Mantém pico curto
        { duration: '10s', target: 20 },    // Volta ao normal
        { duration: '2m', target: 20 },     // Recuperação prolongada
        
        // Ramp-down
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    ...scenarioThresholds.spike,
    'spike_response_time': ['p(95)<5000'],
    'spike_success_rate': ['rate>0.75'],  // Mais tolerante durante picos
  },
};

// Marca de tempo para calcular recuperação
let spikeStart = 0;
let inSpike = false;

export function setup() {
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                       SPIKE TEST                              ║');
  console.log('║           Teste de picos repentinos de tráfego                ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log('Perfil: 3 spikes progressivos (100 → 200 → 300 VUs)');
  console.log('Duração estimada: ~12 minutos');
  console.log('');
  console.log('📈 Cenário simulado:');
  console.log('   - Evento viral / campanha de marketing');
  console.log('   - Picos de Black Friday / promoções relâmpago');
  console.log('   - Menções em redes sociais');
  console.log('');
  
  return { 
    startTime: Date.now(),
    spikeTimes: [],
    recoveryTimes: [],
  };
}

export default function (data) {
  const currentVUs = __VU;
  
  // Detecta início e fim de spike para métricas de recuperação
  if (currentVUs > 50 && !inSpike) {
    inSpike = true;
    spikeStart = Date.now();
  } else if (currentVUs <= 30 && inSpike) {
    inSpike = false;
    if (spikeStart > 0) {
      recoveryTime.add(Date.now() - spikeStart);
    }
  }
  
  // Mix de operações durante spike
  const rand = Math.random();
  
  if (rand < 0.40) {
    // 40% - Consultas rápidas (mais comum durante spikes)
    group('Quick Queries', function () {
      performQuickQuery();
    });
  } else if (rand < 0.70) {
    // 30% - Login (novos usuários chegando)
    group('Login Surge', function () {
      performLogin();
    });
  } else {
    // 30% - Cash In (conversões)
    group('Cash In Burst', function () {
      performCashIn();
    });
  }
  
  // Think time mínimo durante spike para máxima pressão
  sleep(Math.random() * 0.5 + 0.1); // 100-600ms
}

function performQuickQuery() {
  const startTime = Date.now();
  
  // Requisição simples sem autenticação (landing page, status)
  const response = http.get(
    `${config.baseUrl}/status`,
    {
      headers: {
        'Content-Type': 'application/json',
      },
      tags: { name: 'spike_status' },
      timeout: '15s',
    }
  );
  
  spikeResponseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'spike_query: responds': (r) => r.status !== 0,
    'spike_query: not server error': (r) => r.status < 500,
  });
  
  if (success) {
    spikeSuccessRate.add(true);
  } else {
    spikeErrors.add(1);
    spikeSuccessRate.add(false);
  }
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
      tags: { name: 'spike_login' },
      timeout: '20s',
    }
  );
  
  spikeResponseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'spike_login: status ok': (r) => {
      // Durante spike, rate limiting é esperado
      return r.status === 200 || r.status === 429;
    },
  });
  
  if (success) {
    spikeSuccessRate.add(true);
  } else {
    spikeErrors.add(1);
    spikeSuccessRate.add(false);
    
    if (response.status >= 500) {
      console.log(`[SPIKE] Login error at VU${__VU}: ${response.status}`);
    }
  }
}

function performCashIn() {
  const startTime = Date.now();
  
  const payload = generateDepositPayload({
    amount: randomAmount(100, 5000),
  });
  
  const response = http.post(
    `${config.baseUrl}/wallet/deposit/payment`,
    JSON.stringify(payload),
    {
      headers: getApiHeaders(),
      tags: { name: 'spike_cashin' },
      timeout: '30s',
    }
  );
  
  spikeResponseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'spike_cashin: status ok': (r) => {
      return [200, 201, 429].includes(r.status);
    },
  });
  
  if (success) {
    spikeSuccessRate.add(true);
  } else {
    spikeErrors.add(1);
    spikeSuccessRate.add(false);
    
    if (response.status >= 500) {
      console.log(`[SPIKE] Cash In error at VU${__VU}: ${response.status}`);
    }
  }
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                   SPIKE TEST CONCLUÍDO                        ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`⏱  Duração total: ${(duration / 60).toFixed(2)} minutos`);
  console.log('');
  console.log('📊 Análise de Spike:');
  console.log('');
  console.log('O que observar nos resultados:');
  console.log('');
  console.log('1. DURANTE O SPIKE:');
  console.log('   - Taxa de erro aumenta? Quanto?');
  console.log('   - Tempo de resposta degrada? Quanto?');
  console.log('   - Rate limiting está funcionando?');
  console.log('');
  console.log('2. RECUPERAÇÃO:');
  console.log('   - Sistema volta ao normal rapidamente?');
  console.log('   - Há "ressaca" após o spike (erros persistentes)?');
  console.log('   - Conexões de banco são liberadas?');
  console.log('');
  console.log('3. CAPACIDADE:');
  console.log('   - Qual foi o pico máximo sustentável?');
  console.log('   - Sistema precisa de auto-scaling?');
  console.log('');
  console.log('💡 Se a taxa de erro durante spikes > 25%, considere:');
  console.log('   - Implementar queue para operações pesadas');
  console.log('   - Configurar auto-scaling');
  console.log('   - Ajustar rate limiting');
  console.log('   - Adicionar cache agressivo');
  console.log('');
}
