<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\EncryptionService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncController extends Controller
{
    public function __construct(
        private SyncService $syncService,
        private EncryptionService $encryption
    ) {}

    /**
     * POST /sync/start
     * Lanza la sincronización de una cuenta y devuelve el resultado como JSON.
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'required|integer',
        ]);

        $account = Account::where('id', $validated['account_id'])
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada o inactiva.'], 404);
        }

        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()], 500);
        }

        try {
            $result = $this->syncService->syncAccount($account, $password);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error durante la sincronización: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /sync/stream
     * SSE — emite eventos de progreso de sincronización en tiempo real.
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'required|integer',
        ]);

        $account = Account::where('id', $validated['account_id'])
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            // En SSE no podemos devolver un código 404 normal fácilmente,
            // devolvemos un stream con error
            return response()->stream(function () {
                echo "data: " . json_encode(['status' => 'error', 'error' => 'Cuenta no encontrada o inactiva.']) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'     => 'text/event-stream',
                'Cache-Control'    => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->stream(function () use ($e) {
                echo "data: " . json_encode(['status' => 'error', 'error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'     => 'text/event-stream',
                'Cache-Control'    => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $syncService = $this->syncService;

        return response()->stream(function () use ($account, $password, $syncService) {
            foreach ($syncService->syncAccountStreaming($account, $password) as $progress) {
                echo "data: " . json_encode($progress) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type'     => 'text/event-stream',
            'Cache-Control'    => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /sync/resync-bodies
     * Re-descarga los cuerpos de mensajes que no tienen body_text ni body_html.
     */
    public function resyncBodies(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'required|integer',
        ]);

        $account = Account::where('id', $validated['account_id'])
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada o inactiva.'], 404);
        }

        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()], 500);
        }

        try {
            $result = $this->syncService->resyncBodies($account, $password);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error durante la re-sincronización de cuerpos: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /sync/resync-attachments
     * Re-descarga los adjuntos de mensajes que no tienen archivos locales.
     */
    public function resyncAttachments(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'required|integer',
        ]);

        $account = Account::where('id', $validated['account_id'])
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada o inactiva.'], 404);
        }

        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()], 500);
        }

        try {
            $result = $this->syncService->resyncAttachments($account, $password);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error durante la re-sincronización de adjuntos: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /sync/status
     * Devuelve las últimas 10 entradas de audit_logs con action='background_sync'.
     */
    public function status(Request $request): JsonResponse
    {
        $logs = AuditLog::where('action', 'background_sync')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json(['logs' => $logs]);
    }
}
