<?php

namespace App\Http\Controllers;

use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    public function __construct(private AiService $aiService) {}

    /**
     * GET /api/ai/status
     * Comprueba si la IA está disponible.
     */
    public function status(): JsonResponse
    {
        $result = $this->aiService->checkStatus();
        return response()->json($result);
    }

    /**
     * POST /api/ai/test
     * Prueba una conexión con la URL y API Key proporcionadas (sin guardar).
     * Devuelve los modelos disponibles si la conexión funciona.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_url' => 'required|string|url|max:500',
            'api_key' => 'required|string|max:500',
        ]);

        try {
            // Endpoint de modelos: {api_url}/models
            $modelsEndpoint = rtrim($validated['api_url'], '/') . '/models';

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'x-api-key' => $validated['api_key'],
                ])
                ->timeout(10)
                ->get($modelsEndpoint);

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'HTTP ' . $response->status() . ' desde ' . $modelsEndpoint . ': ' . mb_substr($response->body(), 0, 300),
                ], 200);
            }

            $data = $response->json();

            // Extraer lista de modelos según el formato de la respuesta
            $models = [];
            if (is_array($data)) {
                if (isset($data['models']) && is_array($data['models'])) {
                    $models = array_values(array_filter(array_map(fn($m) => is_string($m) ? $m : ($m['id'] ?? $m['name'] ?? null), $data['models'])));
                } elseif (isset($data['data']) && is_array($data['data'])) {
                    $models = collect($data['data'])->pluck('id')->filter()->values()->toArray();
                } elseif (array_is_list($data)) {
                    $models = array_values(array_filter(array_map(fn($m) => is_string($m) ? $m : ($m['id'] ?? $m['name'] ?? null), $data)));
                }
            }

            return response()->json(['success' => true, 'models' => $models]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * POST /api/ai/generate_reply
     * Genera una respuesta de email con IA.
     */
    public function generateReply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_from_name'  => 'nullable|string|max:255',
            'original_from_email' => 'required|string|max:255',
            'original_subject'    => 'nullable|string|max:500',
            'original_body'       => 'nullable|string|max:5000',
            'user_instruction'    => 'nullable|string|max:1000',
            'owner_profile'       => 'nullable|string|max:1000',
        ]);

        try {
            $replyBody = $this->aiService->generateReply(
                $validated['original_from_name']  ?? '',
                $validated['original_from_email'],
                $validated['original_subject']    ?? '',
                $validated['original_body']       ?? '',
                $validated['user_instruction']    ?? 'Genera una respuesta profesional.',
                $validated['owner_profile']       ?? 'Eres un asistente profesional y educado.'
            );

            return response()->json(['reply_body' => $replyBody]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
