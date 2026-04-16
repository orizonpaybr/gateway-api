<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Simpay\SimpayCpfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SimpayCpfController extends Controller
{
    public function __construct(
        private readonly SimpayCpfService $cpfService,
    ) {}

    /**
     * Valida um CPF via API SIMPAY e retorna dados cadastrais.
     *
     * @OA\Post(
     *     path="/api/simpay/validate-cpf",
     *     tags={"SIMPAY"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"cpf"},
     *             @OA\Property(property="cpf", type="string", example="12345678901")
     *         )
     *     ),
     *     @OA\Response(response="200", description="CPF validado com sucesso"),
     *     @OA\Response(response="400", description="CPF inválido"),
     *     @OA\Response(response="401", description="Não autenticado"),
     *     @OA\Response(response="503", description="Serviço SIMPAY indisponível")
     * )
     */
    public function validate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cpf' => ['required', 'string', 'regex:/^\d{11}$/'],
        ], [
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.regex' => 'O CPF deve conter exatamente 11 dígitos numéricos.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $result = $this->cpfService->validate($request->input('cpf'));

            if (!$result['worked']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'CPF inválido ou não encontrado.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => $result['customer'],
            ]);

        } catch (\RuntimeException $e) {
            Log::error('[SIMPAY][CPF] Erro no controller', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Serviço de validação de CPF temporariamente indisponível.',
            ], 503);
        }
    }
}
