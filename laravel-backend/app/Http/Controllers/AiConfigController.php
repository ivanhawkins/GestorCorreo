<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AiConfigController extends Controller
{
    /**
     * GET /ai-config
     * Obtiene la configuración IA (primera fila de ai_config).
     * La api_key se devuelve ofuscada como "***".
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
        }

        $config = AiConfig::first();

        if (!$config) {
            return response()->json(['config' => null]);
        }

        return response()->json([
            'config' => [
                'id'              => $config->id,
                'api_url'         => $config->api_url,
                'api_key'         => '***',
                'primary_model'   => $config->primary_model,
                'secondary_model' => $config->secondary_model,
                'updated_at'      => $config->updated_at,
            ],
        ]);
    }

    /**
     * GET /ai-config/models
     * Devuelve los modelos disponibles (lista estática + los configurados).
     */
    public function models(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
        }

        $config = AiConfig::first();

        $models = [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-3.5-turbo',
            'qwen-plus',
            'qwen-turbo',
            'qwen-max',
        ];

        // Incluir modelos configurados si no están en la lista
        if ($config) {
            if ($config->primary_model && !in_array($config->primary_model, $models)) {
                $models[] = $config->primary_model;
            }
            if ($config->secondary_model && !in_array($config->secondary_model, $models)) {
                $models[] = $config->secondary_model;
            }
        }

        return response()->json(['models' => $models]);
    }

    /**
     * PUT /ai-config
     * Actualiza o crea la configuración IA.
     * La api_key no se devuelve en la respuesta (ofuscada como "***").
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
        }

        $validated = $request->validate([
            'api_url'         => 'sometimes|required|string|url|max:500',
            'api_key'         => 'sometimes|required|string|max:500',
            'primary_model'   => 'sometimes|required|string|max:255',
            'secondary_model' => 'sometimes|required|string|max:255',
        ]);

        $config = AiConfig::first();

        if ($config) {
            // Actualizar: solo los campos enviados
            if (isset($validated['api_url'])) {
                $config->api_url = $validated['api_url'];
            }
            if (isset($validated['api_key'])) {
                $config->api_key = $validated['api_key'];
            }
            if (isset($validated['primary_model'])) {
                $config->primary_model = $validated['primary_model'];
            }
            if (isset($validated['secondary_model'])) {
                $config->secondary_model = $validated['secondary_model'];
            }
            $config->updated_at = now();
            $config->save();
        } else {
            // Crear nueva configuración
            $config = AiConfig::create([
                'api_url'         => $validated['api_url']         ?? '',
                'api_key'         => $validated['api_key']         ?? '',
                'primary_model'   => $validated['primary_model']   ?? '',
                'secondary_model' => $validated['secondary_model'] ?? '',
                'updated_at'      => now(),
            ]);
        }

        return response()->json([
            'message' => 'Configuración IA actualizada correctamente.',
            'config'  => [
                'id'              => $config->id,
                'api_url'         => $config->api_url,
                'api_key'         => '***',
                'primary_model'   => $config->primary_model,
                'secondary_model' => $config->secondary_model,
                'updated_at'      => $config->updated_at,
            ],
        ]);
    }
}
