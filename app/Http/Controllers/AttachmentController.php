<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends Controller
{
    /**
     * GET /attachments/{id}/download
     * Descarga el fichero de un adjunto.
     * Verifica que el mensaje pertenece a una cuenta del usuario autenticado.
     */
    public function download(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();

        $attachment = Attachment::with('message')->find($id);

        if (!$attachment) {
            return response()->json(['error' => 'Adjunto no encontrado.'], 404);
        }

        // Verificar que el mensaje del adjunto pertenece a una cuenta del usuario
        $message = $attachment->message;

        if (!$message) {
            return response()->json(['error' => 'Mensaje asociado no encontrado.'], 404);
        }

        $account = Account::where('id', $message->account_id)
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'No tienes permisos para descargar este adjunto.'], 403);
        }

        // Determinar la ruta del archivo en el disco
        $localPath = $attachment->local_path;

        if (!$localPath) {
            return response()->json(['error' => 'El adjunto no tiene una ruta de archivo válida.'], 404);
        }

        // La ruta puede estar guardada como 'public/attachments/...' o como ruta relativa al storage
        // Intentar ambas resoluciones
        $absolutePath = null;

        // Opción 1: ruta completa desde storage_path('app/')
        $candidate1 = storage_path('app/' . $localPath);
        if (file_exists($candidate1)) {
            $absolutePath = $candidate1;
        }

        // Opción 2: ruta directa (ya es absoluta)
        if (!$absolutePath && file_exists($localPath)) {
            $absolutePath = $localPath;
        }

        // Opción 3: ruta relativa sin el prefijo 'public/'
        if (!$absolutePath) {
            $cleanPath = ltrim(str_replace('public/', '', $localPath), '/');
            $candidate3 = storage_path('app/public/' . $cleanPath);
            if (file_exists($candidate3)) {
                $absolutePath = $candidate3;
            }
        }

        if (!$absolutePath) {
            return response()->json(['error' => 'El archivo del adjunto no existe en el servidor.'], 404);
        }

        $mimeType = $attachment->mime_type ?: 'application/octet-stream';
        $filename = $attachment->filename   ?: 'attachment';

        return response()->download($absolutePath, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }
}
