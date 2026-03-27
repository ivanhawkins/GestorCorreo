<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\EncryptionService;
use App\Services\ImapService;
use App\Services\Pop3Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function __construct(private EncryptionService $encryption) {}

    /**
     * GET /accounts
     * Lista las cuentas del usuario autenticado (no eliminadas).
     */
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $deleted = filter_var($request->query('deleted', 'false'), FILTER_VALIDATE_BOOLEAN);

        $accounts = Account::where('user_id', $user->id)
            ->where('is_deleted', $deleted)
            ->orderBy('id')
            ->get();

        // Devolver array directo (compatibilidad frontend)
        return response()->json($accounts);
    }

    /**
     * POST /accounts
     * Crea una nueva cuenta de correo para el usuario autenticado.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email_address'                 => 'required|email|max:255',
            'imap_host'                     => 'required|string|max:255',
            'imap_port'                     => 'required|integer|min:1|max:65535',
            'smtp_host'                     => 'required|string|max:255',
            'smtp_port'                     => 'required|integer|min:1|max:65535',
            'username'                      => 'required|string|max:255',
            'password'                      => 'required|string',
            'protocol'                      => 'sometimes|in:imap,pop3',
            'is_active'                     => 'sometimes|boolean',
            'ssl_verify'                    => 'sometimes|boolean',
            'connection_timeout'            => 'sometimes|integer|min:5|max:300',
            'auto_classify'                 => 'sometimes|boolean',
            'auto_sync_interval'            => 'sometimes|integer|min:0',
            'custom_classification_prompt'  => 'sometimes|nullable|string',
            'owner_profile'                 => 'sometimes|nullable|string|max:1000',
            'mailbox_storage_limit'         => 'sometimes|integer|min:0',
        ]);

        $encryptedPassword = $this->encryption->encrypt($validated['password']);

        $account = Account::create([
            'user_id'                       => $user->id,
            'email_address'                 => $validated['email_address'],
            'imap_host'                     => $validated['imap_host'],
            'imap_port'                     => $validated['imap_port'],
            'smtp_host'                     => $validated['smtp_host'],
            'smtp_port'                     => $validated['smtp_port'],
            'username'                      => $validated['username'],
            'encrypted_password'            => $encryptedPassword,
            'protocol'                      => $validated['protocol']           ?? 'imap',
            'is_active'                     => $validated['is_active']          ?? true,
            'ssl_verify'                    => $validated['ssl_verify']         ?? true,
            'connection_timeout'            => $validated['connection_timeout']  ?? 30,
            'auto_classify'                 => $validated['auto_classify']      ?? false,
            'auto_sync_interval'            => $validated['auto_sync_interval'] ?? 0,
            'custom_classification_prompt'  => $validated['custom_classification_prompt'] ?? null,
            'owner_profile'                 => $validated['owner_profile']      ?? null,
            'mailbox_storage_limit'         => $validated['mailbox_storage_limit'] ?? 0,
            'is_deleted'                    => false,
        ]);

        return response()->json(['account' => $account], 201);
    }

    /**
     * GET /accounts/{id}
     * Detalle de una cuenta (verificar que pertenece al usuario).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $account = Account::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada.'], 404);
        }

        return response()->json(['account' => $account]);
    }

    /**
     * PUT /accounts/{id}
     * Actualiza una cuenta de correo.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $account = Account::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada.'], 404);
        }

        $validated = $request->validate([
            'email_address'                 => 'sometimes|email|max:255',
            'imap_host'                     => 'sometimes|string|max:255',
            'imap_port'                     => 'sometimes|integer|min:1|max:65535',
            'smtp_host'                     => 'sometimes|string|max:255',
            'smtp_port'                     => 'sometimes|integer|min:1|max:65535',
            'username'                      => 'sometimes|string|max:255',
            'password'                      => 'sometimes|string',
            'protocol'                      => 'sometimes|in:imap,pop3',
            'is_active'                     => 'sometimes|boolean',
            'ssl_verify'                    => 'sometimes|boolean',
            'connection_timeout'            => 'sometimes|integer|min:5|max:300',
            'auto_classify'                 => 'sometimes|boolean',
            'auto_sync_interval'            => 'sometimes|integer|min:0',
            'custom_classification_prompt'  => 'sometimes|nullable|string',
            'owner_profile'                 => 'sometimes|nullable|string|max:1000',
            'mailbox_storage_limit'         => 'sometimes|integer|min:0',
        ]);

        // Si viene nueva password, encriptarla
        if (isset($validated['password'])) {
            $validated['encrypted_password'] = $this->encryption->encrypt($validated['password']);
            unset($validated['password']);
        }

        $account->fill($validated);
        $account->save();

        return response()->json(['account' => $account]);
    }

    /**
     * DELETE /accounts/{id}
     * Soft delete: marca is_deleted=true.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $account = Account::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada.'], 404);
        }

        $account->is_deleted = true;
        $account->is_active  = false;
        $account->save();

        return response()->json(['message' => 'Cuenta eliminada correctamente.']);
    }

    /**
     * POST /accounts/{id}/restore
     * Restaura una cuenta que fue marcada como eliminada (soft delete).
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $account = Account::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_deleted', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta eliminada no encontrada.'], 404);
        }

        $account->is_deleted = false;
        $account->is_active  = true;
        $account->save();

        return response()->json(['message' => 'Cuenta restaurada correctamente.', 'account' => $account]);
    }

    /**
     * POST /accounts/{id}/test-connection
     * Intenta conectar al servidor de correo de la cuenta.
     */
    public function testConnection(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $account = Account::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada.'], 404);
        }

        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()], 500);
        }

        $protocol = strtolower($account->protocol ?? 'imap');

        try {
            if ($protocol === 'pop3') {
                $service = new Pop3Service($account, $password);
                $connected = $service->connect();
                if ($connected) {
                    $count = $service->getMessageCount();
                    $service->disconnect();
                    return response()->json([
                        'success'        => true,
                        'message'        => "Conexión POP3 exitosa. {$count} mensajes en el buzón.",
                        'message_count'  => $count,
                    ]);
                }
                return response()->json(['success' => false, 'error' => 'No se pudo conectar al servidor POP3.'], 422);
            } else {
                $service = new ImapService($account, $password);
                $connected = $service->connect(1); // 1 reintento para test
                if ($connected) {
                    $folders = $service->listFolders();
                    $service->disconnect();
                    return response()->json([
                        'success'  => true,
                        'message'  => 'Conexión IMAP exitosa.',
                        'folders'  => $folders,
                    ]);
                }
                return response()->json(['success' => false, 'error' => 'No se pudo conectar al servidor IMAP.'], 422);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error al probar la conexión: ' . $e->getMessage(),
            ], 422);
        }
    }
}
