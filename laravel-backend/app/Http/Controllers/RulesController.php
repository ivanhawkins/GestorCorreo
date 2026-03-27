<?php

namespace App\Http\Controllers;

use App\Models\SenderRule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RulesController extends Controller
{
    /**
     * GET /rules
     * Lista todas las reglas de remitente del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $rules = SenderRule::where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        return response()->json(['rules' => $rules]);
    }

    /**
     * POST /rules
     * Crea una nueva regla de remitente.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'sender_email'  => 'required|string|max:255',
            'target_folder' => 'required|string|max:255',
            'is_active'     => 'sometimes|boolean',
        ]);

        $rule = SenderRule::create([
            'user_id'       => $user->id,
            'sender_email'  => $validated['sender_email'],
            'target_folder' => $validated['target_folder'],
            'is_active'     => $validated['is_active'] ?? true,
        ]);

        return response()->json(['rule' => $rule], 201);
    }

    /**
     * GET /rules/{id}
     * Detalle de una regla (verificar propietario).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $rule = SenderRule::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$rule) {
            return response()->json(['error' => 'Regla no encontrada.'], 404);
        }

        return response()->json(['rule' => $rule]);
    }

    /**
     * PUT /rules/{id}
     * Actualiza una regla de remitente (verificar propietario).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $rule = SenderRule::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$rule) {
            return response()->json(['error' => 'Regla no encontrada.'], 404);
        }

        $validated = $request->validate([
            'sender_email'  => 'sometimes|string|max:255',
            'target_folder' => 'sometimes|string|max:255',
            'is_active'     => 'sometimes|boolean',
        ]);

        $rule->fill($validated);
        $rule->save();

        return response()->json(['rule' => $rule]);
    }

    /**
     * DELETE /rules/{id}
     * Elimina una regla de remitente (verificar propietario).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $rule = SenderRule::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$rule) {
            return response()->json(['error' => 'Regla no encontrada.'], 404);
        }

        $rule->delete();

        return response()->json(['message' => 'Regla eliminada correctamente.']);
    }
}
