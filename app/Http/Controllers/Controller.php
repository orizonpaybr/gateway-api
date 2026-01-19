<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Resposta de sucesso padronizada
     * 
     * @param mixed $data
     * @param string|null $message
     * @return JsonResponse
     */
    protected function successResponse($data, ?string $message = null): JsonResponse
    {
        $response = ['success' => true];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        // Se $data já é um array, usar diretamente, senão tentar converter
        if (is_array($data)) {
            $response['data'] = $data;
        } elseif (is_object($data) && method_exists($data, 'toArray')) {
            $response['data'] = $data->toArray();
        } else {
            $response['data'] = $data;
        }
        
        return response()->json($response)->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Resposta de erro padronizada
     * 
     * @param string $message
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $statusCode = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $statusCode)->header('Access-Control-Allow-Origin', '*');
    }
}
