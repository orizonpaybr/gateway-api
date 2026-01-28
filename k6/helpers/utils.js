import { sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';

/**
 * Utilitários gerais para testes k6
 */

// Métricas customizadas
export const customMetrics = {
  // Tempos de resposta por operação
  loginDuration: new Trend('login_duration', true),
  depositDuration: new Trend('deposit_duration', true),
  withdrawDuration: new Trend('withdraw_duration', true),
  queryDuration: new Trend('query_duration', true),
  
  // Contadores
  successfulLogins: new Counter('successful_logins'),
  failedLogins: new Counter('failed_logins'),
  successfulDeposits: new Counter('successful_deposits'),
  failedDeposits: new Counter('failed_deposits'),
  successfulWithdraws: new Counter('successful_withdraws'),
  failedWithdraws: new Counter('failed_withdraws'),
  
  // Taxas
  loginSuccessRate: new Rate('login_success_rate'),
  depositSuccessRate: new Rate('deposit_success_rate'),
  withdrawSuccessRate: new Rate('withdraw_success_rate'),
};

/**
 * Simula tempo de "pensamento" do usuário
 * @param {number} minMs - Tempo mínimo em ms
 * @param {number} maxMs - Tempo máximo em ms
 */
export function thinkTime(minMs = 500, maxMs = 2000) {
  const ms = Math.random() * (maxMs - minMs) + minMs;
  sleep(ms / 1000);
}

/**
 * Simula tempo de pensamento fixo
 * @param {number} seconds - Segundos para esperar
 */
export function wait(seconds = 1) {
  sleep(seconds);
}

/**
 * Gera um CPF válido para testes
 * @returns {string} - CPF com 11 dígitos
 */
export function generateCPF() {
  const randomDigits = () => Math.floor(Math.random() * 9);
  
  const cpf = [];
  for (let i = 0; i < 9; i++) {
    cpf.push(randomDigits());
  }
  
  // Primeiro dígito verificador
  let sum = 0;
  for (let i = 0; i < 9; i++) {
    sum += cpf[i] * (10 - i);
  }
  let digit1 = 11 - (sum % 11);
  digit1 = digit1 >= 10 ? 0 : digit1;
  cpf.push(digit1);
  
  // Segundo dígito verificador
  sum = 0;
  for (let i = 0; i < 10; i++) {
    sum += cpf[i] * (11 - i);
  }
  let digit2 = 11 - (sum % 11);
  digit2 = digit2 >= 10 ? 0 : digit2;
  cpf.push(digit2);
  
  return cpf.join('');
}

/**
 * Gera um email único para testes
 * @param {string} prefix - Prefixo do email
 * @returns {string} - Email único
 */
export function generateEmail(prefix = 'k6test') {
  const timestamp = Date.now();
  const random = Math.random().toString(36).substring(7);
  return `${prefix}_${timestamp}_${random}@teste.com`;
}

/**
 * Gera um telefone válido para testes
 * @returns {string} - Telefone com 11 dígitos
 */
export function generatePhone() {
  const ddd = Math.floor(Math.random() * 89) + 11; // 11-99
  const prefix = Math.floor(Math.random() * 9000) + 1000;
  const suffix = Math.floor(Math.random() * 9000) + 1000;
  return `${ddd}9${prefix}${suffix}`;
}

/**
 * Gera uma chave PIX aleatória
 * @param {string} type - Tipo da chave (email, phone, cpf, random)
 * @returns {object} - { key, type }
 */
export function generatePixKey(type = 'random') {
  switch (type) {
    case 'email':
      return { key: generateEmail('pix'), type: 'email' };
    case 'phone':
      return { key: `+55${generatePhone()}`, type: 'phone' };
    case 'cpf':
      return { key: generateCPF(), type: 'cpf' };
    case 'random':
    default:
      const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
      });
      return { key: uuid, type: 'evp' };
  }
}

/**
 * Gera um valor aleatório em centavos dentro de um range
 * @param {number} min - Valor mínimo em centavos
 * @param {number} max - Valor máximo em centavos
 * @returns {number}
 */
export function randomAmount(min = 100, max = 100000) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * Formata valor em centavos para reais
 * @param {number} cents - Valor em centavos
 * @returns {string} - Valor formatado
 */
export function formatCurrency(cents) {
  return `R$ ${(cents / 100).toFixed(2)}`;
}

/**
 * Gera um ID de transação único
 * @returns {string}
 */
export function generateTransactionId() {
  const timestamp = Date.now().toString(36);
  const random = Math.random().toString(36).substring(2, 8);
  return `k6_${timestamp}_${random}`.toUpperCase();
}

/**
 * Gera um endToEndId no formato do PIX/BACEN
 * @returns {string} - E2E ID com 32 caracteres
 */
export function generateEndToEndId() {
  const ispb = '12345678'; // ISPB fictício
  const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const hour = new Date().getHours().toString().padStart(2, '0');
  const random = Math.random().toString(36).substring(2, 13).toUpperCase();
  return `E${ispb}${date}${hour}${random}`.substring(0, 32);
}

/**
 * Extrai dados de uma resposta de forma segura
 * @param {object} response - Resposta HTTP
 * @param {string} path - Caminho do dado (ex: "data.user.id")
 * @param {any} defaultValue - Valor padrão se não encontrar
 * @returns {any}
 */
export function safeGet(response, path, defaultValue = null) {
  try {
    const body = typeof response.body === 'string' ? response.json() : response;
    return path.split('.').reduce((obj, key) => obj && obj[key], body) || defaultValue;
  } catch (e) {
    return defaultValue;
  }
}

/**
 * Verifica se a resposta indica erro
 * @param {object} response - Resposta HTTP
 * @returns {boolean}
 */
export function isError(response) {
  return response.status >= 400 || 
         safeGet(response, 'error') || 
         safeGet(response, 'success') === false;
}

/**
 * Log colorido para debug
 * @param {string} level - Nível (info, warn, error, success)
 * @param {string} message - Mensagem
 */
export function log(level, message) {
  const prefix = {
    info: '[INFO]',
    warn: '[WARN]',
    error: '[ERROR]',
    success: '[OK]',
  };
  console.log(`${prefix[level] || '[LOG]'} ${message}`);
}

/**
 * Calcula estatísticas de um array de números
 * @param {number[]} values - Array de valores
 * @returns {object} - { min, max, avg, p50, p90, p95, p99 }
 */
export function calculateStats(values) {
  if (!values.length) return null;
  
  const sorted = [...values].sort((a, b) => a - b);
  const sum = sorted.reduce((a, b) => a + b, 0);
  
  const percentile = (p) => {
    const index = Math.ceil((p / 100) * sorted.length) - 1;
    return sorted[Math.max(0, index)];
  };
  
  return {
    count: sorted.length,
    min: sorted[0],
    max: sorted[sorted.length - 1],
    avg: sum / sorted.length,
    p50: percentile(50),
    p90: percentile(90),
    p95: percentile(95),
    p99: percentile(99),
  };
}

/**
 * Retry com backoff exponencial
 * @param {function} fn - Função a executar
 * @param {number} maxRetries - Máximo de tentativas
 * @param {number} baseDelayMs - Delay base em ms
 * @returns {any} - Resultado da função
 */
export function retry(fn, maxRetries = 3, baseDelayMs = 1000) {
  let lastError;
  
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      return fn();
    } catch (e) {
      lastError = e;
      if (attempt < maxRetries) {
        const delay = baseDelayMs * Math.pow(2, attempt - 1);
        sleep(delay / 1000);
      }
    }
  }
  
  throw lastError;
}
