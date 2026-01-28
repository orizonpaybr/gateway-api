import http from 'k6/http';
import { check } from 'k6';
import { getConfig } from '../config/options.js';

/**
 * Helper de Autenticação
 * 
 * Fornece funções para autenticação via JWT e token/secret
 */

const config = getConfig();

/**
 * Realiza login e retorna o token JWT
 * @param {string} username - Username ou email do usuário
 * @param {string} password - Senha do usuário
 * @returns {object} - { token, tempToken, requires2FA, user }
 */
export function login(username, password) {
  const url = `${config.baseUrl}/auth/login`;
  
  const payload = JSON.stringify({
    username: username,
    password: password,
  });
  
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    tags: { name: 'login' },
  };
  
  const response = http.post(url, payload, params);
  
  const success = check(response, {
    'login: status 200': (r) => r.status === 200,
    'login: has token or tempToken': (r) => {
      try {
        const body = r.json();
        // Token pode estar em body.token, body.data.token, body.tempToken ou body.temp_token
        const token = body.token || (body.data && body.data.token) || body.tempToken || body.temp_token;
        return !!token;
      } catch (e) {
        return false;
      }
    },
  });
  
  if (!success) {
    console.error(`Login failed: ${response.status} - ${response.body}`);
    return null;
  }
  
  const body = response.json();
  
  // A API retorna o token dentro de data.token
  return {
    token: body.token || (body.data && body.data.token) || null,
    tempToken: body.tempToken || body.temp_token || null,
    requires2FA: body.requires_2fa || body.requires2FA || false,
    user: body.user || (body.data && body.data.user) || null,
  };
}

/**
 * Verifica código 2FA e retorna token definitivo
 * @param {string} tempToken - Token temporário do login
 * @param {string} code - Código 2FA
 * @returns {object} - { token, user }
 */
export function verify2FA(tempToken, code) {
  const url = `${config.baseUrl}/auth/verify-2fa`;
  
  const payload = JSON.stringify({
    tempToken: tempToken,
    code: code,
  });
  
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    tags: { name: 'verify_2fa' },
  };
  
  const response = http.post(url, payload, params);
  
  const success = check(response, {
    'verify2FA: status 200': (r) => r.status === 200,
    'verify2FA: has token': (r) => r.json().token,
  });
  
  if (!success) {
    console.error(`2FA verification failed: ${response.status} - ${response.body}`);
    return null;
  }
  
  const body = response.json();
  
  return {
    token: body.token,
    user: body.user || null,
  };
}

/**
 * Gera código TOTP a partir do secret (requer lib externa ou mock)
 * Para testes, considere desabilitar 2FA no usuário de teste
 * @param {string} secret - Secret do 2FA
 * @returns {string} - Código de 6 dígitos
 */
export function generateTOTP(secret) {
  // k6 não tem suporte nativo a TOTP
  // Opções:
  // 1. Desabilitar 2FA no usuário de teste
  // 2. Usar um serviço externo para gerar o código
  // 3. Mock do código em ambiente de teste
  
  console.warn('TOTP generation not implemented - disable 2FA for test user');
  return '000000';
}

/**
 * Fluxo completo de autenticação (login + 2FA se necessário)
 * @param {string} email 
 * @param {string} password 
 * @param {string} totpSecret - Opcional, secret do 2FA
 * @returns {string} - Token JWT válido
 */
export function authenticate(email, password, totpSecret = null) {
  const loginResult = login(email, password);
  
  if (!loginResult) {
    return null;
  }
  
  // Se não requer 2FA, retorna o token direto
  if (loginResult.token) {
    return loginResult.token;
  }
  
  // Se requer 2FA
  if (loginResult.requires2FA && loginResult.tempToken) {
    if (!totpSecret) {
      console.error('2FA required but no TOTP secret provided');
      return null;
    }
    
    const code = generateTOTP(totpSecret);
    const verifyResult = verify2FA(loginResult.tempToken, code);
    
    return verifyResult ? verifyResult.token : null;
  }
  
  return null;
}

/**
 * Cria headers com autenticação JWT
 * @param {string} token - Token JWT
 * @returns {object} - Headers para requisições autenticadas
 */
export function getAuthHeaders(token) {
  return {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Authorization': `Bearer ${token}`,
  };
}

/**
 * Retorna headers padrão para requisições de integração
 * NOTA: Token e Secret devem ser enviados no BODY da requisição, não nos headers
 * @returns {object} - Headers para requisições de integração
 */
export function getApiHeaders() {
  return {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
}

/**
 * Retorna credenciais de API para incluir no body da requisição
 * @param {string} apiToken - Token da API
 * @param {string} apiSecret - Secret da API
 * @returns {object} - { token, secret }
 */
export function getApiCredentials(apiToken, apiSecret) {
  return {
    token: apiToken,
    secret: apiSecret,
  };
}

/**
 * Verifica se um token JWT está válido
 * @param {string} token - Token JWT
 * @returns {boolean}
 */
export function verifyToken(token) {
  const url = `${config.baseUrl}/auth/verify`;
  
  const params = {
    headers: getAuthHeaders(token),
    tags: { name: 'verify_token' },
  };
  
  const response = http.get(url, params);
  
  return check(response, {
    'verifyToken: status 200': (r) => r.status === 200,
    'verifyToken: valid true': (r) => r.json().valid === true,
  });
}

/**
 * Registra um novo usuário (para testes de stress em registro)
 * @param {object} userData - Dados do usuário
 * @returns {object} - Resultado do registro
 */
export function register(userData) {
  const url = `${config.baseUrl}/auth/register`;
  
  const payload = JSON.stringify({
    name: userData.name,
    email: userData.email,
    password: userData.password,
    password_confirmation: userData.password,
    cpf: userData.cpf,
    phone: userData.phone,
    ...userData,
  });
  
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    tags: { name: 'register' },
  };
  
  const response = http.post(url, payload, params);
  
  check(response, {
    'register: status 201 or 200': (r) => r.status === 201 || r.status === 200,
  });
  
  return response.json();
}
