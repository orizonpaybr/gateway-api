<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;

/**
 * Monta o payload extra enviado ao cliente integrado (webhook Coratri → cliente).
 * Não repete o JSON bruto do provedor PIX: usa os dados já armazenados na transação
 * (cadastro na criação + end_to_end após liquidação).
 */
class ClientWebhookPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function extraForDeposit(Solicitacoes $d): array
    {
        $extra = ['typeTransaction' => 'PIX_IN'];

        $payer = array_filter([
            'name' => $d->client_name,
            'document' => $d->client_document,
            'email' => $d->client_email,
            'phone' => self::normalizeOptionalString($d->client_telefone),
        ], fn ($v) => $v !== null && $v !== '');

        if ($payer !== []) {
            $extra['payer'] = $payer;
        }

        if (! empty($d->user_id)) {
            $extra['receiver'] = ['user_id' => $d->user_id];
        }

        if (! empty($d->end_to_end)) {
            $extra['endToEndId'] = $d->end_to_end;
        }

        return $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function extraForCashOut(SolicitacoesCashOut $w): array
    {
        $extra = ['typeTransaction' => 'PIX_OUT'];

        $beneficiary = array_filter([
            'name' => $w->beneficiaryname,
            'document' => $w->beneficiarydocument,
            'pixKey' => $w->pix,
        ], fn ($v) => $v !== null && $v !== '');

        if ($beneficiary !== []) {
            $extra['beneficiary'] = $beneficiary;
        }

        if (! empty($w->user_id)) {
            $extra['sender'] = ['user_id' => $w->user_id];
        }

        if (! empty($w->end_to_end)) {
            $extra['endToEndId'] = $w->end_to_end;
        }

        return $extra;
    }

    private static function normalizeOptionalString(?string $v): ?string
    {
        if ($v === null || $v === '' || $v === 'N/A') {
            return null;
        }

        return $v;
    }
}
