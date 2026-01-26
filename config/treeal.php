<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Treeal/ONZ PIX Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações para integração com Treeal/ONZ PIX.
    | Credenciais sensíveis devem estar no .env, não no banco de dados.
    |
    | IMPORTANTE: Para produção, altere:
    | - TREEAL_ENVIRONMENT=production
    | - URLs de produção (sem .hmg ou -h)
    | - Certificado de produção
    | - Credenciais de produção
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Ambiente
    |--------------------------------------------------------------------------
    |
    | Ambiente atual: 'sandbox' ou 'production'
    | Em sandbox, SSL pode ser desabilitado para certificados auto-assinados.
    |
    */
    'environment' => env('TREEAL_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | URLs das APIs
    |--------------------------------------------------------------------------
    |
    | URLs de homologação (sandbox):
    | - QR Codes: https://api.pix-h.amplea.coop.br
    | - Accounts: https://secureapi.bancodigital.hmg.onz.software/api/v2
    |
    | URLs de produção (confirmar com TREEAL):
    | - QR Codes: https://api.pix.amplea.coop.br (ou similar)
    | - Accounts: https://secureapi.bancodigital.onz.software/api/v2
    |
    */
    'qrcodes_api_url' => env('TREEAL_QRCODES_API_URL', 'https://api.pix-h.amplea.coop.br'),
    'accounts_api_url' => env('TREEAL_ACCOUNTS_API_URL', 'https://secureapi.bancodigital.hmg.onz.software/api/v2'),

    /*
    |--------------------------------------------------------------------------
    | Certificado Digital
    |--------------------------------------------------------------------------
    |
    | Certificado .PFX para autenticação mTLS.
    | Em produção, usar certificado de produção.
    |
    */
    'certificate_path' => env('TREEAL_CERTIFICATE_PATH', 'PIX-HMG-CLIENTE.pfx'),
    'certificate_password' => env('TREEAL_CERTIFICATE_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Credenciais OAuth2 - Accounts API (Cash Out)
    |--------------------------------------------------------------------------
    |
    | Client ID e Secret para API de Cash Out (ONZ).
    | Obter em: Configurações → API Contas → Nova credencial
    |
    */
    'accounts_client_id' => env('TREEAL_ACCOUNTS_CLIENT_ID'),
    'accounts_client_secret' => env('TREEAL_ACCOUNTS_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Credenciais OAuth2 - QR Codes API (Cash In)
    |--------------------------------------------------------------------------
    |
    | Client ID e Secret para API de QR Codes (Cash In).
    |
    */
    'qrcodes_client_id' => env('TREEAL_QRCODES_CLIENT_ID'),
    'qrcodes_client_secret' => env('TREEAL_QRCODES_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Chave PIX
    |--------------------------------------------------------------------------
    |
    | Chave PIX para recebimento de pagamentos (Cash In).
    |
    */
    'pix_key_secondary' => env('TREEAL_PIX_KEY_SECONDARY'),

    /*
    |--------------------------------------------------------------------------
    | Custo Fixo da TREEAL
    |--------------------------------------------------------------------------
    |
    | Custo fixo cobrado pela TREEAL por cada transação PIX (em reais).
    | Este valor é descontado do lucro líquido da aplicação.
    |
    | Exemplo: Se a taxa configurada para o cliente é R$ 0,50 (50 centavos)
    | e o custo da TREEAL é R$ 0,04 (4 centavos), o lucro líquido será
    | R$ 0,46 (46 centavos).
    |
    */
    'custo_fixo_por_transacao' => env('TREEAL_CUSTO_FIXO_POR_TRANSACAO', 0.04),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de segurança para webhooks da TREEAL.
    |
    | IMPORTANTE: Configure o webhook na plataforma Finance:
    | - URL: https://seu-dominio.com/api/treeal/webhook
    | - Método: POST
    | - Headers: se necessário, configure headers customizados
    |
    */
    
    // Secret para validação HMAC de webhooks (se TREEAL fornecer)
    'webhook_secret' => env('TREEAL_WEBHOOK_SECRET'),
    
    // IPs permitidos para envio de webhooks (whitelist)
    // Formato: IPs separados por vírgula (ex: '192.168.1.1,10.0.0.1')
    // Deixe vazio para desabilitar validação de IP
    'webhook_ips' => array_filter(explode(',', env('TREEAL_WEBHOOK_IPS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de retry para chamadas à API.
    | Retry com backoff exponencial para erros temporários (5xx, timeout).
    |
    */
    
    // Número máximo de tentativas
    'max_retries' => env('TREEAL_MAX_RETRIES', 3),
    
    // Delay base em milissegundos (será multiplicado exponencialmente)
    'retry_delay_ms' => env('TREEAL_RETRY_DELAY_MS', 1000),
    
    // Timeout em segundos para chamadas HTTP
    'timeout' => env('TREEAL_TIMEOUT', 30),
    
    // Timeout de conexão em segundos
    'connect_timeout' => env('TREEAL_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    |
    | Se a integração está ativa.
    | Mude para true apenas quando tudo estiver configurado.
    |
    */
    'status' => env('TREEAL_STATUS', false),
    
    /*
    |--------------------------------------------------------------------------
    | Mapeamento de Status
    |--------------------------------------------------------------------------
    |
    | Referência de status TREEAL:
    |
    | Cash Out (API ONZ):
    | - PROCESSING: Em processamento
    | - LIQUIDATED: Liquidado com sucesso
    | - CANCELED: Cancelado
    | - REFUNDED: Estornado
    | - PARTIALLY_REFUNDED: Parcialmente estornado
    |
    | Cash In (API QRCodes):
    | - ATIVA: Cobrança ativa
    | - CONCLUIDA: Cobrança paga
    | - REMOVIDA_PELO_USUARIO_RECEBEDOR: Removida
    | - EM_PROCESSAMENTO: Em processamento
    | - NAO_REALIZADO: Não realizado
    |
    */
];
