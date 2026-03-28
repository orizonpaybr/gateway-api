<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MagenPay API (PIX)
    |--------------------------------------------------------------------------
    |
    | Credenciais vêm do dashboard Magen ou de par de chaves gerado fora da app.
    | MAGENPAY_PRIVATE_KEY: PEM completo; no .env use \n para quebras de linha
    | ou uma única linha com \n escapado entre aspas.
    |
    */

    'enabled' => (bool) env('MAGENPAY_ENABLED', false),

    /*
    | QR Code dinâmico (cash-in) — POST .../instant
    | Sandbox: https://sandbox.api.magenpay.io/qrcode
    | Produção: https://api.magenpay.io/qrcode
    */
    'qrcode_base_url' => rtrim((string) env('MAGENPAY_QRCODE_BASE_URL', 'https://sandbox.api.magenpay.io/qrcode'), '/'),

    /*
    | Pix API: Pix Out por chave, estorno de cash-in (POST .../requests/in/{e2e}/reversals)
    */
    'pix_api_base_url' => rtrim((string) env('MAGENPAY_PIX_API_BASE_URL', 'https://sandbox.api.magenpay.io/pix/api/v1/external'), '/'),

    /*
    | Limite máximo por Pix Out (BRL), conforme documentação Magen.
    */
    'pix_max_out_amount' => (float) env('MAGENPAY_PIX_MAX_OUT', 15000),

    /*
    | Custo fixo por transação PIX neste adquirente (split em TaxaFlexivelHelper / TaxaSaqueHelper).
    */
    'custo_fixo_transacao' => (float) env('MAGENPAY_CUSTO_FIXO_TRANSACAO', 0.04),

    /*
    | Chave PIX cadastrada no painel Magen (keyId do body).
    */
    'pix_key_id' => env('MAGENPAY_PIX_KEY_ID'),

    /*
    | User-Agent obrigatório na API (rastreio / validação).
    */
    'user_agent' => (string) env('MAGENPAY_USER_AGENT', 'Coratri'),

    /*
    | Expiração padrão do BR Code dinâmico (segundos).
    */
    'qrcode_expiration_seconds' => (int) env('MAGENPAY_QRCODE_EXPIRATION_SECONDS', 86400),

    'public_key_id' => env('MAGENPAY_PUBLIC_KEY_ID'),

    'private_key' => env('MAGENPAY_PRIVATE_KEY') !== null
        ? str_replace('\\n', "\n", (string) env('MAGENPAY_PRIVATE_KEY'))
        : null,

    'timeout' => (int) env('MAGENPAY_TIMEOUT', 30),

    'verify_ssl' => filter_var(env('MAGENPAY_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),

    /*
    | Opcional: se definido, o header X-MagenPay-Webhook-Secret deve coincidir.
    */
    'webhook_secret' => env('MAGENPAY_WEBHOOK_SECRET'),
];
