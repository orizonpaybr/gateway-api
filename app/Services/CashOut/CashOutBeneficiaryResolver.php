<?php

namespace App\Services\CashOut;

/**
 * Extrai nome e documento do recebedor real (credor Pix) a partir do payload do provedor.
 * FyHub Contas: creditorAccount no webhook TRANSFER e na consulta de pagamento.
 */
final class CashOutBeneficiaryResolver
{
    /**
     * @param  array<string, mixed>|null  $providerRaw
     * @return array{name: string, document: string}|array{}
     */
    public static function resolve(?array $providerRaw): array
    {
        $account = self::extractCreditorAccount($providerRaw);
        if ($account === null) {
            return [];
        }

        $name = isset($account['name']) && is_string($account['name'])
            ? trim($account['name'])
            : '';
        $document = isset($account['document']) && (is_string($account['document']) || is_numeric($account['document']))
            ? trim((string) $account['document'])
            : '';

        if ($name === '' && $document === '') {
            return [];
        }

        $out = [];
        if ($name !== '') {
            $out['name'] = $name;
        }
        if ($document !== '') {
            $out['document'] = self::formatDocumentForDisplay($document);
        }

        return $out;
    }

    /**
     * Campos para persistir em solicitacoes_cash_out.
     *
     * @param  array<string, mixed>|null  $providerRaw
     * @return array{beneficiaryname?: string, beneficiarydocument?: string}
     */
    public static function patchForModel(?array $providerRaw): array
    {
        $resolved = self::resolve($providerRaw);
        $patch = [];

        if (isset($resolved['name'])) {
            $patch['beneficiaryname'] = $resolved['name'];
        }
        if (isset($resolved['document'])) {
            $patch['beneficiarydocument'] = $resolved['document'];
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    private static function extractCreditorAccount(?array $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $direct = $raw['creditorAccount'] ?? null;
        if (is_array($direct) && $direct !== []) {
            return $direct;
        }

        $data = $raw['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $nested = $data['creditorAccount'] ?? null;
        if (is_array($nested) && $nested !== []) {
            return $nested;
        }

        $inner = $data['data'] ?? null;
        if (is_array($inner)) {
            $innerAccount = $inner['creditorAccount'] ?? null;
            if (is_array($innerAccount) && $innerAccount !== []) {
                return $innerAccount;
            }
        }

        return null;
    }

    private static function formatDocumentForDisplay(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        if ($digits === null || $digits === '') {
            return $document;
        }

        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }

        if (strlen($digits) === 14) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'
                .substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }

        return $document;
    }
}
