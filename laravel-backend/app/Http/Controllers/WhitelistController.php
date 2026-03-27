<?php

namespace App\Http\Controllers;

use App\Models\ServiceWhitelist;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhitelistController extends Controller
{
    /**
     * GET /whitelist
     * Lista los dominios whitelist del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $entries = ServiceWhitelist::where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        return response()->json(['whitelist' => $entries]);
    }

    /**
     * POST /whitelist
     * Crea una nueva entrada de whitelist para el usuario autenticado.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'domain_pattern' => 'required|string|max:255',
            'description'    => 'sometimes|nullable|string|max:500',
            'is_active'      => 'sometimes|boolean',
        ]);

        $entry = ServiceWhitelist::create([
            'user_id'        => $user->id,
            'domain_pattern' => $validated['domain_pattern'],
            'description'    => $validated['description'] ?? null,
            'is_active'      => $validated['is_active']   ?? true,
        ]);

        return response()->json(['entry' => $entry], 201);
    }

    /**
     * DELETE /whitelist/{id}
     * Elimina una entrada de whitelist (verificar propietario).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $entry = ServiceWhitelist::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$entry) {
            return response()->json(['error' => 'Entrada de whitelist no encontrada.'], 404);
        }

        $entry->delete();

        return response()->json(['message' => 'Entrada eliminada correctamente.']);
    }
}
