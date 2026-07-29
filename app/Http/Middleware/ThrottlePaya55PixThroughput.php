<?php

namespace App\Http\Middleware;

/**
 * Respeita o limite informado pela Paya55 (300 TPS) quando o PIX padrão do
 * usuário resolve para o provider paya55.
 *
 * Mesma lógica da FluxPayments (compara pelo `provider`, não pela `referencia`
 * da nominal) — só muda o slug e o arquivo de config lido.
 */
class ThrottlePaya55PixThroughput extends ThrottleFluxPaymentsPixThroughput
{
    protected function provider(): string
    {
        return 'paya55';
    }

    protected function label(): string
    {
        return 'Paya55';
    }
}
