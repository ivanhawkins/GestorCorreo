<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /users
     * Lista todos los usuarios. Solo accesible por administradores.
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->is_admin) {
            return response()->json(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
        }

        $users = User::select(['id', 'username', 'is_active', 'is_admin', 'created_at'])
            ->orderBy('id')
            ->get();

        // Devolver array directo (compatibilidad frontend)
        return response()->json($users);
    }

    /**
     * PUT /users/{id}
     * Actualiza los datos de un usuario. Solo admins pueden modificar otros usuarios;
     * un usuario normal solo puede modificar su propia cuenta.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'No autenticado.'], 401);
        }

        // Solo admin puede modificar otros usuarios
        if ($currentUser->id !== $id && !$currentUser->is_admin) {
            return response()->json(['error' => 'No tienes permisos para modificar este usuario.'], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado.'], 404);
        }

        $validated = $request->validate([
            'username'  => "sometimes|string|max:255|unique:users,username,{$id}",
            'password'  => 'sometimes|string|min:6',
            'is_active' => 'sometimes|boolean',
            'is_admin'  => 'sometimes|boolean',
        ]);

        if (isset($validated['username'])) {
            $user->username = $validated['username'];
        }

        if (isset($validated['password'])) {
            $user->password_hash = bcrypt($validated['password']);
        }

        if (isset($validated['is_active'])) {
            // Solo admin puede activar/desactivar usuarios
            if (!$currentUser->is_admin) {
                return response()->json(['error' => 'Solo los administradores pueden cambiar el estado activo del usuario.'], 403);
            }
            $user->is_active = $validated['is_active'];
        }

        if (isset($validated['is_admin'])) {
            // Solo admin puede promover/revocar permisos
            if (!$currentUser->is_admin) {
                return response()->json(['error' => 'Solo los administradores pueden cambiar permisos de administrador.'], 403);
            }
            $user->is_admin = $validated['is_admin'];
        }

        $user->save();

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'user'    => [
                'id'       => $user->id,
                'username' => $user->username,
                'is_active' => $user->is_active,
                'is_admin'  => $user->is_admin,
            ],
        ]);
    }

    /** POST /users — crear usuario (alias FastAPI) */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (!$currentUser || !$currentUser->is_admin) {
            return response()->json(['error' => 'Solo administradores pueden crear usuarios.'], 403);
        }
        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:users,username',
            'password' => 'required|string|min:6',
            'is_admin' => 'sometimes|boolean',
        ]);
        $user = User::create([
            'username'      => $validated['username'],
            'password_hash' => bcrypt($validated['password']),
            'is_active'     => true,
            'is_admin'      => $validated['is_admin'] ?? false,
        ]);
        return response()->json($user, 201);
    }

    /** DELETE /users/{id} — soft delete */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (!$currentUser || !$currentUser->is_admin) {
            return response()->json(['error' => 'Acceso denegado.'], 403);
        }
        $user = User::find($id);
        if (!$user) return response()->json(['error' => 'Usuario no encontrado.'], 404);
        $user->delete(); // SoftDeletes
        return response()->json(['message' => 'Usuario eliminado.']);
    }

    /** PUT /users/{id}/password */
    public function updatePassword(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (!$currentUser) return response()->json(['error' => 'No autenticado.'], 401);
        if ($currentUser->id !== $id && !$currentUser->is_admin) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $validated = $request->validate(['password' => 'required|string|min:6']);
        $user = User::find($id);
        if (!$user) return response()->json(['error' => 'Usuario no encontrado.'], 404);
        $user->password_hash = bcrypt($validated['password']);
        $user->save();
        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
