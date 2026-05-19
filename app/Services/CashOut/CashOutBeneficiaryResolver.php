<?php

namespace App\Services\CashOut;

/**
 * Extrai nome e documento do recebedor real (credor Pix) a partir do payload do provedor.
 * FyHub Contas: consulta GET /pix/payments/{e2e} e webhooks TRANSFER.
 */
final class CashOutBeneficiaryResolver
{
    /**
     * @param  array<string, mixed>|null  $providerRaw
     * @return array{name?: string, document?: string}
     */
    public static function resolve(?array $providerRaw): array
    {
        $account = self::extractReceiverAccount($providerRaw);
        if ($account === null) {
            return self::resolveFromFlatFields($providerRaw);
        }

        return self::accountToBeneficiary($account);
    }

    /**
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
    private static function extractReceiverAccount(?array $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $debtor = self::normalizeAccountBlock(self::pickAccount($raw, [
            'debtorAccount',
            'debtor',
            'payerAccount',
            'payer',
        ]));

        $receiverCandidates = [
            self::pickAccount($raw, ['creditParty', 'creditPartyAccount', 'receiverAccount', 'receiver', 'payee', 'payeeAccount', 'beneficiary', 'beneficiaryAccount', 'counterparty', 'counterParty']),
            self::pickAccount($raw, ['creditorAccount', 'creditor']),
            self::pickAccount(self::nestedData($raw), ['creditParty', 'receiverAccount', 'creditorAccount', 'creditor']),
        ];

        foreach ($receiverCandidates as $candidate) {
            $normalized = self::normalizeAccountBlock($candidate);
            if ($normalized === null) {
                continue;
            }
            if ($debtor !== null && self::accountsMatch($normalized, $debtor)) {
                continue;
            }

            return $normalized;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{name?: string, document?: string}
     */
    private static function resolveFromFlatFields(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $nameKeys = ['receiverName', 'recipient_name', 'payeeName', 'creditorName', 'beneficiaryName', 'nomeRecebedor'];
        $docKeys = ['receiverDocument', 'receiverLegalId', 'recipient_legal_id', 'payeeDocument', 'creditorDocument', 'beneficiaryDocument', 'documentoRecebedor'];

        $name = self::firstNonEmptyString($raw, $nameKeys);
        $document = self::firstNonEmptyString($raw, $docKeys);

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
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $keys
     */
    private static function pickAccount(array $raw, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (! isset($raw[$key])) {
                continue;
            }
            $block = $raw[$key];
            if (is_array($block) && $block !== []) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function nestedData(array $raw): array
    {
        $data = $raw['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        $inner = $data['data'] ?? null;

        return is_array($inner) ? array_merge($data, $inner) : $data;
    }

    /**
     * @param  array<string, mixed>|null  $account
     * @return array{name?: string, document?: string}
     */
    private static function accountToBeneficiary(?array $account): array
    {
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
     * @param  array<string, mixed>|null  $account
     * @return array{name: string, document: string}|null
     */
    private static function normalizeAccountBlock(?array $account): ?array
    {
        if ($account === null || $account === []) {
            return null;
        }

        $name = isset($account['name']) && is_string($account['name']) ? trim($account['name']) : '';
        $document = isset($account['document']) && (is_string($account['document']) || is_numeric($account['document']))
            ? preg_replace('/\D/', '', (string) $account['document'])
            : '';

        if ($name === '' && $document === '') {
            return null;
        }

        return ['name' => $name, 'document' => $document];
    }

    /**
     * @param  array{name: string, document: string}  $a
     * @param  array{name: string, document: string}  $b
     */
    private static function accountsMatch(array $a, array $b): bool
    {
        if ($a['document'] !== '' && $b['document'] !== '' && $a['document'] === $b['document']) {
            return true;
        }

        return $a['name'] !== '' && $b['name'] !== '' && strcasecmp($a['name'], $b['name']) === 0;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $keys
     */
    private static function firstNonEmptyString(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($raw[$key])) {
                continue;
            }
            $v = $raw[$key];
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
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
