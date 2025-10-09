<?php

namespace App\Helpers;

class ApiResponseStandardizer
{
    /**
     * Padroniza resposta de depósito PIX para o frontend
     */
    public static function standardizeDepositResponse($response, $amount = null)
    {
        $standardized = [
            'status' => 'success',
            'message' => 'QR Code gerado com sucesso',
            'transaction_id' => null,
            'amount' => $amount,
            'qr_code' => null,
            'qr_code_image_url' => null,
            'expires_at' => null
        ];

        // Se a resposta já está no formato padrão, retorna como está
        if (isset($response['status']) && $response['status'] === 'success' && isset($response['qr_code_image_url'])) {
            // Verificar se qr_code é válido (não base64 de imagem)
            if (isset($response['qr_code']) && strpos($response['qr_code'], 'data:image') === 0) {
                \Log::warning('ApiResponseStandardizer: qr_code contém base64 de imagem, removendo');
                unset($response['qr_code']);
            }
            return $response;
        }

        // Processar diferentes estruturas de resposta
        if (isset($response['data'])) {
            $data = $response['data'];
            
            // Estrutura BSPay/Pixup
            if (isset($data['idTransaction'])) {
                $standardized['transaction_id'] = $data['idTransaction'];
            }
            
            if (isset($data['qr_code_image_url'])) {
                $standardized['qr_code_image_url'] = $data['qr_code_image_url'];
            }
            
            // IMPORTANTE: qrcode deve ser o código PIX para copia e cola, não base64 de imagem
            if (isset($data['qrcode'])) {
                // Verificar se é base64 de imagem (começa com data:image)
                if (strpos($data['qrcode'], 'data:image') === 0) {
                    // Se for base64 de imagem, não usar como código PIX
                    \Log::warning('ApiResponseStandardizer: qrcode contém base64 de imagem em vez de código PIX');
                } else {
                    $standardized['qr_code'] = $data['qrcode'];
                }
            }
            
            // Também verificar qr_code (com underscore) para compatibilidade
            if (isset($data['qr_code'])) {
                if (strpos($data['qr_code'], 'data:image') === 0) {
                    \Log::warning('ApiResponseStandardizer: qr_code contém base64 de imagem em vez de código PIX');
                } else {
                    $standardized['qr_code'] = $data['qr_code'];
                }
            }
            
            // Estrutura com charge (Woovi) - PROCESSAR PRIMEIRO para sobrescrever outros campos
            if (isset($data['charge'])) {
                $charge = $data['charge'];
                if (isset($charge['id'])) {
                    $standardized['transaction_id'] = $charge['id'];
                }
                if (isset($charge['qrCode'])) {
                    $standardized['qr_code_image_url'] = $charge['qrCode'];
                }
                if (isset($charge['brCode'])) {
                    $standardized['qr_code'] = $charge['brCode'];
                }
            }
        }
        
        // Estrutura direta (alguns adquirentes)
        if (isset($response['idTransaction'])) {
            $standardized['transaction_id'] = $response['idTransaction'];
        }
        
        if (isset($response['qr_code_image_url'])) {
            $standardized['qr_code_image_url'] = $response['qr_code_image_url'];
        }
        
        if (isset($response['qrcode'])) {
            if (strpos($response['qrcode'], 'data:image') === 0) {
                \Log::warning('ApiResponseStandardizer: qrcode contém base64 de imagem em vez de código PIX');
            } else {
                $standardized['qr_code'] = $response['qrcode'];
            }
        }
        
        if (isset($response['qr_code'])) {
            if (strpos($response['qr_code'], 'data:image') === 0) {
                \Log::warning('ApiResponseStandardizer: qr_code contém base64 de imagem em vez de código PIX');
            } else {
                $standardized['qr_code'] = $response['qr_code'];
            }
        }
        
        if (isset($response['charge'])) {
            $charge = $response['charge'];
            if (isset($charge['id'])) {
                $standardized['transaction_id'] = $charge['id'];
            }
            if (isset($charge['qrCode'])) {
                $standardized['qr_code_image_url'] = $charge['qrCode'];
            }
            if (isset($charge['brCode'])) {
                $standardized['qr_code'] = $charge['brCode'];
            }
        }

        // Se não tem QR code image URL, gerar uma
        if (!$standardized['qr_code_image_url'] && $standardized['qr_code']) {
            $standardized['qr_code_image_url'] = self::generateQrCodeImageUrl($standardized['qr_code']);
        }

        // Se não tem transaction_id, gerar um UUID
        if (!$standardized['transaction_id']) {
            $standardized['transaction_id'] = \Illuminate\Support\Str::uuid()->toString();
        }
        
        // Se não tem código PIX válido, usar o transaction_id como fallback
        if (!$standardized['qr_code']) {
            $standardized['qr_code'] = $standardized['transaction_id'];
        }

        return $standardized;
    }

