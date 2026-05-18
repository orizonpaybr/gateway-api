<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutBeneficiaryResolver;

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
    /**
     * @param  array<string, mixed>|null  $providerRaw  Payload do provedor (ex.: FyHub creditorAccount) para sobrescrever recebedor real
     */
    public static function extraForCashOut(SolicitacoesCashOut $w, ?array $providerRaw = null): array
    {
        $extra = ['typeTransaction' => 'PIX_OUT'];

        $fromProvider = CashOutBeneficiaryResolver::resolve($providerRaw);
        $beneficiaryName = $fromProvider['name'] ?? $w->beneficiaryname;
        $beneficiaryDocument = $fromProvider['document'] ?? $w->beneficiarydocument;
        $pixKey = self::resolvePixKeyValue($w, $providerRaw);

        $beneficiary = array_filter([
            'name' => $beneficiaryName,
            'document' => $beneficiaryDocument,
            'pixKey' => $pixKey,
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

    /**
     * Coluna pix guarda o valor da chave; pixkey guarda o tipo (cpf, email, …).
     *
     * @param  array<string, mixed>|null  $providerRaw
     */
    private static function resolvePixKeyValue(SolicitacoesCashOut $w, ?array $providerRaw): ?string
    {
        if ($providerRaw !== null) {
            $providerKey = $providerRaw['pixKey'] ?? null;
            if (is_string($providerKey) && trim($providerKey) !== '') {
                return trim($providerKey);
            }
            $data = $providerRaw['data'] ?? null;
            if (is_array($data)) {
                $nested = $data['pixKey'] ?? null;
                if (is_string($nested) && trim($nested) !== '') {
                    return trim($nested);
                }
            }
        }

        $stored = trim((string) ($w->pix ?? ''));

        return $stored !== '' ? $stored : null;
    }
}
