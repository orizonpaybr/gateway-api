import { generateCPF, generateEmail, generatePhone, generatePixKey, randomAmount } from './utils.js';

/**
 * Dados de teste para os cenários k6
 * 
 * Contém dados estáticos e funções para gerar dados dinâmicos
 */

// Credenciais do ambiente (via variáveis de ambiente)
export const credentials = {
  // Usuário para testes de autenticação JWT
  testUser: {
    username: __ENV.K6_TEST_USERNAME || __ENV.K6_TEST_EMAIL || 'k6test@exemplo.com',
    password: __ENV.K6_TEST_PASSWORD || 'senha123',
    totpSecret: __ENV.K6_TEST_2FA_SECRET || null,
  },
  
  // Credenciais de integração (token/secret)
  api: {
    token: __ENV.K6_API_TOKEN || 'test_token',
    secret: __ENV.K6_API_SECRET || 'test_secret',
  },
};

// Pool de usuários para testes com múltiplos usuários
// Em produção, você pode carregar de um arquivo CSV
export const userPool = [
  { email: 'user1@teste.com', password: 'senha123' },
  { email: 'user2@teste.com', password: 'senha123' },
  { email: 'user3@teste.com', password: 'senha123' },
  { email: 'user4@teste.com', password: 'senha123' },
  { email: 'user5@teste.com', password: 'senha123' },
];

/**
 * Retorna um usuário aleatório do pool
 */
export function getRandomUser() {
  return userPool[Math.floor(Math.random() * userPool.length)];
}

/**
 * Gera dados para registro de novo usuário
 */
export function generateRegistrationData() {
  const cpf = generateCPF();
  return {
    name: `Usuário Teste ${Date.now()}`,
    email: generateEmail('register'),
    password: 'SenhaForte123!',
    password_confirmation: 'SenhaForte123!',
    cpf: cpf,
    phone: generatePhone(),
    birth_date: '1990-01-15',
    accept_terms: true,
  };
}

/**
 * Gera payload para depósito PIX (Cash In)
 * NOTA: Token e Secret são adicionados automaticamente pelo teste
 */
export function generateDepositPayload(options = {}) {
  const amount = options.amount || randomAmount(1000, 50000); // R$10 a R$500
  
  return {
    // Credenciais de API (obrigatórios)
    token: credentials.api.token,
    secret: credentials.api.secret,
    // Dados do depósito
    amount: amount,
    debtor_name: options.debtorName || options.payerName || 'Cliente Teste k6',
    debtor_document_number: options.debtorCpf || options.payerCpf || generateCPF(),
    email: options.email || 'k6test@teste.com',
    phone: options.phone || generatePhone(),
    method_pay: options.methodPay || 'pix',
    postback: options.postback || options.callbackUrl || 'https://webhook.site/k6-test',
  };
}

/**
 * Gera payload para saque PIX (Cash Out)
 * NOTA: Token e Secret são incluídos automaticamente
 */
export function generateWithdrawPayload(options = {}) {
  const amount = options.amount || randomAmount(500, 10000); // R$5 a R$100
  const pixKey = options.pixKey || generatePixKey('email');
  
  return {
    // Credenciais de API (obrigatórios)
    token: credentials.api.token,
    secret: credentials.api.secret,
    // Dados do saque
    amount: amount,
    pixKey: pixKey.key,
    pixKeyType: pixKey.type,
    description: options.description || `Saque teste k6 - ${Date.now()}`,
    externalId: options.externalId || `k6_saq_${Date.now()}_${Math.random().toString(36).substring(7)}`,
    // Dados do beneficiário
    beneficiary: {
      name: options.beneficiaryName || 'Beneficiário Teste',
      cpf: options.beneficiaryCpf || generateCPF(),
    },
  };
}

/**
 * Gera payload de webhook TREEAL (para simular callbacks)
 */
export function generateWebhookPayload(type = 'CASH_IN', status = 'CONCLUIDA') {
  const timestamp = new Date().toISOString();
  
  if (type === 'CASH_IN') {
    return {
      evento: 'PIX_RECEBIDO',
      dados: {
        transactionId: `TRE${Date.now()}`,
        endToEndId: `E12345678${Date.now().toString().substring(0, 20)}`,
        status: status,
        valor: randomAmount(1000, 50000),
        dataHora: timestamp,
        pagador: {
          nome: 'Pagador Teste',
          cpf: generateCPF(),
        },
      },
      timestamp: timestamp,
    };
  }
  
  if (type === 'CASH_OUT') {
    return {
      evento: 'PIX_ENVIADO',
      dados: {
        transactionId: `TRE${Date.now()}`,
        endToEndId: `E12345678${Date.now().toString().substring(0, 20)}`,
        status: status,
        valor: randomAmount(500, 10000),
        dataHora: timestamp,
        beneficiario: {
          nome: 'Beneficiário Teste',
          cpf: generateCPF(),
        },
      },
      timestamp: timestamp,
    };
  }
  
  return {};
}

// Status possíveis para testes
export const cashInStatuses = [
  'ATIVA',
  'CONCLUIDA',
  'REMOVIDA_PELO_USUARIO_RECEBEDOR',
  'REMOVIDA_PELO_PSP',
  'EM_PROCESSAMENTO',
  'NAO_REALIZADO',
];

export const cashOutStatuses = [
  'PROCESSING',
  'LIQUIDATED',
  'CANCELED',
  'REFUNDED',
  'PARTIALLY_REFUNDED',
  'ERROR',
];

/**
 * Valores de teste pré-definidos para cenários específicos
 */
export const testAmounts = {
  minimum: 100,        // R$1,00 - valor mínimo
  small: 1000,         // R$10,00
  medium: 10000,       // R$100,00
  large: 100000,       // R$1.000,00
  veryLarge: 500000,   // R$5.000,00
  maximum: 1000000,    // R$10.000,00 - valor máximo típico
};

/**
 * Cenários de erro para testes de resiliência
 */
export const errorScenarios = {
  invalidCPF: { cpf: '00000000000' },
  invalidAmount: { amount: -100 },
  zeroAmount: { amount: 0 },
  exceedsLimit: { amount: 99999999 },
  invalidPixKey: { pixKey: 'invalid', pixKeyType: 'cpf' },
  emptyPayload: {},
};

/**
 * Headers padrão para diferentes tipos de requisição
 */
export const defaultHeaders = {
  json: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  form: {
    'Content-Type': 'application/x-www-form-urlencoded',
    'Accept': 'application/json',
  },
};
