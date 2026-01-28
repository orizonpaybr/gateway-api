/**
 * Thresholds de Performance
 * 
 * Define os limites aceitáveis de performance para cada tipo de operação.
 * Se um threshold for ultrapassado, o teste falhará.
 */

// Thresholds globais padrão
export const globalThresholds = {
  // Taxa de erro máxima de 1%
  'http_req_failed': ['rate<0.01'],
  
  // 95% das requisições devem responder em menos de 500ms
  'http_req_duration': ['p(95)<500'],
  
  // 99% das requisições devem responder em menos de 1000ms
  'http_req_duration': ['p(99)<1000'],
  
  // Tempo máximo de espera para conexão
  'http_req_connecting': ['p(95)<100'],
  
  // Todas as verificações devem passar
  'checks': ['rate>0.99'],
};

// Thresholds específicos por endpoint
export const endpointThresholds = {
  // Autenticação
  auth: {
    'http_req_duration{name:login}': ['p(95)<300', 'p(99)<500'],
    'http_req_duration{name:verify_2fa}': ['p(95)<200', 'p(99)<400'],
    'http_req_duration{name:register}': ['p(95)<500', 'p(99)<1000'],
  },
  
  // Consultas (devem ser rápidas, possivelmente cacheadas)
  queries: {
    'http_req_duration{name:balance}': ['p(95)<100', 'p(99)<200'],
    'http_req_duration{name:transactions}': ['p(95)<200', 'p(99)<400'],
    'http_req_duration{name:extrato}': ['p(95)<300', 'p(99)<500'],
    'http_req_duration{name:dashboard_stats}': ['p(95)<200', 'p(99)<400'],
  },
  
  // Cash In (PIX) - envolve comunicação com TREEAL
  cashIn: {
    'http_req_duration{name:generate_qr}': ['p(95)<1000', 'p(99)<2000'],
    'http_req_duration{name:deposit_status}': ['p(95)<200', 'p(99)<400'],
  },
  
  // Cash Out (Saque) - envolve comunicação com TREEAL
  cashOut: {
    'http_req_duration{name:pixout}': ['p(95)<1500', 'p(99)<3000'],
  },
  
  // Webhooks - devem responder rapidamente
  webhooks: {
    'http_req_duration{name:webhook_treeal}': ['p(95)<200', 'p(99)<500'],
  },
};

// Thresholds para cenários específicos
export const scenarioThresholds = {
  // Smoke test - mais tolerante
  smoke: {
    'http_req_failed': ['rate<0.05'],
    'http_req_duration': ['p(95)<1000'],
    'checks': ['rate>0.95'],
  },
  
  // Load test - padrão de produção
  load: {
    'http_req_failed': ['rate<0.01'],
    'http_req_duration': ['p(95)<500', 'p(99)<1000'],
    'checks': ['rate>0.99'],
  },
  
  // Stress test - mais tolerante (esperamos degradação)
  stress: {
    'http_req_failed': ['rate<0.05'],
    'http_req_duration': ['p(95)<2000', 'p(99)<5000'],
    'checks': ['rate>0.90'],
  },
  
  // Spike test - tolerante durante o pico
  spike: {
    'http_req_failed': ['rate<0.10'],
    'http_req_duration': ['p(95)<3000'],
    'checks': ['rate>0.85'],
  },
};

// Combina thresholds globais com específicos
export function getThresholds(scenario = 'load', endpoints = []) {
  let thresholds = { ...scenarioThresholds[scenario] || globalThresholds };
  
  endpoints.forEach(endpoint => {
    if (endpointThresholds[endpoint]) {
      thresholds = { ...thresholds, ...endpointThresholds[endpoint] };
    }
  });
  
  return thresholds;
}

// SLOs (Service Level Objectives) recomendados para produção
export const productionSLOs = {
  availability: '99.9%',         // Uptime esperado
  latencyP50: '100ms',           // Mediana de latência
  latencyP95: '500ms',           // Percentil 95
  latencyP99: '1000ms',          // Percentil 99
  errorRate: '0.1%',             // Taxa de erro máxima
  throughput: '100 req/s',       // Throughput mínimo
};
