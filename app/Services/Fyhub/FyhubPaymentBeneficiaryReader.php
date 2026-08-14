<?php

namespace App\Services\Fyhub;

/**
 * Extrai recebedor Pix de payloads FyHub Contas conforme documentação oficial:
 * - GET /pix/payments/{endToEndId} → data.creditorAccount
 * - Webhook TRANSFER → data.creditorAccount (cash-out = creditDebitType DEBIT)
 * - GET /accounts/transactions/{id}/details → [0].creditorAccount
 */
final class FyhubPaymentBeneficiaryReader
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{name?: string, document?: string}
     */
    public static function creditorFromPayload(?array $payload): array
    {
        $data = self::paymentData($payload);
        if ($data === []) {
            return [];
        }

        $creditor = self::accountToBeneficiary($data['creditorAccount'] ?? null);
        if ($creditor === []) {
            return [];
        }

        $debtor = self::accountToBeneficiary($data['debtorAccount'] ?? null);
        if ($debtor !== [] && self::sameBeneficiary($creditor, $debtor)) {
            return [];
        }

        return $creditor;
    }

    /**
     * Detalhe de conta: resposta é lista; usa o primeiro item.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $detailsBody
     * @return array{name?: string, document?: string}
     */
    public static function creditorFromAccountTransactionDetails(?array $detailsBody): array
    {
        if ($detailsBody === null || $detailsBody === []) {
            return [];
        }

        $row = array_is_list($detailsBody) ? ($detailsBody[0] ?? []) : $detailsBody;
        if (! is_array($row)) {
            return [];
        }

        return self::accountToBeneficiary($row['creditorAccount'] ?? null);
    }

    /**
     * ID numérico da transação na FyHub (campo data.id do POST/GET pagamento).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function paymentId(?array $payload): ?int
    {
        $data = self::paymentData($payload);
        if ($data === [] || ! isset($data['id'])) {
            return null;
        }

        $id = $data['id'];
        if (is_int($id)) {
            return $id;
        }
        if (is_numeric($id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * Corpo interno data.* (webhook, GET pagamento, etc.).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public static function paymentData(?array $payload): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        $inner = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];
        $hasRootPaymentFields = isset($payload['creditorAccount'])
            || isset($payload['debtorAccount'])
            || isset($payload['endToEndId'])
            || isset($payload['pixKey'])
            || isset($payload['id']);

        if ($inner === [] && $hasRootPaymentFields) {
            return $payload;
        }

        if ($inner === []) {
            return [];
        }

        if (! $hasRootPaymentFields) {
            return $inner;
        }

        // getPayoutStatus faz array_merge(envelope, data): creditor pode existir só no nível raiz.
        $overlayKeys = [
            'id', 'endToEndId', 'pixKey', 'status', 'creditDebitType',
            'creditorAccount', 'debtorAccount',
        ];
        foreach ($overlayKeys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $rootVal = $payload[$key];
            if ($key === 'creditorAccount' || $key === 'debtorAccount') {
                if (self::accountToBeneficiary(is_array($rootVal) ? $rootVal : null) === []) {
                    continue;
                }
            }
            $inner[$key] = $rootVal;
        }

        return $inner;
    }

    /**
     * @param  array<string, mixed>|null  $account
     * @return array{name?: string, document?: string}
     */
    private static function accountToBeneficiary(?array $account): array
    {
        if ($account === null || $account === []) {
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
     * @param  array{name?: string, document?: string}  $a
     * @param  array{name?: string, document?: string}  $b
     */
    private static function sameBeneficiary(array $a, array $b): bool
    {
        $docA = isset($a['document']) ? preg_replace('/\D/', '', (string) $a['document']) : '';
        $docB = isset($b['document']) ? preg_replace('/\D/', '', (string) $b['document']) : '';
        if ($docA !== '' && $docB !== '' && $docA === $docB) {
            return true;
        }

        $nameA = $a['name'] ?? '';
        $nameB = $b['name'] ?? '';

        return $nameA !== '' && $nameB !== '' && strcasecmp($nameA, $nameB) === 0;
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
