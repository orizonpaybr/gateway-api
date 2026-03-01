<?php

namespace App\Helpers;

/**
 * Códigos de erro PIX (padrão BACEN/SPI).
 * Mapeamento código → descrição para exibição em logs e respostas ao usuário.
 *
 * Referência: documentação Onz / Guia de códigos de erro para pagamentos PIX.
 */
class PixErrorCodes
{
    /** @var array<string, string> Código => Descrição do erro */
    private const MAP = [
        'AB03' => 'Liquidação da transação interrompida devido a timeout no SPI.',
        'AB09' => 'Transação interrompida devido a erro no participante do usuário recebedor.',
        'AB11' => 'Timeout do participante emissor da ordem de pagamento.',
        'AC03' => 'Número da agência e/ou conta transacional do usuário recebedor inexistente ou inválido.',
        'AC06' => 'Conta transacional do usuário recebedor encontra-se bloqueada.',
        'AC07' => 'Número da conta transacional do usuário recebedor encerrada.',
        'AC14' => 'Tipo incorreto para a conta transacional do usuário recebedor.',
        'AG03' => 'Tipo de transação não é suportado/autorizado na conta do usuário recebedor.',
        'AG12' => 'Não é permitida ordem de pagamento/devolução (book transfer).',
        'AG13' => 'Não é permitido devolver a devolução de um pagamento instantâneo.',
        'AGNT' => 'Participante direto não é liquidante do participante do usuário pagador.',
        'AM01' => 'Ordem de pagamento instantâneo com valor zero.',
        'AM02' => 'Valor faz superar o limite permitido para o tipo de conta creditada.',
        'AM04' => 'Saldo insuficiente na conta do usuário pagador.',
        'AM09' => 'Devolução em valor que supera o valor da ordem de pagamento correspondente.',
        'AM12' => 'Divergência entre a somatória dos valores e o campo valor.',
        'AM18' => 'Quantidade de transações inválida.',
        'BE01' => 'CPF/CNPJ do usuário recebedor não é consistente com o titular da conta especificada.',
        'BE05' => 'CNPJ do iniciador de pagamento não se encontra cadastrado no arranjo Pix.',
        'BE17' => 'QR Code rejeitado pelo participante do usuário recebedor.',
        'CH11' => 'CPF/CNPJ do usuário recebedor incorreto.',
        'CH16' => 'Preenchimento do conteúdo da mensagem incorreto ou incompatível com as regras de negócio.',
        'DS04' => 'Ordem rejeitada pelo participante do usuário recebedor.',
        'DS0G' => 'Participante não é autorizado a realizar a operação na conta debitada.',
        'DS24' => 'Ordem rejeitada por extrapolação do tempo de espera.',
        'DS27' => 'Participante não se encontra cadastrado ou ainda não iniciou a operação no SPI.',
        'DT02' => 'Data e hora do envio da mensagem inválida.',
        'DT05' => 'Transação extrapola o prazo máximo para devolução de pagamento instantâneo.',
        'ED05' => 'Erro no processamento do pagamento instantâneo (erro genérico).',
        'FF07' => 'Inconsistência entre a finalidade da transação e o preenchimento dos elementos.',
        'FF08' => 'Identificador da operação mal formatado.',
        'MD01' => 'ISPB do participante facilitador de serviço Pix Saque ou Pix Troco inexistente.',
        'OZ01' => 'Saldo insuficiente.',
        'OZ02' => 'Erro de processamento.',
        'RC09' => 'ISPB do participante do usuário pagador inválido ou inexistente.',
        'RC10' => 'ISPB do participante do usuário recebedor inválido ou inexistente.',
        'RR04' => 'Ordem em que o usuário pagador é sancionado (CSNU).',
        'SL02' => 'A transação original não está relacionada aos serviços de Pix Saque ou Pix Troco.',
    ];

    /**
     * Retorna a descrição amigável do código de erro PIX, ou o fallback se não houver mapeamento.
     *
     * @param string|null $code Código do erro (ex.: AM04, OZ01)
     * @param string|null $fallback Mensagem alternativa quando o código não está mapeado
     * @return string
     */
    public static function getMessage(?string $code, ?string $fallback = null): string
    {
        if ($code === null || $code === '') {
            return $fallback ?? 'Erro não informado';
        }
        $normalized = strtoupper(trim($code));
        if (isset(self::MAP[$normalized])) {
            return self::MAP[$normalized];
        }
        return $fallback ?? "Erro PIX ({$code})";
    }

    /**
     * Obtém a mensagem de erro a partir do payload do webhook/resposta (errorCode, rejectionReason, message).
     *
     * @param array<string, mixed> $data Payload (ex.: webhook ou resposta da API)
     * @param string|null $default Mensagem padrão quando não houver código nem message
     * @return string
     */
    public static function getMessageFromPayload(array $data, ?string $default = 'Não informado'): string
    {
        $code = $data['errorCode'] ?? $data['rejectionReason'] ?? $data['code'] ?? null;
        $code = is_string($code) ? $code : null;
        $message = isset($data['message']) && is_string($data['message']) ? trim($data['message']) : null;
        $mapped = self::getMessage($code, $message ?? $default);
        if ($mapped !== ($message ?? $default) && $message !== null && $message !== '') {
            return $mapped . ' (' . $message . ')';
        }
        return $mapped;
    }
}
