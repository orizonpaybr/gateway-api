<?php

namespace App\Helpers;

/**
 * Tipos de erro da API PIX (RFC 7807) – API QRCodes / Cob / Webhook.
 * O campo type nas respostas de erro segue: https://pix.bcb.gov.br/api/v2/error/<TipoErro>
 *
 * Referência: documentação BACEN API Pix – Tratamento de erros.
 */
class PixApiErrorTypes
{
    private const PREFIX = 'https://pix.bcb.gov.br/api/v2/error/';

    /** @var array<string, string> TipoErro => Mensagem em português */
    private const MAP = [
        // Gerais
        'RequisicaoInvalida' => 'Requisição inválida.',
        'AcessoNegado' => 'Requisição não autorizada.',
        'NaoEncontrado' => 'Recurso não encontrado.',
        'PermanentementeRemovido' => 'Recurso foi removido permanentemente.',
        'ErroInternoDoServidor' => 'Erro interno no servidor. Tente novamente mais tarde.',
        'ServicoIndisponivel' => 'Serviço indisponível no momento.',
        'IndisponibilidadePorTempoEsgotado' => 'Serviço demorou além do esperado.',
        // Tag Cob (cobrança imediata)
        'CobNaoEncontrado' => 'Cobrança não encontrada para o txid informado.',
        'CobOperacaoInvalida' => 'Requisição de cobrança inválida ou em formato incorreto.',
        'CobConsultaInvalida' => 'Parâmetros de consulta à cobrança inválidos.',
        // Tag CobPayload (payload/location)
        'CobPayloadNaoEncontrado' => 'Cobrança não encontrada para a location informada.',
        'CobPayloadOperacaoInvalida' => 'Requisição de cobrança inválida.',
        // Tag Webhook
        'WebhookOperacaoInvalida' => 'Requisição de webhook inválida. Verifique a URL e a chave PIX.',
        'WebhookNaoEncontrado' => 'Webhook não registrado para esta chave PIX.',
        'WebhookConsultaInvalida' => 'Parâmetros de consulta ao webhook inválidos.',
    ];

    /**
     * Extrai o TipoErro do campo type (URL RFC 7807).
     */
    public static function extractTypeFromUrl(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $type = trim($type);
        if (str_starts_with($type, self::PREFIX)) {
            $suffix = substr($type, strlen(self::PREFIX));
            return $suffix !== '' ? $suffix : null;
        }
        return null;
    }

    /**
     * Retorna mensagem amigável para o TipoErro, ou null se não mapeado.
     */
    public static function getMessageForType(?string $tipoErro): ?string
    {
        if ($tipoErro === null || $tipoErro === '') {
            return null;
        }
        $key = trim($tipoErro);
        return self::MAP[$key] ?? null;
    }

    /**
     * Monta mensagem de erro a partir do corpo da resposta (RFC 7807).
     * Prioridade: TipoErro mapeado → detail → title → message → fallback.
     *
     * @param array<string, mixed>|null $body JSON decode do body (ou null)
     * @param string|null $fallback Mensagem quando não houver type/detail/title/message
     */
    public static function getMessageFromResponse(?array $body, ?string $fallback = 'Erro na API PIX.'): string
    {
        if ($body === null || !is_array($body)) {
            return $fallback ?? 'Erro na API PIX.';
        }

        $type = isset($body['type']) && is_string($body['type']) ? $body['type'] : null;
        $tipoErro = self::extractTypeFromUrl($type);
        $mapped = $tipoErro !== null ? self::getMessageForType($tipoErro) : null;
        if ($mapped !== null && $mapped !== '') {
            $detail = isset($body['detail']) && is_string($body['detail']) ? trim($body['detail']) : null;
            if ($detail !== null && $detail !== '') {
                return $mapped . ' ' . $detail;
            }
            return $mapped;
        }

        $detail = isset($body['detail']) && is_string($body['detail']) ? trim($body['detail']) : null;
        if ($detail !== null && $detail !== '') {
            return $detail;
        }
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : null;
        if ($title !== null && $title !== '') {
            return $title;
        }
        $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : null;
        if ($message !== null && $message !== '') {
            return $message;
        }

        return $fallback ?? 'Erro na API PIX.';
    }
}
