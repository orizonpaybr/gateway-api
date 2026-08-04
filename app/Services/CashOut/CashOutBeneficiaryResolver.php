<?php

namespace App\Services\CashOut;


/**
 * Extrai nome e documento do recebedor real (credor Pix) a partir do payload do provedor.
 */
final class CashOutBeneficiaryResolver
{
    /**
     * @param  array<string, mixed>|null  $providerRaw
     * @return array{name?: string, document?: string}
     */
    public static function resolve(?array $providerRaw): array
    {
        $raw = self::flattenProviderPayload($providerRaw);
        if ($raw === []) {
            return [];
        }

        $account = self::extractReceiverAccount($raw);
        if ($account === null) {
            return self::resolveFromFlatFields($raw);
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

        // Cash-out: recebedor = creditorAccount (o débito sai da conta Coratri).
        $receiverCandidates = [
            self::pickAccount($raw, ['creditorAccount', 'creditor']),
            self::pickAccount(self::nestedData($raw), ['creditorAccount', 'creditor']),
            self::pickAccount($raw, ['creditParty', 'creditPartyAccount', 'receiverAccount', 'receiver', 'payee', 'payeeAccount', 'beneficiary', 'beneficiaryAccount', 'counterparty', 'counterParty']),
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

        $nameKeys = [
            'receiverName', 'recipient_name', 'payeeName', 'creditorName', 'beneficiaryName', 'nomeRecebedor',
            'creditPartyName', 'holderName', 'legalName',
        ];
        $docKeys = [
            'receiverDocument', 'receiverLegalId', 'recipient_legal_id', 'payeeDocument', 'creditorDocument',
            'beneficiaryDocument', 'documentoRecebedor', 'taxId', 'cpfCnpj', 'creditorTaxId', 'creditPartyTaxId',
        ];

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

        $name = self::extractNameFromAccount($account);
        $document = self::extractDocumentDigitsFromAccount($account);

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

        $name = self::extractNameFromAccount($account);
        $document = self::extractDocumentDigitsFromAccount($account);

        if ($name === '' && $document === '') {
            return null;
        }

        return ['name' => $name, 'document' => $document];
    }

    /**
     * Provedores costumam aninhar em data.data e usar taxId / legalName em creditParty.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    private static function flattenProviderPayload(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $flat = $raw;
        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            $flat = array_merge($flat, $data);
            if (isset($data['data']) && is_array($data['data'])) {
                $flat = array_merge($flat, $data['data']);
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private static function extractNameFromAccount(array $account): string
    {
        foreach (['name', 'legalName', 'holderName', 'fullName', 'tradeName'] as $key) {
            if (! isset($account[$key]) || ! is_string($account[$key])) {
                continue;
            }
            $value = trim($account[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private static function extractDocumentDigitsFromAccount(array $account): string
    {
        foreach (['document', 'taxId', 'cpfCnpj', 'cpf', 'cnpj', 'legalId', 'identification'] as $key) {
            if (! isset($account[$key])) {
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) $account[$key]);

            if ($digits !== '') {
                return $digits;
            }
        }

        return '';
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
