<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\EncryptionService;
use App\Services\SmtpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SendController extends Controller
{
    public function __construct(private EncryptionService $encryption) {}

    /**
     * POST /send
     * Envía un email usando SmtpService.
     *
     * Body: {account_id, to, cc?, subject, body_text, body_html?, reply_to?}
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'account_id' => 'required|integer',
            'to'         => 'required',
            'cc'         => 'sometimes|nullable',
            'bcc'        => 'sometimes|nullable',
            'subject'    => 'required|string|max:1000',
            'body_text'  => 'sometimes|nullable|string',
            'body_html'  => 'sometimes|nullable|string',
            'reply_to'   => 'sometimes|nullable|string|email',
        ]);

        // Verificar que la cuenta pertenece al usuario
        $account = Account::where('id', $validated['account_id'])
            ->where('user_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Cuenta no encontrada o inactiva.'], 404);
        }

        // Desencriptar password
        try {
            $password = $this->encryption->decrypt($account->encrypted_password);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo desencriptar la contraseña: ' . $e->getMessage()], 500);
        }

        // Construir datos del email
        $emailData = [
            'to'        => $validated['to'],
            'subject'   => $validated['subject'],
            'body_text' => $validated['body_text'] ?? '',
            'body_html' => $validated['body_html'] ?? null,
        ];

        if (!empty($validated['cc'])) {
            $emailData['cc'] = $validated['cc'];
        }

        if (!empty($validated['bcc'])) {
            $emailData['bcc'] = $validated['bcc'];
        }

        if (!empty($validated['reply_to'])) {
            $emailData['reply_to'] = $validated['reply_to'];
        }

        // Enviar email
        $smtp   = new SmtpService($account, $password);
        $result = $smtp->sendEmail($emailData);

        // Guardar AuditLog
        try {
            AuditLog::create([
                'message_id'    => null,
                'action'        => 'send_email',
                'payload'       => [
                    'account_id' => $account->id,
                    'to'         => $validated['to'],
                    'cc'         => $validated['cc'] ?? null,
                    'subject'    => $validated['subject'],
                ],
                'status'        => $result['status'],
                'error_message' => $result['status'] === 'error' ? $result['message'] : null,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // No fallar si el audit log falla
        }

        if ($result['status'] === 'error') {
            return response()->json(['error' => $result['message']], 500);
        }

        return response()->json([
            'message' => $result['message'],
            'status'  => 'success',
        ]);
    }
}
