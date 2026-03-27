<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    /**
     * GET /system/health
     * Devuelve el estado de salud de la API.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'version'   => '0.4',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /audit-logs
     * Devuelve las últimas 50 entradas de audit_logs, ordenadas por created_at DESC.
     * Solo accesible por administradores.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
        }

        $logs = AuditLog::orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['logs' => $logs]);
    }
}
