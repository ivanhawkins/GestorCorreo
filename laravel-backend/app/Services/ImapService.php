<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ImapService
{
    /** @var resource|false|null */
    private $connection = null;

    private $account;
    private string $password;
    private string $currentFolder = '';

    // Resultados acumulados del parseo de partes MIME (estado temporal por mensaje)
    private string $tempBodyText  = '';
    private string $tempBodyHtml  = '';
    private array  $tempAttachments = [];

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    // -------------------------------------------------------------------------
    // Conexión
    // -------------------------------------------------------------------------

    /**
     * Conectar al servidor IMAP con reintentos.
     */
    public function connect(int $maxRetries = 3): bool
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            try {
                $host    = $this->account->imap_host;
                $port    = (int) $this->account->imap_port;
                $verify  = (bool) ($this->account->ssl_verify ?? true);
                $timeout = (int) ($this->account->connection_timeout ?? 30);

                $flags = $this->buildImapFlags($port, $verify);
                $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';

                imap_timeout(IMAP_OPENTIMEOUT, $timeout);
                imap_timeout(IMAP_READTIMEOUT, $timeout);
                imap_timeout(IMAP_WRITETIMEOUT, $timeout);
                imap_timeout(IMAP_CLOSETIMEOUT, $timeout);

                $this->connection = @imap_open(
                    $mailbox,
                    $this->account->username,
                    $this->password,
                    0,
                    1
                );

                if ($this->connection !== false) {
                    $this->currentFolder = 'INBOX';
                    Log::info('ImapService: Conexión establecida', [
                        'account' => $this->account->email_address,
                        'host'    => $host,
                        'port'    => $port,
                        'attempt' => $attempt,
                    ]);
                    return true;
                }

                $errors = imap_errors();
                $alerts = imap_alerts();
                Log::warning("ImapService: Intento {$attempt}/{$maxRetries} fallido", [
                    'account' => $this->account->email_address,
                    'errors'  => $errors,
                    'alerts'  => $alerts,
                ]);

                if ($attempt < $maxRetries) {
                    sleep(min($attempt * 2, 10)); // backoff: 2s, 4s, ...
                }
            } catch (\Throwable $e) {
                Log::warning("ImapService: Excepción en intento {$attempt}/{$maxRetries}", [
                    'account' => $this->account->email_address ?? 'unknown',
                    'error'   => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    sleep(min($attempt * 2, 10));
                }
            }
        }

        Log::error('ImapService: Todos los intentos de conexión fallaron', [
            'account'  => $this->account->email_address ?? 'unknown',
            'retries'  => $maxRetries,
        ]);

        return false;
    }

    /**
     * Cierra la conexión IMAP.
     */
    public function disconnect(): void
    {
        if ($this->isConnected()) {
            @imap_close($this->connection);
            $this->connection    = null;
            $this->currentFolder = '';
            Log::info('ImapService: Conexión cerrada', [
                'account' => $this->account->email_address ?? 'unknown',
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Carpetas
    // -------------------------------------------------------------------------

    /**
     * Selecciona (abre) una carpeta del buzón IMAP.
     */
    public function selectFolder(string $folder = 'INBOX'): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        if ($this->currentFolder === $folder) {
            return true;
        }

        try {
            $host    = $this->account->imap_host;
            $port    = (int) $this->account->imap_port;
            $verify  = (bool) ($this->account->ssl_verify ?? true);
            $flags   = $this->buildImapFlags($port, $verify);
            $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;

            $result = @imap_reopen($this->connection, $mailbox);

            if ($result) {
                $this->currentFolder = $folder;
                return true;
            }

            Log::warning('ImapService: No se pudo seleccionar la carpeta', [
                'account' => $this->account->email_address ?? 'unknown',
                'folder'  => $folder,
                'errors'  => imap_errors(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('ImapService: Excepción en selectFolder()', [
                'account' => $this->account->email_address ?? 'unknown',
                'folder'  => $folder,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Lista todas las carpetas del buzón IMAP.
     *
     * @return string[]
     */
    public function listFolders(): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        try {
            $host    = $this->account->imap_host;
            $port    = (int) $this->account->imap_port;
            $verify  = (bool) ($this->account->ssl_verify ?? true);
            $flags   = $this->buildImapFlags($port, $verify);
            $ref     = '{' . $host . ':' . $port . $flags . '}';

            $folders = @imap_list($this->connection, $ref, '*');

            if ($folders === false) {
                Log::warning('ImapService: imap_list() falló', [
                    'account' => $this->account->email_address ?? 'unknown',
                    'errors'  => imap_errors(),
                ]);
                return ['INBOX'];
            }

            // Eliminar el prefijo del servidor de los nombres de carpeta
            $cleanFolders = [];
            foreach ($folders as $folder) {
                // Extraer solo el nombre de la carpeta (eliminar la parte {host:port/flags})
                $name = preg_replace('/^\{[^}]+\}/', '', $folder);
                $cleanFolders[] = $name;
            }

            sort($cleanFolders);
            return $cleanFolders;
        } catch (\Throwable $e) {
            Log::error('ImapService: Excepción en listFolders()', [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
            ]);
            return ['INBOX'];
        }
    }

    // -------------------------------------------------------------------------
    // Mensajes
    // -------------------------------------------------------------------------

    /**
     * Obtiene los UIDs de mensajes nuevos (posteriores al lastUid dado).
     * Si lastUid=0, devuelve todos los UIDs.
     *
     * @return int[]
     */
    public function getNewMessageUids(int $lastUid = 0): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        try {
            if ($lastUid === 0) {
                // Buscar todos los mensajes
                $msgNums = @imap_search($this->connection, 'ALL', SE_UID);
            } else {
                // Buscar mensajes con UID > lastUid
                $nextUid = $lastUid + 1;
                $msgNums = @imap_search($this->connection, 'UID ' . $nextUid . ':*', SE_UID);
            }

            if ($msgNums === false) {
                // imap_search devuelve false si no hay resultados (no es error)
                $errors = imap_errors();
                if (!empty($errors)) {
                    Log::warning('ImapService: imap_search con errores', [
                        'account'  => $this->account->email_address ?? 'unknown',
                        'lastUid'  => $lastUid,
                        'errors'   => $errors,
                    ]);
                }
                return [];
            }

            // Con SE_UID, imap_search ya devuelve UIDs directamente
            $uids = array_map('intval', $msgNums);

            // Si lastUid > 0, filtrar por si acaso imap_search devolvió el lastUid
            if ($lastUid > 0) {
                $uids = array_filter($uids, fn(int $uid) => $uid > $lastUid);
            }

            sort($uids);
            return array_values($uids);
        } catch (\Throwable $e) {
            Log::error('ImapService: Excepción en getNewMessageUids()', [
                'account' => $this->account->email_address ?? 'unknown',
                'lastUid' => $lastUid,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Obtiene los headers de un mensaje por UID.
     *
     * @return array{
     *     uid: int,
     *     message_id: string,
     *     subject: string,
     *     from_name: string,
     *     from_email: string,
     *     to_addresses: string,
     *     cc_addresses: string,
     *     date: \Carbon\Carbon|null,
     *     snippet: string
     * }|null
     */
    public function fetchMessageHeaders(int $uid): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        try {
            // Convertir UID a número de secuencia
            $msgNum = @imap_msgno($this->connection, $uid);
            if ($msgNum === 0 || $msgNum === false) {
                Log::warning('ImapService: UID no encontrado', [
                    'account' => $this->account->email_address ?? 'unknown',
                    'uid'     => $uid,
                ]);
                return null;
            }

            $headerInfo = @imap_headerinfo($this->connection, $msgNum);
            if ($headerInfo === false) {
                Log::warning('ImapService: No se pudo obtener headerinfo', [
                    'account' => $this->account->email_address ?? 'unknown',
                    'uid'     => $uid,
                    'msgNum'  => $msgNum,
                ]);
                return null;
            }

            // From
            $fromName  = '';
            $fromEmail = '';
            if (!empty($headerInfo->from)) {
                $from      = $headerInfo->from[0];
                $fromName  = $this->decodeImapHeader(isset($from->personal) ? $from->personal : '');
                $fromEmail = strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? ''));
            }

            // To
            $toAddresses = $this->extractAddressList($headerInfo->to ?? []);
            // Cc
            $ccAddresses = $this->extractAddressList($headerInfo->cc ?? []);

            // Subject
            $subject = $this->decodeImapHeader($headerInfo->subject ?? '');

            // Message-ID
            $messageId = trim($headerInfo->message_id ?? '');

            // Date
            $dateStr  = $headerInfo->date ?? '';
            $dateParsed = null;
            if ($dateStr !== '') {
                try {
                    $dateParsed = Carbon::parse($dateStr);
                } catch (\Throwable) {
                    $dateParsed = null;
                }
            }

            // Snippet: primeras palabras del body (usando fetch_overview que es más rápido)
            $snippet = '';
            $overview = @imap_fetch_overview($this->connection, (string) $msgNum);
            if ($overview !== false && !empty($overview)) {
                // El snippet lo generaremos al descargar el body completo; aquí dejamos vacío
                $snippet = '';
            }

            return [
                'uid'          => $uid,
                'message_id'   => $messageId,
                'subject'      => $subject,
                'from_name'    => $fromName,
                'from_email'   => $fromEmail,
                'to_addresses' => json_encode($toAddresses, JSON_UNESCAPED_UNICODE),
                'cc_addresses' => json_encode($ccAddresses, JSON_UNESCAPED_UNICODE),
                'date'         => $dateParsed,
                'snippet'      => $snippet,
            ];
        } catch (\Throwable $e) {
            Log::error('ImapService: Excepción en fetchMessageHeaders()', [
                'account' => $this->account->email_address ?? 'unknown',
                'uid'     => $uid,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Descarga el cuerpo completo de un mensaje por UID.
     *
     * @return array{body_text: string, body_html: string, attachments: array}|null
     */
    public function fetchFullMessageBody(int $uid): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        try {
            $msgNum = @imap_msgno($this->connection, $uid);
            if ($msgNum === 0 || $msgNum === false) {
                Log::warning('ImapService: UID no encontrado en fetchFullMessageBody', [
                    'account' => $this->account->email_address ?? 'unknown',
                    'uid'     => $uid,
                ]);
                return null;
            }

            $structure = @imap_fetchstructure($this->connection, $msgNum);
            if ($structure === false) {
                // Fallback: descarga raw
                $raw = @imap_body($this->connection, $msgNum) ?: '';
                return [
                    'body_text'   => $raw,
                    'body_html'   => '',
                    'attachments' => [],
                ];
            }

            // Reiniciar acumuladores
            $this->tempBodyText    = '';
            $this->tempBodyHtml    = '';
            $this->tempAttachments = [];

            $this->parseBodyPart($structure, $msgNum, '');

            return [
                'body_text'   => $this->tempBodyText,
                'body_html'   => $this->tempBodyHtml,
                'attachments' => $this->tempAttachments,
            ];
        } catch (\Throwable $e) {
            Log::error('ImapService: Excepción en fetchFullMessageBody()', [
                'account' => $this->account->email_address ?? 'unknown',
                'uid'     => $uid,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Parseo MIME recursivo
    // -------------------------------------------------------------------------

    /**
     * Navega recursivamente la estructura MIME de un mensaje
     * y acumula los resultados en $this->tempBodyText, $this->tempBodyHtml y $this->tempAttachments.
     */
    private function parseBodyPart(object $structure, int $msgNum, string $partNum): void
    {
        $type = (int) ($structure->type ?? 0);

        if ($type === TYPEMULTIPART) {
            // Parte multipart: procesar cada sub-parte
            if (empty($structure->parts)) {
                return;
            }

            foreach ($structure->parts as $index => $part) {
                $subPartNum = $partNum === '' ? (string)($index + 1) : $partNum . '.' . ($index + 1);
                $this->parseBodyPart($part, $msgNum, $subPartNum);
            }
            return;
        }

        // Sección a usar con imap_fetchbody
        $sectionNum = $partNum === '' ? '1' : $partNum;

        // Detectar nombre de archivo y disposición
        $disposition = strtolower($structure->disposition ?? '');
        $filename    = $this->extractFilename($structure);
        $isAttachment = ($disposition === 'attachment') || ($filename !== '' && $type !== TYPETEXT);

        // Obtener contenido de la parte
        $rawBody = @imap_fetchbody($this->connection, $msgNum, $sectionNum);
        if ($rawBody === false) {
            $rawBody = '';
        }

        $encoding = (int) ($structure->encoding ?? 0);
        $decoded  = $this->decodeBody($rawBody, $encoding);

        // Determinar charset
        $charset = $this->extractCharset($structure);

        if ($isAttachment) {
            $mimeType = $this->buildMimeType($structure);
            $this->tempAttachments[] = [
                'filename'   => $filename !== '' ? $filename : ('attachment_' . uniqid()),
                'mime_type'  => $mimeType,
                'content'    => $decoded,
                'size_bytes' => strlen($decoded),
            ];
            return;
        }

        if ($type === TYPETEXT) {
            // Convertir a UTF-8
            if ($charset !== 'UTF-8' && $charset !== '') {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }

            $subtype = strtolower($structure->subtype ?? 'plain');
            if ($subtype === 'html') {
                $this->tempBodyHtml .= $decoded;
            } else {
                $this->tempBodyText .= $decoded;
            }
        } elseif ($type === TYPEMESSAGE) {
            // Mensaje adjunto (message/rfc822)
            $this->tempAttachments[] = [
                'filename'   => $filename !== '' ? $filename : ('message_' . uniqid() . '.eml'),
                'mime_type'  => 'message/rfc822',
                'content'    => $decoded,
                'size_bytes' => strlen($decoded),
            ];

            // Si tiene sub-partes, procesarlas también
            if (!empty($structure->parts)) {
                foreach ($structure->parts as $index => $part) {
                    $subPartNum = $sectionNum . '.' . ($index + 1);
                    $this->parseBodyPart($part, $msgNum, $subPartNum);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Métodos privados de ayuda
    // -------------------------------------------------------------------------

    /**
     * Construye los flags IMAP para el mailbox string.
     */
    private function buildImapFlags(int $port, bool $verify): string
    {
        // Puerto 993 = IMAP sobre SSL/TLS
        if ($port === 993) {
            $flags = '/imap/ssl';
        } elseif ($port === 143) {
            $flags = '/imap/notls';
        } else {
            $flags = '/imap/ssl';
        }

        if (!$verify) {
            $flags .= '/novalidate-cert';
        }

        return $flags;
    }

    /**
     * Decodifica el cuerpo de una parte MIME según su encoding IMAP.
     */
    private function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case ENC7BIT:
            case ENC8BIT:
            case ENCBINARY:
                return $body;

            case ENCBASE64:
                return base64_decode(str_replace(["\r", "\n", " "], '', $body));

            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($body);

            default:
                return $body;
        }
    }

    /**
     * Extrae el nombre de archivo de los parámetros de una estructura MIME.
     */
    private function extractFilename(object $structure): string
    {
        // Buscar en dparameters (Content-Disposition)
        if (!empty($structure->dparameters)) {
            foreach ($structure->dparameters as $param) {
                $attr = strtolower($param->attribute ?? '');
                if ($attr === 'filename' || $attr === 'filename*') {
                    return $this->decodeImapHeader($param->value ?? '');
                }
            }
        }

        // Buscar en parameters (Content-Type)
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                $attr = strtolower($param->attribute ?? '');
                if ($attr === 'name' || $attr === 'name*') {
                    return $this->decodeImapHeader($param->value ?? '');
                }
            }
        }

        return '';
    }

    /**
     * Extrae el charset de los parámetros de una estructura MIME.
     */
    private function extractCharset(object $structure): string
    {
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute ?? '') === 'charset') {
                    return strtoupper($param->value ?? 'UTF-8');
                }
            }
        }
        return 'UTF-8';
    }

    /**
     * Construye el tipo MIME completo de una estructura IMAP.
     */
    private function buildMimeType(object $structure): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type  = $types[(int) ($structure->type ?? 7)] ?? 'other';
        $sub   = strtolower($structure->subtype ?? 'octet-stream');
        return $type . '/' . $sub;
    }

    /**
     * Extrae una lista de direcciones de email desde los objetos de imap_headerinfo.
     *
     * @param  object[] $addressObjects
     * @return array<array{name: string, email: string}>
     */
    private function extractAddressList(array $addressObjects): array
    {
        $result = [];
        foreach ($addressObjects as $addr) {
            $mailbox = $addr->mailbox ?? '';
            $host    = $addr->host ?? '';
            $email   = strtolower($mailbox . ($host !== '' ? '@' . $host : ''));
            $name    = $this->decodeImapHeader(isset($addr->personal) ? $addr->personal : '');

            if ($email === '' || $email === '@') {
                continue;
            }

            $result[] = [
                'name'  => $name,
                'email' => $email,
            ];
        }
        return $result;
    }

    /**
     * Decodifica una cabecera MIME codificada.
     */
    private function decodeImapHeader(string $header): string
    {
        if ($header === '') {
            return '';
        }

        if (function_exists('imap_mime_header_decode')) {
            $elements = @imap_mime_header_decode($header);
            if ($elements === false) {
                return $header;
            }

            $decoded = '';
            foreach ($elements as $element) {
                $charset = strtoupper($element->charset ?? 'DEFAULT');
                $text    = $element->text ?? '';

                if ($charset === 'DEFAULT' || $charset === 'UTF-8') {
                    $decoded .= $text;
                } else {
                    $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                    $decoded  .= ($converted !== false) ? $converted : $text;
                }
            }

            return $decoded;
        }

        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($header);
        }

        return $header;
    }

    /**
     * Verifica si hay una conexión activa.
     */
    private function isConnected(): bool
    {
        return $this->connection !== null && $this->connection !== false;
    }
}
