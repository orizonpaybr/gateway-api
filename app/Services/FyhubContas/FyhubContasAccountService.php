<?php

namespace App\Services\FyhubContas;

use Illuminate\Http\Client\Response;

/**
 * Operações da seção Accounts da API Contas Fyhub.
 *
 * @see https://pagamentos.fyhub.com.br/api/v2
 */
class FyhubContasAccountService
{
    public function __construct(private readonly FyhubContasApiClient $client) {}

    /**
     * Lista transações da conta (paginação e filtros opcionais).
     *
     * @param  array<string, string|int|float|null>  $extraQuery  page_offset, page_limit, sort_by, sort_order, filter
     * @return array{success:bool,data?:array,message?:string,status?:int}
     */
    public function listTransactions(string $eventDateStart, string $eventDateEnd, array $extraQuery = []): array
    {
        $query = array_merge([
            'event_date_start' => $eventDateStart,
            'event_date_end' => $eventDateEnd,
        ], $extraQuery);

        $response = $this->client->get('/accounts/transactions/', $query);

        return $this->decodeJsonResponse($response);
    }

    /**
     * Detalhes de uma transação pelo ID numérico da API Contas.
     *
     * @return array{success:bool,data?:array,message?:string,status?:int}
     */
    public function getTransactionDetails(int|string $transactionId): array
    {
        $id = trim((string) $transactionId);
        if ($id === '' || ! is_numeric($id)) {
            return ['success' => false, 'message' => 'transactionId inválido.'];
        }

        $response = $this->client->get('/accounts/transactions/'.$id.'/details');

        return $this->decodeJsonResponse($response);
    }

    /**
     * Saldo da conta.
     *
     * @return array{success:bool,data?:array,message?:string,status?:int}
     */
    public function getBalances(): array
    {
        $response = $this->client->get('/accounts/balances/');

        return $this->decodeJsonResponse($response);
    }

    /**
     * Extrato consolidado (groupBy DAY ou MONTH + datas conforme doc).
     *
     * @param  array<string, string>  $body  Ex.: ['groupBy'=>'DAY','initialDate'=>'2025-05-01','finalDate'=>'2025-05-03']
     * @return array{success:bool,data?:array,message?:string,status?:int}
     */
    public function consolidatedStatement(array $body): array
    {
        $response = $this->client->postJson('/accounts/consolidated-statement', $body);

        return $this->decodeJsonResponse($response);
    }

    /**
     * @return array{success:bool,data?:array,message?:string,status?:int}
     */
    private function decodeJsonResponse(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body)) {
            return ['success' => true, 'data' => $body, 'status' => $status];
        }

        $msg = 'Erro na API FYHUB Contas.';
        if (is_array($body)) {
            $msg = (string) ($body['message'] ?? $body['detail'] ?? $body['title'] ?? $msg);
        }

        return ['success' => false, 'message' => $msg, 'data' => is_array($body) ? $body : null, 'status' => $status];
    }
}
