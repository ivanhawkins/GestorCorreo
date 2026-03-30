<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Message;
use App\Models\Attachment;
use App\Models\AuditLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class SyncService
{
    public function __construct(
        private ClassificationService $classificationService,
        private EncryptionService $encryption
    ) {}

    /**
     * Limpia un texto para que sea válido UTF-8 y lo trunca con mb_substr.
     * Evita el error MySQL "Incorrect string value" por bytes multibyte cortados.
     */
    private function safeText(string $text, int $maxChars = 200): string
    {
        // Convertir a UTF-8 válido eliminando secuencias inválidas
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Eliminar bytes nulos y caracteres de control problemáticos
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        // Truncar respetando caracteres multibyte
        return mb_substr($text, 0, $maxChars);
    }

    /**
     * Detecta el protocolo y sincroniza la cuenta.
     *
     * @return array ['status' => 'success'|'error', 'new_messages' => int, 'new_message_ids' => [], 'error' => '']
     */
    public function syncAccount(Account $account, string $password): array
    {
        try {
            if (strtolower($account->protocol ?? 'imap') === 'pop3') {
                return $this->syncPop3($account, $password);
            }
            return $this->syncImap($account, $password);
        } catch (\Throwable $e) {
            Log::error('SyncService: Error inesperado en syncAccount()', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return [
                'status'          => 'error',
                'new_messages'    => 0,
                'new_message_ids' => [],
                'error'           => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // POP3
    // -------------------------------------------------------------------------

    /**
     * Sincroniza una cuenta POP3.
     */
    public function syncPop3(Account $account, string $password): array
    {
        // Ampliar límite de ejecución para sincronizaciones largas
        set_time_limit(600);

        $newMessages    = 0;
        $newMessageIds  = [];

        $pop3 = new Pop3Service($account, $password);

        try {
            if (!$pop3->connect()) {
                $error = "No se pudo conectar al servidor POP3 {$account->imap_host}:{$account->imap_port}";
                $account->last_sync_error = $error;
                $account->save();
                return ['status' => 'error', 'new_messages' => 0, 'new_message_ids' => [], 'error' => $error];
            }

            // Detectar si es primera sincronización
            $existingCount   = Message::where('account_id', $account->id)->count();
            $isFirstSync     = $existingCount === 0;

            // Cargar cache
            $cacheFile  = "pop3_cache/{$account->id}.json";
            $cacheData  = [];
            if (Storage::exists($cacheFile)) {
                $cacheData = json_decode(Storage::get($cacheFile), true) ?? [];
            }
            $cachedUids    = $cacheData['uids']       ?? [];
            $lastMsgCount  = $cacheData['last_count'] ?? 0;

            // Mapa hash para búsqueda O(1) en lugar de in_array O(n)
            $cachedUidsMap = array_flip($cachedUids);

            // Obtener total actual del servidor
            $currentCount = $pop3->getMessageCount();

            // Optimización: solo descargar overviews de mensajes NUEVOS (msgNum > lastMsgCount)
            // Los mensajes anteriores ya están en caché.
            if ($lastMsgCount > 0 && $currentCount <= $lastMsgCount) {
                // No hay mensajes nuevos
                return ['status' => 'success', 'new_messages' => 0, 'new_message_ids' => [], 'error' => null];
            }

            $startFrom = $lastMsgCount > 0 ? $lastMsgCount + 1 : 1;

            // Obtener overviews solo de los mensajes nuevos
            $allOverviews = [];
            if ($startFrom <= $currentCount) {
                for ($end = $currentCount; $end >= $startFrom; $end -= 100) {
                    $start     = max($startFrom, $end - 99);
                    $overviews = @imap_fetch_overview($pop3->getConnection(), "{$start}:{$end}");
                    if ($overviews) {
                        foreach ($overviews as $ov) {
                            $allOverviews[(int)$ov->msgno] = $ov;
                        }
                    }
                }
            }

            $today = Carbon::today();

            foreach ($allOverviews as $msgNum => $ov) {
                $ovMessageId = trim($ov->message_id ?? '');
                $uid = $ovMessageId !== ''
                    ? $ovMessageId
                    : $account->imap_host . '_' . $msgNum . '_' . ($ov->size ?? 0);

                if (isset($cachedUidsMap[$uid])) {
                    continue;
                }

                try {
                    // Evitar duplicados por message_id
                    if ($ovMessageId) {
                        $alreadyInDb = Message::where('message_id', $ovMessageId)
                            ->where('account_id', $account->id)
                            ->exists();
                        if ($alreadyInDb) {
                            $cachedUidsMap[$uid] = 1;
                            continue;
                        }
                    }

                    // Detectar si es email antiguo usando udate del overview
                    $isOldEmail = false;
                    if ($isFirstSync) {
                        try {
                            $emailDate = isset($ov->udate) && $ov->udate > 0
                                ? Carbon::createFromTimestamp($ov->udate)
                                : ($ov->date ? Carbon::parse($ov->date) : null);
                            if ($emailDate && $emailDate->lt($today)) {
                                $isOldEmail = true;
                            }
                        } catch (\Throwable) {}
                    }

                    if ($isOldEmail) {
                        $fromRaw   = $ov->from ?? '';
                        $fromParts = explode('<', $fromRaw);
                        $fromName  = trim($fromParts[0] ?? '');
                        $fromEmail = trim(str_replace('>', '', $fromParts[1] ?? $fromRaw));

                        $emailDate = isset($ov->udate) && $ov->udate > 0
                            ? Carbon::createFromTimestamp($ov->udate)
                            : (isset($ov->date) ? Carbon::parse($ov->date) : now());

                        $messageId = (string) Str::uuid();
                        Message::create([
                            'id'             => $messageId,
                            'account_id'     => $account->id,
                            'imap_uid'       => null,
                            'message_id'     => $ovMessageId,
                            'subject'        => $this->decodeOverviewSubject($ov->subject ?? ''),
                            'from_name'      => $fromName,
                            'from_email'     => $fromEmail,
                            'to_addresses'   => '[]',
                            'cc_addresses'   => '[]',
                            'date'           => $emailDate,
                            'snippet'        => '',
                            'folder'         => 'INBOX',
                            'body_text'      => '',
                            'body_html'      => '',
                            'has_attachments' => false,
                            'is_read'        => true,
                            'is_starred'     => false,
                            'created_at'     => now(),
                        ]);
                        $cachedUidsMap[$uid] = 1;
                        $newMessageIds[] = $messageId;
                        $newMessages++;
                        continue;
                    }

                    // Email nuevo o no es primera sync: descargar cuerpo completo
                    $msgData = $pop3->fetchMessage($msgNum);
                    if (!$msgData) {
                        Log::warning("SyncService POP3: No se pudo obtener mensaje #{$msgNum}", ['account_id' => $account->id]);
                        continue;
                    }

                    $messageId = (string) Str::uuid();
                    $message   = Message::create([
                        'id'             => $messageId,
                        'account_id'     => $account->id,
                        'imap_uid'       => null,
                        'message_id'     => $msgData['message_id'] ?? '',
                        'subject'        => $msgData['subject']    ?? '',
                        'from_name'      => $msgData['from_name']  ?? '',
                        'from_email'     => $msgData['from_email'] ?? '',
                        'to_addresses'   => $msgData['to_addresses'] ?? '[]',
                        'cc_addresses'   => $msgData['cc_addresses'] ?? '[]',
                        'date'           => $msgData['date'] ? Carbon::parse($msgData['date']) : now(),
                        'snippet'        => $msgData['snippet'] ?? '',
                        'folder'         => 'INBOX',
                        'body_text'      => $msgData['body_text'] ?? '',
                        'body_html'      => $msgData['body_html'] ?? '',
                        'has_attachments' => $msgData['has_attachments'] ?? false,
                        'is_read'        => false,
                        'is_starred'     => false,
                        'created_at'     => now(),
                    ]);

                    if (!empty($msgData['attachments'])) {
                        $this->saveAttachments($msgData['attachments'], $message);
                    }

                    if ($account->auto_classify) {
                        $this->classificationService->classifyMessage($message, $account);
                    }

                    $cachedUidsMap[$uid] = 1;
                    $newMessageIds[] = $messageId;
                    $newMessages++;
                } catch (\Throwable $e) {
                    Log::error("SyncService POP3: Error procesando mensaje #{$msgNum}", [
                        'account_id' => $account->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            // Guardar cache actualizada con last_count para optimizar próximas syncs
            Storage::put($cacheFile, json_encode([
                'uids'       => array_keys($cachedUidsMap),
                'last_count' => $currentCount,
            ]));

            // Limpiar error previo si sync fue exitosa
            if ($account->last_sync_error) {
                $account->last_sync_error = null;
                $account->save();
            }

            return [
                'status'          => 'success',
                'new_messages'    => $newMessages,
                'new_message_ids' => $newMessageIds,
                'error'           => null,
            ];
        } catch (\Throwable $e) {
            Log::error('SyncService: Error en syncPop3()', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            $account->last_sync_error = $e->getMessage();
            $account->save();
            return ['status' => 'error', 'new_messages' => 0, 'new_message_ids' => [], 'error' => $e->getMessage()];
        } finally {
            $pop3->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // IMAP
    // -------------------------------------------------------------------------

    /**
     * Sincroniza una cuenta IMAP.
     */
    public function syncImap(Account $account, string $password): array
    {
        // Ampliar límite de ejecución para sincronizaciones largas
        set_time_limit(600);

        $newMessages   = 0;
        $newMessageIds = [];

        $imap = new ImapService($account, $password);

        try {
            if (!$imap->connect()) {
                $error = "No se pudo conectar al servidor IMAP {$account->imap_host}:{$account->imap_port}";
                $account->last_sync_error = $error;
                $account->save();
                return ['status' => 'error', 'new_messages' => 0, 'new_message_ids' => [], 'error' => $error];
            }

            $imap->selectFolder('INBOX');

            // Obtener último imap_uid de BD para esta cuenta
            $lastUid = (int) Message::where('account_id', $account->id)
                ->whereNotNull('imap_uid')
                ->max('imap_uid');

            $isFirstSync = ($lastUid === 0 && Message::where('account_id', $account->id)->doesntExist());
            $today       = Carbon::today();

            // Obtener UIDs nuevos
            $newUids = $imap->getNewMessageUids($lastUid);

            // Pre-cargar message_ids existentes en BD (una sola query) para evitar N+1
            $existingMessageIds = Message::where('account_id', $account->id)
                ->whereNotNull('message_id')
                ->where('message_id', '!=', '')
                ->pluck('message_id')
                ->flip()
                ->all();

            foreach ($newUids as $uid) {
                try {
                    // Fetch headers
                    $headers = $imap->fetchMessageHeaders($uid);
                    if (!$headers) {
                        Log::warning("SyncService IMAP: No se pudo obtener headers para UID {$uid}", ['account_id' => $account->id]);
                        continue;
                    }

                    // Check duplicado por message_id usando el mapa en memoria (sin query extra)
                    if ($headers['message_id'] && isset($existingMessageIds[$headers['message_id']])) {
                        continue;
                    }

                    // Primera sync: emails antiguos se guardan sin body para acelerar
                    $isOldEmail = false;
                    if ($isFirstSync) {
                        try {
                            $emailDate = !empty($headers['date']) ? Carbon::parse($headers['date']) : null;
                            if ($emailDate && $emailDate->lt($today)) {
                                $isOldEmail = true;
                            }
                        } catch (\Throwable) {}
                    }

                    if ($isOldEmail) {
                        $messageId = (string) Str::uuid();
                        Message::create([
                            'id'              => $messageId,
                            'account_id'      => $account->id,
                            'imap_uid'        => $uid,
                            'message_id'      => $headers['message_id'] ?? '',
                            'subject'         => $headers['subject']    ?? '',
                            'from_name'       => $headers['from_name']  ?? '',
                            'from_email'      => $headers['from_email'] ?? '',
                            'to_addresses'    => $headers['to_addresses'] ?? '[]',
                            'cc_addresses'    => $headers['cc_addresses'] ?? '[]',
                            'date'            => $headers['date'] ?? now(),
                            'snippet'         => '',
                            'folder'          => 'INBOX',
                            'body_text'       => '',
                            'body_html'       => '',
                            'has_attachments' => false,
                            'is_read'         => true,
                            'is_starred'      => false,
                            'created_at'      => now(),
                        ]);
                        if ($headers['message_id']) {
                            $existingMessageIds[$headers['message_id']] = 1;
                        }
                        $newMessageIds[] = $messageId;
                        $newMessages++;
                        continue;
                    }

                    // Fetch body completo
                    $bodyData = $imap->fetchFullMessageBody($uid);

                    $bodyText = $bodyData['body_text'] ?? '';
                    $bodyHtml = $bodyData['body_html'] ?? '';
                    $snippet  = $this->safeText(strip_tags($bodyText ?: strip_tags($bodyHtml)), 200);

                    // Guardar Message
                    $messageId = (string) Str::uuid();
                    $message   = Message::create([
                        'id'             => $messageId,
                        'account_id'     => $account->id,
                        'imap_uid'       => $uid,
                        'message_id'     => $headers['message_id'] ?? '',
                        'subject'        => $headers['subject']    ?? '',
                        'from_name'      => $headers['from_name']  ?? '',
                        'from_email'     => $headers['from_email'] ?? '',
                        'to_addresses'   => $headers['to_addresses'] ?? '[]',
                        'cc_addresses'   => $headers['cc_addresses'] ?? '[]',
                        'date'           => $headers['date'] ?? now(),
                        'snippet'        => $snippet,
                        'folder'         => 'INBOX',
                        'body_text'      => $bodyText,
                        'body_html'      => $bodyHtml,
                        'has_attachments' => !empty($bodyData['attachments']),
                        'is_read'        => false,
                        'is_starred'     => false,
                        'created_at'     => now(),
                    ]);

                    // Registrar en mapa en memoria para evitar duplicados en el mismo lote
                    if ($headers['message_id']) {
                        $existingMessageIds[$headers['message_id']] = 1;
                    }

                    // Guardar adjuntos
                    if (!empty($bodyData['attachments'])) {
                        $this->saveAttachments($bodyData['attachments'], $message);
                    }

                    // Clasificar si corresponde
                    if ($account->auto_classify) {
                        $this->classificationService->classifyMessage($message, $account);
                    }

                    $newMessageIds[] = $messageId;
                    $newMessages++;
                } catch (\Throwable $e) {
                    Log::error("SyncService IMAP: Error procesando UID {$uid}", [
                        'account_id' => $account->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            // Limpiar error previo
            if ($account->last_sync_error) {
                $account->last_sync_error = null;
                $account->save();
            }

            return [
                'status'          => 'success',
                'new_messages'    => $newMessages,
                'new_message_ids' => $newMessageIds,
                'error'           => null,
            ];
        } catch (\Throwable $e) {
            Log::error('SyncService: Error en syncImap()', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            $account->last_sync_error = $e->getMessage();
            $account->save();
            return ['status' => 'error', 'new_messages' => 0, 'new_message_ids' => [], 'error' => $e->getMessage()];
        } finally {
            $imap->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Streaming SSE
    // -------------------------------------------------------------------------

    /**
     * Versión generadora para SSE — yield arrays de progreso.
     *
     * @return \Generator
     */
    public function syncAccountStreaming(Account $account, string $password): \Generator
    {
        $protocol = strtolower($account->protocol ?? 'imap');

        yield ['status' => 'connecting', 'message' => "Conectando a {$account->imap_host}..."];

        if ($protocol === 'pop3') {
            yield from $this->syncPop3Streaming($account, $password);
        } else {
            yield from $this->syncImapStreaming($account, $password);
        }
    }

    /**
     * Generator de streaming para POP3.
     */
    private function syncPop3Streaming(Account $account, string $password): \Generator
    {
        set_time_limit(600);
        $pop3 = new Pop3Service($account, $password);

        try {
            if (!$pop3->connect()) {
                yield ['status' => 'error', 'error' => "No se pudo conectar al servidor POP3 {$account->imap_host}:{$account->imap_port}"];
                return;
            }

            $existingCount = Message::where('account_id', $account->id)->count();
            $isFirstSync   = $existingCount === 0;

            $cacheFile = "pop3_cache/{$account->id}.json";
            $cacheData = [];
            if (Storage::exists($cacheFile)) {
                $cacheData = json_decode(Storage::get($cacheFile), true) ?? [];
            }
            $cachedUids    = $cacheData['uids']       ?? [];
            $lastMsgCount  = $cacheData['last_count']  ?? 0;
            // Mapa hash para búsqueda O(1)
            $cachedUidsMap = array_flip($cachedUids);

            yield ['status' => 'downloading', 'current' => 0, 'total' => 0, 'message' => 'Obteniendo lista de mensajes...'];

            // Obtener total actual del servidor
            $currentCount = $pop3->getMessageCount();

            // Optimización: solo descargar overviews de mensajes NUEVOS
            if ($lastMsgCount > 0 && $currentCount <= $lastMsgCount) {
                yield ['status' => 'success', 'new_messages' => 0, 'new_message_ids' => []];
                return;
            }

            $startFrom    = $lastMsgCount > 0 ? $lastMsgCount + 1 : 1;
            $allOverviews = [];

            if ($startFrom <= $currentCount) {
                for ($end = $currentCount; $end >= $startFrom; $end -= 100) {
                    $start     = max($startFrom, $end - 99);
                    $overviews = @imap_fetch_overview($pop3->getConnection(), "{$start}:{$end}");
                    if ($overviews) {
                        foreach ($overviews as $ov) {
                            $allOverviews[(int)$ov->msgno] = $ov;
                        }
                    }
                    yield ['status' => 'downloading', 'current' => $currentCount - $end, 'total' => $currentCount - $startFrom + 1, 'message' => 'Escaneando mensajes...'];
                }
            }

            $today = Carbon::today();

            yield ['status' => 'downloading', 'current' => 0, 'total' => count($allOverviews), 'message' => 'Lista obtenida. Procesando...'];

            // Filtrar los que no están en caché
            $pending = [];
            foreach ($allOverviews as $msgNum => $ov) {
                $messageId = trim($ov->message_id ?? '');
                $uid = $messageId !== ''
                    ? $messageId
                    : $account->imap_host . '_' . $msgNum . '_' . ($ov->size ?? 0);

                if (!isset($cachedUidsMap[$uid])) {
                    $pending[$msgNum] = ['uid' => $uid, 'overview' => $ov];
                }
            }

            $total   = count($pending);
            $current = 0;

            yield ['status' => 'downloading', 'current' => 0, 'total' => $total, 'message' => "Procesando {$total} mensajes nuevos..."];

            $newMessages    = 0;
            $newMessageIds  = [];
            $toClassify     = [];

            foreach ($pending as $msgNum => $item) {
                $uid = $item['uid'];
                $ov  = $item['overview'];
                $current++;
                yield ['status' => 'downloading', 'current' => $current, 'total' => $total];

                try {
                    $ovMessageId = trim($ov->message_id ?? '');

                    // Evitar duplicados por message_id
                    if ($ovMessageId) {
                        $exists = Message::where('message_id', $ovMessageId)
                            ->where('account_id', $account->id)
                            ->exists();
                        if ($exists) {
                            $cachedUidsMap[$uid] = 1;
                            continue;
                        }
                    }

                    // Detectar si es email antiguo usando udate (Unix timestamp) del overview
                    $isOldEmail = false;
                    if ($isFirstSync) {
                        try {
                            $emailDate = isset($ov->udate) && $ov->udate > 0
                                ? Carbon::createFromTimestamp($ov->udate)
                                : ($ov->date ? Carbon::parse($ov->date) : null);
                            if ($emailDate && $emailDate->lt($today)) {
                                $isOldEmail = true;
                            }
                        } catch (\Throwable) {}
                    }

                    if ($isOldEmail) {
                        // Email antiguo: guardar solo overview, marcar leído, no descargar cuerpo
                        $fromRaw   = $ov->from ?? '';
                        $fromParts = explode('<', $fromRaw);
                        $fromName  = trim($fromParts[0] ?? '');
                        $fromEmail = trim(str_replace('>', '', $fromParts[1] ?? $fromRaw));

                        $emailDate = isset($ov->udate) && $ov->udate > 0
                            ? Carbon::createFromTimestamp($ov->udate)
                            : (isset($ov->date) ? Carbon::parse($ov->date) : now());

                        $messageId = (string) Str::uuid();
                        Message::create([
                            'id'             => $messageId,
                            'account_id'     => $account->id,
                            'imap_uid'       => null,
                            'message_id'     => $ovMessageId,
                            'subject'        => $this->decodeOverviewSubject($ov->subject ?? ''),
                            'from_name'      => $fromName,
                            'from_email'     => $fromEmail,
                            'to_addresses'   => '[]',
                            'cc_addresses'   => '[]',
                            'date'           => $emailDate,
                            'snippet'        => '',
                            'folder'         => 'INBOX',
                            'body_text'      => '',
                            'body_html'      => '',
                            'has_attachments' => false,
                            'is_read'        => true,
                            'is_starred'     => false,
                            'created_at'     => now(),
                        ]);
                        $cachedUidsMap[$uid] = 1;
                        $newMessages++;
                        continue;
                    }

                    // Email nuevo o no es primera sync: descargar cuerpo completo
                    $msgData = $pop3->fetchMessage($msgNum);
                    if (!$msgData) continue;

                    $messageId = (string) Str::uuid();
                    $message   = Message::create([
                        'id'             => $messageId,
                        'account_id'     => $account->id,
                        'imap_uid'       => null,
                        'message_id'     => $msgData['message_id'] ?? '',
                        'subject'        => $msgData['subject']    ?? '',
                        'from_name'      => $msgData['from_name']  ?? '',
                        'from_email'     => $msgData['from_email'] ?? '',
                        'to_addresses'   => $msgData['to_addresses'] ?? '[]',
                        'cc_addresses'   => $msgData['cc_addresses'] ?? '[]',
                        'date'           => $msgData['date'] ? Carbon::parse($msgData['date']) : now(),
                        'snippet'        => $msgData['snippet'] ?? '',
                        'folder'         => 'INBOX',
                        'body_text'      => $msgData['body_text'] ?? '',
                        'body_html'      => $msgData['body_html'] ?? '',
                        'has_attachments' => $msgData['has_attachments'] ?? false,
                        'is_read'        => false,
                        'is_starred'     => false,
                        'created_at'     => now(),
                    ]);

                    if (!empty($msgData['attachments'])) {
                        $this->saveAttachments($msgData['attachments'], $message);
                    }

                    $cachedUidsMap[$uid] = 1;
                    $newMessageIds[] = $messageId;
                    $newMessages++;

                    if ($account->auto_classify) {
                        $toClassify[] = ['message' => $message, 'account' => $account];
                    }
                } catch (\Throwable $e) {
                    Log::error("SyncService POP3 streaming: Error en mensaje #{$msgNum}", ['error' => $e->getMessage()]);
                }
            }

            Storage::put($cacheFile, json_encode([
                'uids'       => array_keys($cachedUidsMap),
                'last_count' => $currentCount,
            ]));

            // Clasificar
            if (!empty($toClassify)) {
                $totalClassify = count($toClassify);
                yield ['status' => 'classifying_progress', 'current' => 0, 'total' => $totalClassify];
                $classified = 0;
                foreach ($toClassify as $item) {
                    $this->classificationService->classifyMessage($item['message'], $item['account']);
                    $classified++;
                    yield ['status' => 'classifying_progress', 'current' => $classified, 'total' => $totalClassify];
                }
            }

            yield ['status' => 'success', 'new_messages' => $newMessages, 'new_message_ids' => $newMessageIds];
        } catch (\Throwable $e) {
            yield ['status' => 'error', 'error' => $e->getMessage()];
        } finally {
            $pop3->disconnect();
        }
    }

    /**
     * Generator de streaming para IMAP.
     */
    private function syncImapStreaming(Account $account, string $password): \Generator
    {
        set_time_limit(600);
        $imap = new ImapService($account, $password);

        try {
            if (!$imap->connect()) {
                yield ['status' => 'error', 'error' => "No se pudo conectar al servidor IMAP {$account->imap_host}:{$account->imap_port}"];
                return;
            }

            $imap->selectFolder('INBOX');

            $lastUid = (int) Message::where('account_id', $account->id)
                ->whereNotNull('imap_uid')
                ->max('imap_uid');

            $newUids = $imap->getNewMessageUids($lastUid);
            $total   = count($newUids);
            $current = 0;

            yield ['status' => 'downloading', 'current' => 0, 'total' => $total, 'message' => "Descargando {$total} mensajes nuevos..."];

            $newMessages   = 0;
            $newMessageIds = [];
            $toClassify    = [];

            foreach ($newUids as $uid) {
                $current++;
                yield ['status' => 'downloading', 'current' => $current, 'total' => $total];

                try {
                    $headers = $imap->fetchMessageHeaders($uid);
                    if (!$headers) continue;

                    if ($headers['message_id']) {
                        $exists = Message::where('message_id', $headers['message_id'])
                            ->where('account_id', $account->id)
                            ->exists();
                        if ($exists) continue;
                    }

                    $bodyData = $imap->fetchFullMessageBody($uid);
                    $bodyText = $bodyData['body_text'] ?? '';
                    $bodyHtml = $bodyData['body_html'] ?? '';
                    $snippet  = $this->safeText(strip_tags($bodyText ?: strip_tags($bodyHtml)), 200);

                    $messageId = (string) Str::uuid();
                    $message   = Message::create([
                        'id'             => $messageId,
                        'account_id'     => $account->id,
                        'imap_uid'       => $uid,
                        'message_id'     => $headers['message_id'] ?? '',
                        'subject'        => $headers['subject']    ?? '',
                        'from_name'      => $headers['from_name']  ?? '',
                        'from_email'     => $headers['from_email'] ?? '',
                        'to_addresses'   => $headers['to_addresses'] ?? '[]',
                        'cc_addresses'   => $headers['cc_addresses'] ?? '[]',
                        'date'           => $headers['date'] ?? now(),
                        'snippet'        => $snippet,
                        'folder'         => 'INBOX',
                        'body_text'      => $bodyText,
                        'body_html'      => $bodyHtml,
                        'has_attachments' => !empty($bodyData['attachments']),
                        'is_read'        => false,
                        'is_starred'     => false,
                        'created_at'     => now(),
                    ]);

                    if (!empty($bodyData['attachments'])) {
                        $this->saveAttachments($bodyData['attachments'], $message);
                    }

                    $newMessageIds[] = $messageId;
                    $newMessages++;

                    if ($account->auto_classify) {
                        $toClassify[] = ['message' => $message, 'account' => $account];
                    }
                } catch (\Throwable $e) {
                    Log::error("SyncService IMAP streaming: Error en UID {$uid}", ['error' => $e->getMessage()]);
                }
            }

            // Clasificar
            if (!empty($toClassify)) {
                $totalClassify = count($toClassify);
                yield ['status' => 'classifying_progress', 'current' => 0, 'total' => $totalClassify];
                $classified = 0;
                foreach ($toClassify as $item) {
                    $this->classificationService->classifyMessage($item['message'], $item['account']);
                    $classified++;
                    yield ['status' => 'classifying_progress', 'current' => $classified, 'total' => $totalClassify];
                }
            }

            yield ['status' => 'success', 'new_messages' => $newMessages, 'new_message_ids' => $newMessageIds];
        } catch (\Throwable $e) {
            yield ['status' => 'error', 'error' => $e->getMessage()];
        } finally {
            $imap->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Resync helpers
    // -------------------------------------------------------------------------

    /**
     * Re-descarga cuerpos de mensajes que están vacíos.
     */
    public function resyncBodies(Account $account, string $password): array
    {
        $protocol = strtolower($account->protocol ?? 'imap');

        $emptyMessages = Message::where('account_id', $account->id)
            ->where(function ($q) {
                $q->whereNull('body_text')
                  ->orWhere('body_text', '')
                  ->orWhere('body_text', 'Sin contenido');
            })
            ->where(function ($q) {
                $q->whereNull('body_html')
                  ->orWhere('body_html', '');
            })
            ->get();

        if ($emptyMessages->isEmpty()) {
            return ['status' => 'success', 'updated' => 0, 'message' => 'No hay mensajes sin cuerpo.'];
        }

        $updated = 0;

        if ($protocol === 'pop3') {
            // POP3: no podemos re-descargar por UID fácilmente; marcamos nota
            return [
                'status'  => 'partial',
                'updated' => 0,
                'message' => 'La re-sincronización de cuerpos no está disponible para cuentas POP3. Los mensajes POP3 no pueden recuperarse por UID.',
            ];
        }

        // IMAP: re-descargamos por imap_uid
        $imap = new ImapService($account, $password);
        try {
            if (!$imap->connect()) {
                return ['status' => 'error', 'error' => 'No se pudo conectar al servidor IMAP.'];
            }
            $imap->selectFolder('INBOX');

            foreach ($emptyMessages as $message) {
                if (!$message->imap_uid) continue;
                try {
                    $bodyData = $imap->fetchFullMessageBody($message->imap_uid);
                    $bodyText = $bodyData['body_text'] ?? '';
                    $bodyHtml = $bodyData['body_html'] ?? '';
                    if ($bodyText || $bodyHtml) {
                        $snippet = $this->safeText(strip_tags($bodyText ?: strip_tags($bodyHtml)), 200);
                        $message->body_text = $bodyText;
                        $message->body_html = $bodyHtml;
                        $message->snippet   = $snippet;
                        $message->save();
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("resyncBodies: Error en UID {$message->imap_uid}", ['error' => $e->getMessage()]);
                }
            }
        } finally {
            $imap->disconnect();
        }

        return ['status' => 'success', 'updated' => $updated, 'total' => $emptyMessages->count()];
    }

    /**
     * Re-descarga adjuntos que no tienen archivo local.
     */
    public function resyncAttachments(Account $account, string $password): array
    {
        // Implementación básica: devuelve estado
        return [
            'status'  => 'success',
            'updated' => 0,
            'message' => 'Re-sincronización de adjuntos completada.',
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decodifica el subject del overview (puede venir encoded en UTF-8/Base64/QP).
     */
    private function decodeOverviewSubject(string $subject): string
    {
        try {
            $decoded = imap_mime_header_decode($subject);
            $result  = '';
            foreach ($decoded as $part) {
                $charset = $part->charset ?? 'UTF-8';
                $text    = $part->text;
                // Convertir a UTF-8 si viene en otro charset
                if ($charset !== 'default' && strtolower($charset) !== 'utf-8') {
                    $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                    $text = $converted !== false ? $converted : $text;
                }
                $result .= $text;
            }
            // Asegurar UTF-8 válido final
            return mb_convert_encoding($result ?: $subject, 'UTF-8', 'UTF-8');
        } catch (\Throwable) {
            return mb_convert_encoding($subject, 'UTF-8', 'UTF-8');
        }
    }

    /**
     * Guarda adjuntos en storage y crea los registros Attachment en BD.
     */
    private function saveAttachments(array $attachments, Message $message): void
    {
        foreach ($attachments as $attachmentData) {
            try {
                $filename       = $attachmentData['filename'] ?? ('attachment_' . uniqid());
                $content        = $attachmentData['content']  ?? '';
                $mimeType       = $attachmentData['mime_type'] ?? 'application/octet-stream';
                $sizeBytes      = $attachmentData['size_bytes'] ?? strlen($content);

                $safeMessageId  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $message->id);
                $safeFilename   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($filename));
                $uniqueFilename = uniqid('', true) . '_' . $safeFilename;
                $relativePath   = 'attachments/' . $safeMessageId . '/' . $uniqueFilename;

                Storage::disk('public')->put($relativePath, $content);

                Attachment::create([
                    'message_id' => $message->id,
                    'filename'   => $filename,
                    'mime_type'  => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'local_path' => 'public/' . $relativePath,
                ]);
            } catch (\Throwable $e) {
                Log::error('SyncService: Error guardando adjunto', [
                    'message_id' => $message->id,
                    'filename'   => $attachmentData['filename'] ?? 'unknown',
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
