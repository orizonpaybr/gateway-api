<?php

namespace Tests\Feature\Helpers;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;

class TransactionTestHelper
{
    /**
     * Cria uma Solicitacao com todos os campos obrigatórios
     */
    public static function createSolicitacao(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => 'testuser',
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Teste',
        ];

        $merged = array_merge($defaults, $attributes);
        
        // Processar user_id após merge - se for objeto User, extrair username
        if (isset($merged['user_id']) && is_object($merged['user_id'])) {
            if (isset($merged['user_id']->username)) {
                $merged['user_id'] = $merged['user_id']->username;
            } elseif (isset($merged['user_id']->user_id)) {
                $merged['user_id'] = $merged['user_id']->user_id;
            } else {
                $merged['user_id'] = 'testuser';
            }
        }

        return Solicitacoes::create($merged);
    }

    /**
     * Cria uma SolicitacoesCashOut com todos os campos obrigatórios
     */
    public static function createSolicitacaoCashOut(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => 'testuser',
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 50.00,
            'cash_out_liquido' => 49.00,
            'taxa_cash_out' => 1.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'beneficiaryname' => 'Beneficiário Test',
            'beneficiarydocument' => '98765432100',
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Saque Teste',
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'type' => 'pix',
        ];

        $merged = array_merge($defaults, $attributes);
        
        // Processar user_id após merge - se for objeto User, extrair username
        if (isset($merged['user_id']) && is_object($merged['user_id'])) {
            if (isset($merged['user_id']->username)) {
                $merged['user_id'] = $merged['user_id']->username;
            } elseif (isset($merged['user_id']->user_id)) {
                $merged['user_id'] = $merged['user_id']->user_id;
            } else {
                $merged['user_id'] = 'testuser';
            }
        }

        return SolicitacoesCashOut::create($merged);
    }
}










