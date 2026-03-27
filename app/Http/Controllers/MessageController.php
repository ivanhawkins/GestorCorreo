<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Message;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    /**
     * GET /messages
     * Lista mensajes con filtros: account_id, folder, category, search, page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'sometimes|integer',
            'folder'     => 'sometimes|string',
            'category'   => 'sometimes|string',
            'search'     => 'sometimes|string|max:255',
            'page'       => 'sometimes|integer|min:1',
        ]);

        // Obtener IDs de cuentas del usuario
        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        $query = Message::with(['classification', 'attachments'])
            ->whereIn('account_id', $accountIds);

        // Filtro por cuenta específica
        if (!empty($validated['account_id'])) {
            if (!$accountIds->contains($validated['account_id'])) {
                return response()->json(['error' => 'Cuenta no autorizada.'], 403);
            }
            $query->where('account_id', $validated['account_id']);
        }

        // Filtro por carpeta
        if (!empty($validated['folder'])) {
            $query->where('folder', $validated['folder']);
        }

        // Filtro por categoría (join con classifications)
        if (!empty($validated['category'])) {
            $query->whereHas('classification', function ($q) use ($validated) {
                $q->where('final_label', $validated['category']);
            });
        }

        // Búsqueda por asunto o remitente
        if (!empty($validated['search'])) {
            $search = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', $search)
                  ->orWhere('from_name', 'like', $search)
                  ->orWhere('from_email', 'like', $search);
            });
        }

        $query->orderBy('date', 'desc');

        $perPage  = 15;
        $page     = $validated['page'] ?? 1;
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        // Devolver array directo de mensajes (compatibilidad frontend)
        return response()->json($paginated->items());
    }

    /**
     * GET /messages/{id}
     * Detalle completo del mensaje con classification y attachments.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        $message = Message::with(['classification', 'attachments'])
            ->whereIn('account_id', $accountIds)
            ->find($id);

        if (!$message) {
            return response()->json(['error' => 'Mensaje no encontrado.'], 404);
        }

        // Devolver objeto directo (compatibilidad frontend)
        return response()->json($message);
    }

    /**
     * PUT /messages/{id}/read
     * Actualiza el estado is_read del mensaje.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'is_read' => 'required|boolean',
        ]);

        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        $message = Message::whereIn('account_id', $accountIds)->find($id);

        if (!$message) {
            return response()->json(['error' => 'Mensaje no encontrado.'], 404);
        }

        $message->is_read = $validated['is_read'];
        $message->save();

        return response()->json([
            'message' => 'Estado de lectura actualizado.',
            'id'      => $message->id,
            'is_read' => $message->is_read,
        ]);
    }

    /**
     * DELETE /messages/{id}
     * Elimina un mensaje y sus adjuntos.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        $message = Message::with('attachments')
            ->whereIn('account_id', $accountIds)
            ->find($id);

        if (!$message) {
            return response()->json(['error' => 'Mensaje no encontrado.'], 404);
        }

        // Eliminar archivos de adjuntos del disco
        foreach ($message->attachments as $attachment) {
            if ($attachment->local_path) {
                try {
                    // local_path puede ser 'public/attachments/...' o relativo
                    $path = str_replace('public/', '', $attachment->local_path);
                    Storage::disk('public')->delete($path);
                } catch (\Throwable) {
                    // Continuar aunque falle la eliminación del archivo
                }
            }
        }

        // Eliminar adjuntos de BD, clasificación y mensaje
        $message->attachments()->delete();

        if ($message->classification) {
            $message->classification()->delete();
        }

        $message->delete();

        return response()->json(['message' => 'Mensaje eliminado correctamente.']);
    }

    /**
     * PATCH /messages/mark-all-read
     * Marca todos los mensajes como leídos, opcionalmente filtrado por account_id.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'sometimes|integer',
        ]);

        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        $query = Message::whereIn('account_id', $accountIds)
            ->where('is_read', false);

        if (!empty($validated['account_id'])) {
            if (!$accountIds->contains($validated['account_id'])) {
                return response()->json(['error' => 'Cuenta no autorizada.'], 403);
            }
            $query->where('account_id', $validated['account_id']);
        }

        $updated = $query->update(['is_read' => true]);

        return response()->json([
            'message'  => "Se marcaron {$updated} mensajes como leídos.",
            'updated'  => $updated,
        ]);
    }

    /**
     * PUT /messages/{id}/flags  — alias FastAPI
     * Actualiza is_read y/o is_starred.
     */
    public function updateFlags(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $accountIds = Account::where('user_id', $user->id)->where('is_deleted', false)->pluck('id');
        $message = Message::whereIn('account_id', $accountIds)->find($id);

        if (!$message) {
            return response()->json(['error' => 'Mensaje no encontrado.'], 404);
        }

        if ($request->has('is_read'))    $message->is_read    = (bool) $request->input('is_read');
        if ($request->has('is_starred')) $message->is_starred = (bool) $request->input('is_starred');
        $message->save();

        return response()->json(['updated' => 1, 'id' => $message->id]);
    }

    /**
     * PATCH /messages/{id}  — alias FastAPI
     * Actualiza campos del mensaje (is_read, is_starred).
     */
    public function patch(Request $request, string $id): JsonResponse
    {
        return $this->updateFlags($request, $id);
    }
}
