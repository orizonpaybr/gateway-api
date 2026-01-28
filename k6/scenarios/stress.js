import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter, Rate, Gauge } from 'k6/metrics';
import { getConfig } from '../config/options.js';
import { scenarioThresholds } from '../config/thresholds.js';
import { authenticate, getAuthHeaders, getApiHeaders } from '../helpers/auth.js';
import { credentials, generateDepositPayload } from '../helpers/data.js';
import { thinkTime, randomAmount } from '../helpers/utils.js';

/**
 * STRESS TEST
 * 
 * Objetivo: Encontrar os limites do sistema
 * 
 * Características:
 * - Aumenta carga gradualmente até quebrar
 * - Identifica ponto de ruptura
 * - Verifica comportamento sob stress extremo
 * - Testa recuperação do sistema
 * 
 * Uso:
 *   k6 run k6/scenarios/stress.js
 *   k6 run --out json=stress-results.json k6/scenarios/stress.js
 */

const config = getConfig();

// Métricas de stress
const responseTime = new Trend('stress_response_time', true);
const errorCount = new Counter('stress_errors');
const successRate = new Rate('stress_success_rate');
const currentVUs = new Gauge('stress_current_vus');

export const options = {
  scenarios: {
    stress_test: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        // Aquecimento
        { duration: '2m', target: 50 },
        { duration: '2m', target: 50 },
        
        // Carga moderada
        { duration: '2m', target: 100 },
        { duration: '3m', target: 100 },
        
        // Stress inicial
        { duration: '2m', target: 200 },
        { duration: '3m', target: 200 },
        
        // Stress alto
        { duration: '2m', target: 300 },
        { duration: '3m', target: 300 },
        
        // Stress extremo
        { duration: '2m', target: 400 },
        { duration: '3m', target: 400 },
        
        // Recuperação
        { duration: '2m', target: 100 },
        { duration: '2m', target: 50 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    ...scenarioThresholds.stress,
    'stress_response_time': ['p(95)<5000', 'p(99)<10000'],
    'stress_success_rate': ['rate>0.80'],
  },
};

// Cache de tokens
const tokenCache = {};

export function setup() {
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                      STRESS TEST                              ║');
  console.log('║            Encontrando os limites do sistema                  ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`Base URL: ${config.baseUrl}`);
  console.log('Perfil: Escalada até 400 VUs');
  console.log('Duração estimada: ~30 minutos');
  console.log('');
  console.log('⚠️  ATENÇÃO: Este teste pode sobrecarregar o sistema!');
  console.log('⚠️  Monitore recursos do servidor durante a execução.');
  console.log('');
  
  return { 
    startTime: Date.now(),
    breakpointFound: false,
    maxVUsReached: 0,
  };
}

function getOrCreateToken(vuId) {
  if (!tokenCache[vuId] || tokenCache[vuId].expiry < Date.now()) {
    try {
      const token = authenticate(
        credentials.testUser.username,
        credentials.testUser.password,
        credentials.testUser.totpSecret
      );
      
      if (token) {
        tokenCache[vuId] = {
          token,
          expiry: Date.now() + (22 * 60 * 60 * 1000),
        };
      }
    } catch (e) {
      // Durante stress, autenticação pode falhar
      return null;
    }
  }
  
  return tokenCache[vuId]?.token;
}

export default function (data) {
  currentVUs.add(__VU);
  
  // Mix de operações sob stress
  const rand = Math.random();
  
  if (rand < 0.30) {
    // 30% - Operações leves (consultas)
    group('Light Operations', function () {
      stressLightOperation();
    });
  } else if (rand < 0.60) {
    // 30% - Operações médias (autenticação)
    group('Medium Operations', function () {
      stressMediumOperation();
    });
  } else {
    // 40% - Operações pesadas (Cash In)
    group('Heavy Operations', function () {
      stressHeavyOperation();
    });
  }
  
  // Think time reduzido para máxima pressão
  thinkTime(100, 500);
}

function stressLightOperation() {
  const vuId = __VU;
  const token = getOrCreateToken(vuId);
  
  if (!token) {
    errorCount.add(1);
    successRate.add(false);
    return;
  }
  
  const headers = getAuthHeaders(token);
  const startTime = Date.now();
  
  const response = http.get(`${config.baseUrl}/balance`, {
    headers,
    tags: { name: 'stress_balance' },
    timeout: '30s',
  });
  
  responseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'stress_light: status ok': (r) => r.status === 200 || r.status === 429,
  });
  
  if (success) {
    successRate.add(true);
  } else {
    errorCount.add(1);
    successRate.add(false);
    
    // Log de erros durante stress
    if (response.status >= 500) {
      console.log(`[VU${__VU}] Server error: ${response.status}`);
    }
  }
}

function stressMediumOperation() {
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
      tags: { name: 'stress_login' },
      timeout: '30s',
    }
  );
  
  responseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'stress_medium: status ok': (r) => {
      // Durante stress, rate limiting (429) é aceitável
      return r.status === 200 || r.status === 429;
    },
  });
  
  if (success) {
    successRate.add(true);
  } else {
    errorCount.add(1);
    successRate.add(false);
    
    if (response.status >= 500) {
      console.log(`[VU${__VU}] Login error: ${response.status}`);
    }
  }
}

function stressHeavyOperation() {
  const startTime = Date.now();
  
  const payload = generateDepositPayload({
    amount: randomAmount(100, 10000),
  });
  
  const response = http.post(
    `${config.baseUrl}/wallet/deposit/payment`,
    JSON.stringify(payload),
    {
      headers: getApiHeaders(),
      tags: { name: 'stress_cashin' },
      timeout: '60s',
    }
  );
  
  responseTime.add(Date.now() - startTime);
  
  const success = check(response, {
    'stress_heavy: status ok': (r) => {
      // Aceita 200, 201, ou 429 (rate limit)
      return [200, 201, 429].includes(r.status);
    },
  });
  
  if (success) {
    successRate.add(true);
  } else {
    errorCount.add(1);
    successRate.add(false);
    
    if (response.status >= 500) {
      console.log(`[VU${__VU}] Cash In error: ${response.status} - ${response.body?.substring(0, 100)}`);
    }
  }
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  
  console.log('');
  console.log('╔══════════════════════════════════════════════════════════════╗');
  console.log('║                  STRESS TEST CONCLUÍDO                        ║');
  console.log('╚══════════════════════════════════════════════════════════════╝');
  console.log('');
  console.log(`⏱  Duração total: ${(duration / 60).toFixed(2)} minutos`);
  console.log('');
  console.log('📊 Análise de Stress:');
  console.log('');
  console.log('O que observar nos resultados:');
  console.log('');
  console.log('1. PONTO DE RUPTURA:');
  console.log('   - Momento em que erros aumentam significativamente');
  console.log('   - Quando P95 ultrapassa 5s');
  console.log('   - Taxa de sucesso cai abaixo de 90%');
  console.log('');
  console.log('2. RECUPERAÇÃO:');
  console.log('   - Sistema deve voltar ao normal após redução de carga');
  console.log('   - Verificar se não há degradação permanente');
  console.log('');
  console.log('3. GARGALOS:');
  console.log('   - Verificar logs do servidor');
  console.log('   - Monitorar CPU, memória, conexões de banco');
  console.log('   - Verificar filas (Redis/Queue)');
  console.log('');
  console.log('💡 Recomendação: Compare com o Load Test para entender');
  console.log('   a margem de segurança do sistema.');
  console.log('');
}
