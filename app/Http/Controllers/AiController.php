<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
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
            'api_url'       => 'sometimes|required|string|url|max:500',
            'api_key'       => 'sometimes|required|string|max:500',
            'primary_model' => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $config = AiConfig::first();
            $apiUrl = $validated['api_url'] ?? $config?->api_url;
            $apiKey = $validated['api_key'] ?? $config?->api_key;
            $model  = $validated['primary_model'] ?? $config?->primary_model ?? 'qwen3';

            if (!$apiUrl || !$apiKey) {
                return response()->json(['success' => false, 'error' => 'Falta api_url o api_key para probar conexión.'], 200);
            }

            $endpoint = $this->resolveChatEndpoint($apiUrl);
            $response = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(12)
                ->post($endpoint, [
                    'prompt' => 'ping',
                    'modelo' => $model,
                    'model'  => $model,
                ]);

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'HTTP ' . $response->status() . ' desde ' . $endpoint . ': ' . mb_substr($response->body(), 0, 300),
                ], 200);
            }

            return response()->json([
                'success'  => true,
                'endpoint' => $endpoint,
                'model'    => $model,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 200);
        }
    }

    private function resolveChatEndpoint(string $apiUrl): string
    {
        $url = rtrim($apiUrl, '/');

        if (str_ends_with($url, '/chat/chat')) {
            return $url;
        }
        if (str_ends_with($url, '/chat/text/chat')) {
            return substr($url, 0, -strlen('/text/chat')) . '/chat';
        }
        if (str_ends_with($url, '/chat')) {
            return $url;
        }

        return $url . '/chat/chat';
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