    /**
     * Padroniza resposta de saque PIX para o frontend
     */
    public static function standardizeWithdrawResponse($response, $amount = null)
    {
        $standardized = [
            'status' => 'success',
            'message' => 'Saque solicitado com sucesso',
            'id' => null,
            'amount' => $amount,
            'pixKey' => null,
            'pixKeyType' => null,
            'withdrawStatusId' => 'PendingProcessing',
            'createdAt' => now()->toISOString(),
            'updatedAt' => now()->toISOString()
        ];

        // Se a resposta já está no formato padrão
        if (isset($response['status']) && $response['status'] === 'success' && isset($response['id'])) {
            return $response;
        }

        // Processar diferentes estruturas de resposta
        if (isset($response['data'])) {
            $data = $response['data'];
            
            if (isset($data['id'])) {
                $standardized['id'] = $data['id'];
            }
            
            if (isset($data['amount'])) {
                $standardized['amount'] = $data['amount'];
            }
            
            if (isset($data['pixKey'])) {
                $standardized['pixKey'] = $data['pixKey'];
            }
            
            if (isset($data['pixKeyType'])) {
                $standardized['pixKeyType'] = $data['pixKeyType'];
            }
            
            if (isset($data['withdrawStatusId'])) {
                $standardized['withdrawStatusId'] = $data['withdrawStatusId'];
            }
        }

        // Se não tem ID, gerar um UUID
        if (!$standardized['id']) {
            $standardized['id'] = \Illuminate\Support\Str::uuid()->toString();
        }

        return $standardized;
    }

    /**
     * Padroniza resposta de status de transação
     */
    public static function standardizeStatusResponse($response)
    {
        $standardized = [
            'status' => 'UNKNOWN'
        ];

        // Se a resposta já está no formato padrão
        if (isset($response['status'])) {
            return $response;
        }

        // Processar diferentes estruturas
        if (isset($response['data'])) {
            $data = $response['data'];
            if (isset($data['status'])) {
                $standardized['status'] = $data['status'];
            }
        }

        return $standardized;
    }

    /**
     * Gera URL da imagem do QR Code usando serviço externo
     */
    private static function generateQrCodeImageUrl($qrcode)
    {
        try {
            // Usar serviço gratuito para gerar QR Code
            return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrcode);
        } catch (\Exception $e) {
            \Log::error('Erro ao gerar URL do QR Code: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Padroniza resposta de erro
     */
    public static function standardizeErrorResponse($response, $defaultMessage = 'Erro interno do servidor')
    {
        $standardized = [
            'status' => 'error',
            'message' => $defaultMessage
        ];

        if (isset($response['message'])) {
            $standardized['message'] = $response['message'];
        } elseif (isset($response['error'])) {
            $standardized['message'] = $response['error'];
        } elseif (isset($response['errors'])) {
            if (is_array($response['errors'])) {
                $standardized['message'] = implode(', ', $response['errors']);
            } else {
                $standardized['message'] = $response['errors'];
            }
        }

        return $standardized;
    }
}
