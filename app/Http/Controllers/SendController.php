<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Message;
use App\Services\EncryptionService;
use App\Services\SmtpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'reply_to_message_id' => 'sometimes|nullable|string|exists:messages,id',
            'attachments' => 'sometimes|array',
            'attachments.*.name' => 'required_with:attachments|string|max:255',
            'attachments.*.mime_type' => 'sometimes|nullable|string|max:120',
            'attachments.*.content_base64' => 'required_with:attachments|string',
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

        if (!empty($validated['reply_to_message_id'])) {
            $original = Message::where('id', $validated['reply_to_message_id'])
                ->where('account_id', $account->id)
                ->first();
            if ($original) {
                $cleanOriginalBody = $this->normalizeQuotedOriginalBody(
                    (string)($original->body_text ?? ''),
                    (string)($original->body_html ?? '')
                );
                $quoted = "\n\n-------- Mensaje original --------\n"
                    . "De: " . ($original->from_email ?? '') . "\n"
                    . "Fecha: " . ($original->date ? $original->date->toDateTimeString() : '') . "\n"
                    . "Asunto: " . ($original->subject ?? '') . "\n\n"
                    . $cleanOriginalBody;
                if (!str_contains((string)$emailData['body_text'], '-------- Mensaje original --------')) {
                    $emailData['body_text'] = ($emailData['body_text'] ?? '') . $quoted;
                }
            }
        }

        if (!empty($validated['attachments']) && is_array($validated['attachments'])) {
            $emailData['attachments'] = [];
            foreach ($validated['attachments'] as $att) {
                $decoded = $this->decodeAttachmentPayload((string)($att['content_base64'] ?? ''));
                if ($decoded === null || $decoded === '') {
                    continue;
                }
                $emailData['attachments'][] = [
                    'name'      => $att['name'] ?? ('attachment_' . uniqid()),
                    'mime_type' => $att['mime_type'] ?? 'application/octet-stream',
                    'content'   => $decoded,
                ];
            }
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

        // Guardar una copia en carpeta "Sent"
        try {
            $messageId = (string) Str::uuid();
            $sentMessage = Message::create([
                'id'              => $messageId,
                'account_id'      => $account->id,
                'imap_uid'        => null,
                'message_id'      => '<local-sent-' . $messageId . '@hawkins.mail>',
                'subject'         => $validated['subject'],
                'from_name'       => '',
                'from_email'      => $account->email_address,
                'to_addresses'    => json_encode($this->normalizeAddressList($validated['to'])),
                'cc_addresses'    => !empty($validated['cc']) ? json_encode($this->normalizeAddressList($validated['cc'])) : '[]',
                'date'            => now(),
                'snippet'         => mb_substr(strip_tags((string)($emailData['body_text'] ?? '')), 0, 200),
                'folder'          => 'Sent',
                'body_text'       => $emailData['body_text'] ?? '',
                'body_html'       => $emailData['body_html'] ?? '',
                'has_attachments' => !empty($emailData['attachments']),
                'is_read'         => true,
                'is_starred'      => false,
                'created_at'      => now(),
            ]);

            if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
                foreach ($emailData['attachments'] as $attachmentData) {
                    $filename = $attachmentData['name'] ?? ('attachment_' . uniqid());
                    $content = $attachmentData['content'] ?? '';
                    $mimeType = $attachmentData['mime_type'] ?? 'application/octet-stream';
                    $sizeBytes = strlen($content);
                    $safeMessageId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sentMessage->id);
                    $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($filename));
                    $uniqueFilename = uniqid('', true) . '_' . $safeFilename;
                    $relativePath = 'attachments/' . $safeMessageId . '/' . $uniqueFilename;
                    Storage::disk('public')->put($relativePath, $content);

                    Attachment::create([
                        'message_id' => $sentMessage->id,
                        'filename'   => $filename,
                        'mime_type'  => $mimeType,
                        'size_bytes' => $sizeBytes,
                        'local_path' => 'public/' . $relativePath,
                    ]);
                }
            }
        } catch (\Throwable) {
            // No bloquear envío si falla el guardado local en "Sent"
        }

        return response()->json([
            'message' => $result['message'],
            'status'  => 'success',
        ]);
    }

    private function normalizeAddressList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(function ($item) {
                if (is_string($item)) return ['name' => '', 'email' => trim($item)];
                if (is_array($item)) return ['name' => (string)($item['name'] ?? ''), 'email' => (string)($item['email'] ?? '')];
                return null;
            }, $raw)));
        }

        $str = trim((string)$raw);
        if ($str === '') return [];

        $parts = array_filter(array_map('trim', explode(',', $str)));
        $res = [];
        foreach ($parts as $p) {
            if (preg_match('/^(.*?)\s*<\s*([^>]+)\s*>$/', $p, $m)) {
                $res[] = ['name' => trim($m[1], "\"' "), 'email' => trim($m[2])];
            } else {
                $res[] = ['name' => '', 'email' => $p];
            }
        }
        return $res;
    }

    private function decodeAttachmentPayload(string $payload): ?string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        // Permitir formato data URL por robustez.
        if (str_contains($payload, ',')) {
            $parts = explode(',', $payload, 2);
            if (isset($parts[1]) && str_starts_with($parts[0], 'data:')) {
                $payload = $parts[1];
            }
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? $payload;
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            $decoded = base64_decode($payload, false);
        }

        return $decoded === false ? null : $decoded;
    }

    private function normalizeQuotedOriginalBody(string $bodyText, string $bodyHtml): string
    {
        $text = trim($bodyText);

        if (preg_match('/=[A-Fa-f0-9]{2}/', $text) || str_contains($text, '=0D=0A')) {
            $qp = preg_replace('/=\r?\n/', '', $text) ?? $text;
            $decoded = quoted_printable_decode($qp);
            if (is_string($decoded) && trim($decoded) !== '') {
                $text = $decoded;
            }
        }

        if ($text === '' || preg_match('/<html|<body|<table|<style/i', $text)) {
            $source = trim($bodyHtml) !== '' ? $bodyHtml : $text;
            $text = strip_tags($source);
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
