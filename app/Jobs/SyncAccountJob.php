<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\EncryptionService;
use App\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300; // 5 minutos

    public function __construct(public int $accountId) {}

    /**
     * Ejecuta la sincronización de la cuenta en background.
     */
    public function handle(SyncService $syncService, EncryptionService $encryption): void
    {
        $startedAt = now();

        try {
            // 1. Obtener la cuenta
            $account = Account::find($this->accountId);

            if (!$account) {
                Log::warning("SyncAccountJob: Cuenta #{$this->accountId} no encontrada.");
                return;
            }

            if (!$account->is_active || $account->is_deleted) {
                Log::info("SyncAccountJob: Cuenta #{$this->accountId} inactiva o eliminada, omitiendo.");
                return;
            }

            // 2. Desencriptar la password
            $password = $encryption->decrypt($account->encrypted_password);

            // 3. Sincronizar
            Log::info("SyncAccountJob: Iniciando sync para cuenta #{$this->accountId} ({$account->email_address})");

            $result = $syncService->syncAccount($account, $password);

            // 4. Registrar resultado en audit_logs
            AuditLog::create([
                'message_id'    => null,
                'action'        => 'background_sync',
                'payload'       => [
                    'account_id'    => $this->accountId,
                    'email_address' => $account->email_address,
                    'new_messages'  => $result['new_messages'] ?? 0,
                    'duration_ms'   => now()->diffInMilliseconds($startedAt),
                ],
                'status'        => $result['status'] ?? 'success',
                'error_message' => $result['error'] ?? null,
                'created_at'    => now(),
            ]);

            Log::info("SyncAccountJob: Sync completado para cuenta #{$this->accountId}", [
                'new_messages' => $result['new_messages'] ?? 0,
                'status'       => $result['status'] ?? 'success',
            ]);
        } catch (\Throwable $e) {
            Log::error("SyncAccountJob: Error en sync de cuenta #{$this->accountId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Registrar error en audit_logs
            try {
                AuditLog::create([
                    'message_id'    => null,
                    'action'        => 'background_sync',
                    'payload'       => [
                        'account_id'  => $this->accountId,
                        'duration_ms' => now()->diffInMilliseconds($startedAt),
                    ],
                    'status'        => 'error',
                    'error_message' => $e->getMessage(),
                    'created_at'    => now(),
                ]);
            } catch (\Throwable $logError) {
                Log::error('SyncAccountJob: No se pudo guardar audit log de error', ['error' => $logError->getMessage()]);
            }

            // Re-lanzar para que el queue manager maneje los reintentos
            throw $e;
        }
    }
}
