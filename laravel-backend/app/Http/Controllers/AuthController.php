<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /auth/login
     * Autentica con username + password y devuelve un token Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        // Acepta JSON y también application/x-www-form-urlencoded (compatibilidad FastAPI)
        $username = $request->input('username');
        $password = $request->input('password');

        if (!$username || !$password) {
            return response()->json(['error' => 'Username y password son requeridos.'], 422);
        }

        $user = User::where('username', $username)->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            return response()->json(['error' => 'Credenciales incorrectas.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'La cuenta de usuario está desactivada.'], 403);
        }

        // Revocar tokens anteriores opcionales (uncomment si se desea sesión única)
        // $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'access_token' => $token,  // compatibilidad FastAPI
            'token'        => $token,
            'token_type'   => 'bearer',
            'user'  => [
                'id'       => $user->id,
                'username' => $user->username,
                'is_active' => $user->is_active,
                'is_admin'  => $user->is_admin,
            ],
        ]);
    }

    /**
     * POST /auth/logout
     * Revoca el token actual del usuario autenticado.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * POST /auth/register
     * Crea un nuevo usuario. Solo admins o si no existen usuarios todavía.
     */
    public function register(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // Verificar si se permite el registro
        $userCount = User::count();
        $isFirstUser = $userCount === 0;

        if (!$isFirstUser) {
            // Debe estar autenticado y ser admin
            if (!$currentUser) {
                return response()->json(['error' => 'No autenticado. Solo administradores pueden crear usuarios.'], 401);
            }
            if (!$currentUser->is_admin) {
                return response()->json(['error' => 'Solo los administradores pueden crear nuevos usuarios.'], 403);
            }
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
            'is_admin'      => $validated['is_admin'] ?? $isFirstUser, // el primer usuario es admin
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'username' => $user->username,
                'is_active' => $user->is_active,
                'is_admin'  => $user->is_admin,
            ],
        ], 201);
    }

    /**
     * GET /auth/me
     * Devuelve el usuario autenticado actualmente.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'No autenticado.'], 401);
        }

        return response()->json([
            'id'       => $user->id,
            'username' => $user->username,
            'is_active' => $user->is_active,
            'is_admin'  => $user->is_admin,
        ]);
    }
}
