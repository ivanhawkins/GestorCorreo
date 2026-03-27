<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * GET /categories
     * Lista las categorías del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $categories = Category::where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        // Devolver array directo (compatibilidad frontend)
        return response()->json($categories);
    }

    /**
     * POST /categories
     * Crea una nueva categoría para el usuario autenticado.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'key'            => "required|string|max:100|unique:categories,key,NULL,id,user_id,{$user->id}",
            'name'           => 'required|string|max:255',
            'description'    => 'sometimes|nullable|string|max:1000',
            'ai_instruction' => 'sometimes|nullable|string',
            'icon'           => 'sometimes|nullable|string|max:100',
            'is_system'      => 'sometimes|boolean',
        ]);

        $category = Category::create([
            'user_id'        => $user->id,
            'key'            => $validated['key'],
            'name'           => $validated['name'],
            'description'    => $validated['description']    ?? null,
            'ai_instruction' => $validated['ai_instruction'] ?? null,
            'icon'           => $validated['icon']           ?? null,
            'is_system'      => $validated['is_system']      ?? false,
        ]);

        return response()->json(['category' => $category], 201);
    }

    /**
     * GET /categories/{id}
     * Detalle de una categoría (verificar propietario).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $category = Category::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$category) {
            return response()->json(['error' => 'Categoría no encontrada.'], 404);
        }

        return response()->json(['category' => $category]);
    }

    /**
     * PUT /categories/{id}
     * Actualiza una categoría (verificar propietario).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $category = Category::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$category) {
            return response()->json(['error' => 'Categoría no encontrada.'], 404);
        }

        $validated = $request->validate([
            'key'            => "sometimes|string|max:100|unique:categories,key,{$id},id,user_id,{$user->id}",
            'name'           => 'sometimes|string|max:255',
            'description'    => 'sometimes|nullable|string|max:1000',
            'ai_instruction' => 'sometimes|nullable|string',
            'icon'           => 'sometimes|nullable|string|max:100',
            'is_system'      => 'sometimes|boolean',
        ]);

        $category->fill($validated);
        $category->save();

        return response()->json(['category' => $category]);
    }

    /**
     * DELETE /categories/{id}
     * Elimina una categoría. No se pueden borrar categorías de sistema.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $category = Category::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$category) {
            return response()->json(['error' => 'Categoría no encontrada.'], 404);
        }

        if ($category->is_system) {
            return response()->json(['error' => 'No se pueden eliminar categorías de sistema.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Categoría eliminada correctamente.']);
    }
}
