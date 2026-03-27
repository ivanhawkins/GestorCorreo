<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class Pop3Service
{
    /** @var resource|false|null */
    private $connection = null;

    private $account;
    private string $password;

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    // -------------------------------------------------------------------------
    // Conexión
    // -------------------------------------------------------------------------

    /**
     * Conectar al servidor POP3 usando la extensión imap de PHP.
     * Usa /pop3/ssl o /pop3 según la configuración de la cuenta.
     */
    public function connect(): bool
    {
        try {
            $host    = $this->account->imap_host;
            $port    = (int) $this->account->imap_port;
            $useSSL  = ($port === 995 || stripos((string)($this->account->imap_host ?? ''), 'ssl') !== false);
            $verify  = (bool) ($this->account->ssl_verify ?? true);
            $timeout = (int) ($this->account->connection_timeout ?? 30);

            // Construir el mailbox string de POP3
            if ($useSSL) {
                $flags = '/pop3/ssl';
                if (!$verify) {
                    $flags .= '/novalidate-cert';
                }
            } else {
                $flags = '/pop3/notls';
                if (!$verify) {
                    $flags .= '/novalidate-cert';
                }
            }

            $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';

            // Configurar timeout
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

            if ($this->connection === false) {
                $errors = imap_errors();
                $alerts = imap_alerts();
                Log::error('Pop3Service: Error de conexión', [
                    'account' => $this->account->email_address,
                    'mailbox' => $mailbox,
                    'errors'  => $errors,
                    'alerts'  => $alerts,
                ]);
                return false;
            }

            Log::info('Pop3Service: Conexión establecida', [
                'account' => $this->account->email_address,
                'host'    => $host,
                'port'    => $port,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Pop3Service: Excepción en connect()', [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Expone el recurso de conexión (para uso desde SyncService en llamadas directas a imap_*).
     * @return resource|false|null
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Cierra la conexión POP3.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null && $this->connection !== false) {
            @imap_close($this->connection, CL_EXPUNGE);
            $this->connection = null;
            Log::info('Pop3Service: Conexión cerrada', [
                'account' => $this->account->email_address ?? 'unknown',
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Operaciones de mensajes
    // -------------------------------------------------------------------------

    /**
     * Devuelve el número total de mensajes en el buzón.
     */
    public function getMessageCount(): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            $count = imap_num_msg($this->connection);
            return $count !== false ? (int) $count : 0;
        } catch (\Throwable $e) {
            Log::error('Pop3Service: Error en getMessageCount()', [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Obtiene todos los UIDLs disponibles (identificadores únicos por mensaje).
     * En POP3, la extensión imap no expone UIDL nativo, así que usamos
     * el Message-ID del header como identificador estable.
     *
     * @return array<int, string>  ['msg_num' => 'uid_string']
     */
    /**
     * Descarga overviews en batches de $batchSize (más robusto que una sola llamada masiva).
     * Orden descendente (más recientes primero) para procesar emails nuevos antes.
     *
     * @return array<int, object>  ['msg_num' => stdClass{message_id, subject, from, date, size}]
     */
    public function getAllOverviews(int $batchSize = 100): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        $count = $this->getMessageCount();
        if ($count === 0) return [];

        $result = [];

        // Procesar de más reciente a más antiguo, en batches
        for ($end = $count; $end >= 1; $end -= $batchSize) {
            $start     = max(1, $end - $batchSize + 1);
            $sequence  = "{$start}:{$end}";

            try {
                $overviews = @imap_fetch_overview($this->connection, $sequence);
                if (!$overviews) continue;

                foreach ($overviews as $ov) {
                    $msgNum          = (int) $ov->msgno;
                    $result[$msgNum] = $ov;
                }
            } catch (\Throwable $e) {
                Log::warning('Pop3Service: Error en batch overview ' . $sequence, [
                    'account' => $this->account->email_address ?? 'unknown',
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Devolver en orden descendente (más reciente primero)
        krsort($result);
        return $result;
    }

    public function getAllUidls(): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        $overviews = $this->getAllOverviews();
        $uidls     = [];

        foreach ($overviews as $msgNum => $ov) {
            $messageId = trim($ov->message_id ?? '');
            $uid = $messageId !== ''
                ? $messageId
                : $this->account->imap_host . '_' . $msgNum . '_' . ($ov->size ?? 0);
            $uidls[$msgNum] = $uid;
        }

        return $uidls;
    }

    /**
     * Descarga un mensaje completo (headers + body) y lo devuelve como array estructurado.
     *
     * @return array{
     *     message_id: string,
     *     subject: string,
     *     from_name: string,
     *     from_email: string,
     *     to_addresses: string,
     *     cc_addresses: string,
     *     date: string,
     *     snippet: string,
     *     body_text: string,
     *     body_html: string,
     *     has_attachments: bool,
     *     attachments: array
     * }|null
     */
    public function fetchMessage(int $msgNum): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        try {
            // Obtener headers
            $headerInfo = @imap_headerinfo($this->connection, $msgNum);
            if ($headerInfo === false) {
                Log::warning('Pop3Service: No se pudo obtener headerinfo para mensaje ' . $msgNum);
                return null;
            }

            $rawHeader = @imap_fetchheader($this->connection, $msgNum) ?: '';

            // Parsear direcciones
            $fromName  = '';
            $fromEmail = '';

            if (!empty($headerInfo->from)) {
                $from      = $headerInfo->from[0];
                $fromName  = $this->decodeImapHeader(isset($from->personal) ? $from->personal : '');
                $fromEmail = strtolower(trim(($from->mailbox ?? '') . '@' . ($from->host ?? '')));
            }

            $toAddresses = $this->extractAddresses($headerInfo->to ?? []);
            $ccAddresses = $this->extractAddresses($headerInfo->cc ?? []);

            $subject   = $this->decodeImapHeader($headerInfo->subject ?? '');
            $messageId = trim($headerInfo->message_id ?? '');
            $date      = $headerInfo->date ?? '';

            // Obtener body
            $bodyData = $this->getMessageBody($msgNum);

            $snippet = substr(strip_tags($bodyData['body_text'] ?: strip_tags($bodyData['body_html'])), 0, 200);

            return [
                'message_id'     => $messageId,
                'subject'        => $subject,
                'from_name'      => $fromName,
                'from_email'     => $fromEmail,
                'to_addresses'   => json_encode($toAddresses, JSON_UNESCAPED_UNICODE),
                'cc_addresses'   => json_encode($ccAddresses, JSON_UNESCAPED_UNICODE),
                'date'           => $date,
                'snippet'        => $snippet,
                'body_text'      => $bodyData['body_text'],
                'body_html'      => $bodyData['body_html'],
                'has_attachments' => count($bodyData['attachments']) > 0,
                'attachments'    => $bodyData['attachments'],
            ];
        } catch (\Throwable $e) {
            Log::error('Pop3Service: Error en fetchMessage() para mensaje ' . $msgNum, [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Obtiene solo los headers de un mensaje (más rápido que fetchMessage completo).
     * Útil para verificar duplicados sin descargar el cuerpo.
     *
     * @return array{message_id: string, subject: string, from: string, to: string, cc: string, date: string}|null
     */
    public function fetchHeadersOnly(int $msgNum): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        try {
            $headerInfo = @imap_headerinfo($this->connection, $msgNum);
            if ($headerInfo === false) {
                return null;
            }

            $fromParts = [];
            if (!empty($headerInfo->from)) {
                $from        = $headerInfo->from[0];
                $personalDec = $this->decodeImapHeader(isset($from->personal) ? $from->personal : '');
                $email       = strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? ''));

                $fromParts = $personalDec !== ''
                    ? $personalDec . ' <' . $email . '>'
                    : $email;
            }

            $toParts = [];
            foreach ($headerInfo->to ?? [] as $addr) {
                $email    = ($addr->mailbox ?? '') . '@' . ($addr->host ?? '');
                $personal = $this->decodeImapHeader(isset($addr->personal) ? $addr->personal : '');
                $toParts[] = $personal !== '' ? $personal . ' <' . $email . '>' : $email;
            }

            $ccParts = [];
            foreach ($headerInfo->cc ?? [] as $addr) {
                $email    = ($addr->mailbox ?? '') . '@' . ($addr->host ?? '');
                $personal = $this->decodeImapHeader(isset($addr->personal) ? $addr->personal : '');
                $ccParts[] = $personal !== '' ? $personal . ' <' . $email . '>' : $email;
            }

            return [
                'message_id' => trim($headerInfo->message_id ?? ''),
                'subject'    => $this->decodeImapHeader($headerInfo->subject ?? ''),
                'from'       => is_array($fromParts) ? implode(', ', $fromParts) : (string) $fromParts,
                'to'         => implode(', ', $toParts),
                'cc'         => implode(', ', $ccParts),
                'date'       => $headerInfo->date ?? '',
            ];
        } catch (\Throwable $e) {
            Log::error('Pop3Service: Error en fetchHeadersOnly() para mensaje ' . $msgNum, [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrae el body_text, body_html y attachments de un mensaje.
     * Navega la estructura MIME recursivamente.
     *
     * @return array{body_text: string, body_html: string, attachments: array}
     */
    public function getMessageBody(int $msgNum): array
    {
        $result = [
            'body_text'   => '',
            'body_html'   => '',
            'attachments' => [],
        ];

        if (!$this->isConnected()) {
            return $result;
        }

        try {
            $structure = @imap_fetchstructure($this->connection, $msgNum);
            if ($structure === false) {
                // Fallback: intentar descargar el body directamente como texto plano
                $rawBody = @imap_body($this->connection, $msgNum) ?: '';
                $result['body_text'] = $rawBody;
                return $result;
            }

            $this->processMimePart($structure, $msgNum, '', $result);
        } catch (\Throwable $e) {
            Log::error('Pop3Service: Error en getMessageBody() para mensaje ' . $msgNum, [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Métodos privados de ayuda
    // -------------------------------------------------------------------------

    /**
     * Procesa una parte MIME de forma recursiva.
     * Navega multipart/*, text/plain, text/html, y attachments.
     */
    private function processMimePart(object $structure, int $msgNum, string $partNum, array &$result): void
    {
        // TYPETEXT=0, TYPEMULTIPART=1, TYPEMESSAGE=2, TYPEAPPLICATION=3, TYPEAUDIO=4, TYPEIMAGE=5, TYPEVIDEO=6, TYPEOTHER=7
        $type = (int) ($structure->type ?? 0);

        if ($type === TYPEMULTIPART) {
            // Parte multipart: iterar las sub-partes
            foreach ($structure->parts as $index => $part) {
                $subPartNum = $partNum === '' ? (string)($index + 1) : $partNum . '.' . ($index + 1);
                $this->processMimePart($part, $msgNum, $subPartNum, $result);
            }
            return;
        }

        // Determinar número de sección para imap_fetchbody
        $sectionNum = $partNum === '' ? '1' : $partNum;

        // Detectar si es adjunto
        $disposition = '';
        $filename    = '';

        if (!empty($structure->disposition)) {
            $disposition = strtolower($structure->disposition);
        }

        // Intentar obtener el nombre del archivo de los parámetros
        if (!empty($structure->dparameters)) {
            foreach ($structure->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename' || strtolower($param->attribute) === 'filename*') {
                    $filename = $this->decodeImapHeader($param->value);
                    break;
                }
            }
        }

        if ($filename === '' && !empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'name' || strtolower($param->attribute) === 'name*') {
                    $filename = $this->decodeImapHeader($param->value);
                    break;
                }
            }
        }

        $isAttachment = ($disposition === 'attachment') || ($filename !== '' && $type !== TYPETEXT);

        // Obtener el contenido de la sección
        $rawBody = @imap_fetchbody($this->connection, $msgNum, $sectionNum);
        if ($rawBody === false) {
            $rawBody = '';
        }

        // Decodificar según el encoding de la parte
        $encoding = (int) ($structure->encoding ?? 0);
        // 0=7BIT,1=8BIT,2=BINARY,3=BASE64,4=QUOTED-PRINTABLE,5=OTHER
        $decoded = $this->decodeBody($rawBody, $encoding);

        // Determinar charset
        $charset = 'UTF-8';
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = strtoupper($param->value);
                    break;
                }
            }
        }

        if ($isAttachment) {
            $mimeType = $this->getMimeType($structure);
            $result['attachments'][] = [
                'filename'   => $filename !== '' ? $filename : 'attachment_' . uniqid(),
                'mime_type'  => $mimeType,
                'content'    => $decoded,
                'size_bytes' => strlen($decoded),
            ];
            return;
        }

        // Es parte de texto
        if ($type === TYPETEXT) {
            $subtype = strtolower($structure->subtype ?? 'plain');

            // Convertir a UTF-8 si es necesario
            if ($charset !== 'UTF-8' && $charset !== '') {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }

            if ($subtype === 'html') {
                $result['body_html'] .= $decoded;
            } else {
                $result['body_text'] .= $decoded;
            }
        } elseif ($type === TYPEMESSAGE) {
            // Mensaje adjunto (message/rfc822) - tratarlo como adjunto
            $result['attachments'][] = [
                'filename'   => $filename !== '' ? $filename : 'message_' . uniqid() . '.eml',
                'mime_type'  => 'message/rfc822',
                'content'    => $decoded,
                'size_bytes' => strlen($decoded),
            ];
        }
    }

    /**
     * Decodifica el cuerpo de una parte MIME según su encoding IMAP.
     */
    private function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case ENC7BIT:    // 0
            case ENC8BIT:    // 1
            case ENCBINARY:  // 2
                return $body;

            case ENCBASE64:  // 3
                return base64_decode(str_replace(["\r", "\n", " "], '', $body));

            case ENCQUOTEDPRINTABLE: // 4
                return quoted_printable_decode($body);

            default:
                return $body;
        }
    }

    /**
     * Construye el tipo MIME de una estructura IMAP.
     */
    private function getMimeType(object $structure): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type  = $types[(int) ($structure->type ?? 7)] ?? 'other';
        $sub   = strtolower($structure->subtype ?? 'octet-stream');
        return $type . '/' . $sub;
    }

    /**
     * Extrae un array de direcciones de email desde los objetos stdClass de imap_headerinfo.
     *
     * @return array<array{name: string, email: string}>
     */
    private function extractAddresses(array $addressObjects): array
    {
        $addresses = [];
        foreach ($addressObjects as $addr) {
            $mailbox = $addr->mailbox ?? '';
            $host    = $addr->host ?? '';
            $email   = strtolower($mailbox . ($host !== '' ? '@' . $host : ''));
            $name    = $this->decodeImapHeader(isset($addr->personal) ? $addr->personal : '');

            if ($email === '' || $email === '@') {
                continue;
            }

            $addresses[] = [
                'name'  => $name,
                'email' => $email,
            ];
        }
        return $addresses;
    }

    /**
     * Decodifica un header MIME usando imap_mime_header_decode o mb_decode_mimeheader.
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

        return mb_decode_mimeheader($header);
    }

    /**
     * Verifica si hay una conexión activa.
     */
    private function isConnected(): bool
    {
        return $this->connection !== null && $this->connection !== false;
    }
}
