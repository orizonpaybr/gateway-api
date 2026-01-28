/**
 * Configurações de cenários para testes k6
 * 
 * Este arquivo define os diferentes perfis de carga que podem ser usados
 * nos testes. Cada cenário tem um propósito específico.
 */

// Cenários pré-definidos
export const scenarios = {
  // Smoke Test - Verificação básica
  smoke: {
    executor: 'constant-vus',
    vus: 1,
    duration: '1m',
  },

  // Load Test - Carga normal esperada
  load: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 50 },   // Ramp-up para 50 usuários
      { duration: '5m', target: 50 },   // Mantém 50 usuários
      { duration: '2m', target: 100 },  // Aumenta para 100 usuários
      { duration: '5m', target: 100 },  // Mantém 100 usuários
      { duration: '2m', target: 0 },    // Ramp-down
    ],
  },

  // Stress Test - Encontrar limites
  stress: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 100 },  // Ramp-up para 100
      { duration: '3m', target: 100 },  // Mantém
      { duration: '2m', target: 200 },  // Aumenta para 200
      { duration: '3m', target: 200 },  // Mantém
      { duration: '2m', target: 300 },  // Aumenta para 300
      { duration: '3m', target: 300 },  // Mantém
      { duration: '2m', target: 400 },  // Aumenta para 400
      { duration: '3m', target: 400 },  // Mantém
      { duration: '3m', target: 0 },    // Ramp-down
    ],
  },

  // Spike Test - Picos repentinos
  spike: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '30s', target: 10 },   // Aquecimento
      { duration: '1m', target: 10 },    // Carga normal
      { duration: '10s', target: 200 },  // SPIKE!
      { duration: '2m', target: 200 },   // Mantém pico
      { duration: '10s', target: 10 },   // Volta ao normal
      { duration: '1m', target: 10 },    // Recuperação
      { duration: '30s', target: 0 },    // Ramp-down
    ],
  },

  // Soak Test - Teste de longa duração (verificar memory leaks)
  soak: {
    executor: 'constant-vus',
    vus: 50,
    duration: '30m',
  },

  // Breakpoint Test - Encontrar ponto de ruptura
  breakpoint: {
    executor: 'ramping-arrival-rate',
    startRate: 10,
    timeUnit: '1s',
    preAllocatedVUs: 500,
    maxVUs: 1000,
    stages: [
      { duration: '2m', target: 50 },
      { duration: '2m', target: 100 },
      { duration: '2m', target: 200 },
      { duration: '2m', target: 300 },
      { duration: '2m', target: 400 },
      { duration: '2m', target: 500 },
    ],
  },
};

// Configuração de stages para diferentes intensidades
export const stages = {
  // Carga leve - para testes iniciais
  light: [
    { duration: '1m', target: 10 },
    { duration: '3m', target: 10 },
    { duration: '1m', target: 0 },
  ],

  // Carga média - simulação do dia-a-dia
  medium: [
    { duration: '2m', target: 50 },
    { duration: '5m', target: 50 },
    { duration: '2m', target: 0 },
  ],

  // Carga pesada - horários de pico
  heavy: [
    { duration: '2m', target: 100 },
    { duration: '5m', target: 100 },
    { duration: '2m', target: 150 },
    { duration: '5m', target: 150 },
    { duration: '2m', target: 0 },
  ],
};

// Configurações de execução por ambiente
export const environments = {
  local: {
    baseUrl: 'http://localhost:8000/api',
    thinkTime: 500,
    timeout: '30s',
  },
  staging: {
    baseUrl: 'https://staging-api.exemplo.com/api',
    thinkTime: 1000,
    timeout: '30s',
  },
  production: {
    baseUrl: 'https://api.exemplo.com/api',
    thinkTime: 1000,
    timeout: '60s',
  },
};

// Função para obter configurações baseadas em variáveis de ambiente
export function getConfig() {
  return {
    baseUrl: __ENV.BASE_URL || __ENV.K6_BASE_URL || 'http://localhost:8000/api',
    vus: parseInt(__ENV.K6_VUS) || 10,
    duration: __ENV.K6_DURATION || '30s',
    thinkTime: parseInt(__ENV.K6_THINK_TIME) || 1000,
    timeout: __ENV.K6_HTTP_TIMEOUT || '30000',
    debug: __ENV.K6_DEBUG === 'true',
  };
}
