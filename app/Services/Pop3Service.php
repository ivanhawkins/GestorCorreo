<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class Pop3Service
{
    private $socket = null;
    private $account;
    private string $password;
    private array $lastErrors = [];

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    public function connect(): bool
    {
        $host = (string)$this->account->imap_host;
        $port = (int)$this->account->imap_port;
        $timeout = 30;
        $isSsl = in_array($port, [965, 995], true);
        $transport = $isSsl ? 'tls' : 'tcp';
        $target = sprintf('%s://%s:%d', $transport, $host, $port);

        try {
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);

            $errno = 0;
            $errstr = '';
            $this->socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if (!is_resource($this->socket)) {
                throw new \RuntimeException("No se pudo abrir socket POP3: ({$errno}) {$errstr}");
            }

            stream_set_timeout($this->socket, $timeout);

            $greeting = $this->readLine();
            if (!$this->isOk($greeting)) {
                throw new \RuntimeException('Saludo POP3 inválido: ' . $greeting);
            }

            $this->assertOk($this->sendCommand('USER ' . $this->account->username), 'USER');
            $this->assertOk($this->sendCommand('PASS ' . $this->password), 'PASS');

            Log::info('Pop3Service (socket): Conexión establecida', ['account' => $this->account->email_address]);
            return true;
        } catch (\Throwable $e) {
            $this->lastErrors = [$e->getMessage()];
            Log::error('Pop3Service (socket): Fallo de conexión', [
                'account' => $this->account->email_address,
                'error'   => $e->getMessage()
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function getMessageCount(): int
    {
        try {
            $line = $this->sendCommand('STAT');
            if (!$this->isOk($line)) return 0;
            if (preg_match('/^\+OK\s+(\d+)/i', $line, $m)) {
                return (int)$m[1];
            }
            return 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getAllUidls(): array
    {
        try {
            $head = $this->sendCommand('UIDL');
            if (!$this->isOk($head)) return [];
            $lines = $this->readMultiline();
            $uidls = [];
            foreach ($lines as $line) {
                if (preg_match('/^(\d+)\s+(.+)$/', trim($line), $m)) {
                    $uidls[(int)$m[1]] = trim($m[2]);
                }
            }
            return $uidls;
        } catch (\Throwable) {
            return [];
        }
    }

    public function fetchMessage(int $msgNum): ?array
    {
        try {
            $head = $this->sendCommand("RETR {$msgNum}");
            if (!$this->isOk($head)) {
                return null;
            }

            $raw = implode("\r\n", $this->readMultiline());
            $parsed = $this->parseRawMessage($raw);

            return [
                'message_id'      => $parsed['message_id'],
                'subject'         => $parsed['subject'],
                'from_name'       => $parsed['from_name'],
                'from_email'      => $parsed['from_email'],
                'to_addresses'    => json_encode($parsed['to_addresses']),
                'cc_addresses'    => json_encode($parsed['cc_addresses']),
                'date'            => $parsed['date'],
                'snippet'         => '',
                'body_text'       => $parsed['body_text'],
                'body_html'       => $parsed['body_html'],
                'has_attachments' => !empty($parsed['attachments']),
                'attachments'     => $parsed['attachments'],
            ];
        } catch (\Throwable $e) {
            Log::error('Pop3Service (socket): Error en fetchMessage', ['msgNum' => $msgNum, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function sendCommand(string $command): string
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('Socket POP3 no inicializado');
        }
        fwrite($this->socket, $command . "\r\n");
        return $this->readLine();
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('Socket POP3 no disponible');
        }

        $line = fgets($this->socket, 8192);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Timeout leyendo del servidor POP3');
            }
            throw new \RuntimeException('Error leyendo del servidor POP3');
        }
        return rtrim($line, "\r\n");
    }

    private function readMultiline(): array
    {
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if ($line === '.') {
                break;
            }
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $lines[] = $line;
        }
        return $lines;
    }

    private function isOk(string $line): bool
    {
        return str_starts_with($line, '+OK');
    }

    private function assertOk(string $line, string $command): void
    {
        if (!$this->isOk($line)) {
            throw new \RuntimeException("POP3 {$command} falló: {$line}");
        }
    }

    private function parseRawMessage(string $raw): array
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
        $rawHeaders = $parts[0] ?? '';
        $rawBody = $parts[1] ?? '';

        $headers = $this->parseHeaders($rawHeaders);
        $contentType = strtolower($headers['content-type'] ?? 'text/plain');
        $mainBoundary = $this->extractBoundary($headers['content-type'] ?? '');

        $bodyText = '';
        $bodyHtml = '';
        $attachments = [];

        if (str_contains($contentType, 'multipart/')) {
            $boundary = $this->extractBoundary($headers['content-type'] ?? '');
            if ($boundary !== null) {
                [$bodyText, $bodyHtml, $attachments] = $this->parseMultipartBody($rawBody, $boundary);
            }
        } else {
            $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
            $decodedBody = $this->decodeTransfer($rawBody, $encoding);
            if (str_contains($contentType, 'text/html')) {
                $bodyHtml = $decodedBody;
            } else {
                $bodyText = $decodedBody;
            }
        }

        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim(strip_tags($bodyHtml));
        }

        // Algunos servidores/formatos dejan el boundary MIME en el cuerpo final.
        $bodyText = $this->stripMimeBoundaryArtifacts($bodyText, $mainBoundary);
        $bodyHtml = $this->stripMimeBoundaryArtifacts($bodyHtml, $mainBoundary);

        return [
            'message_id'  => trim((string)($headers['message-id'] ?? '')),
            'subject'     => $this->decodeHeader((string)($headers['subject'] ?? '')),
            'from_name'   => $this->extractName((string)($headers['from'] ?? '')),
            'from_email'  => $this->extractEmail((string)($headers['from'] ?? '')),
            'to_addresses'=> $this->parseAddressList((string)($headers['to'] ?? '')),
            'cc_addresses'=> $this->parseAddressList((string)($headers['cc'] ?? '')),
            'date'        => (string)($headers['date'] ?? now()->toRfc2822String()),
            'body_text'   => $bodyText,
            'body_html'   => $bodyHtml,
            'attachments' => $attachments,
        ];
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $rawHeaders = preg_replace("/\r\n[ \t]+/", ' ', $rawHeaders);
        $lines = preg_split("/\r\n|\n|\r/", (string)$rawHeaders) ?: [];
        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseMultipartBody(string $rawBody, string $boundary): array
    {
        $text = '';
        $html = '';
        $attachments = [];
        $segments = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', $rawBody) ?: [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '--') {
                continue;
            }

            $parts = preg_split("/\r\n\r\n|\n\n/", $segment, 2);
            $h = $this->parseHeaders($parts[0] ?? '');
            $b = $parts[1] ?? '';
            $ctype = strtolower($h['content-type'] ?? 'text/plain');
            $encoding = strtolower($h['content-transfer-encoding'] ?? '');
            $decoded = $this->decodeTransfer($b, $encoding);
            $disp = strtolower($h['content-disposition'] ?? '');
            $filename = $this->extractFilename($h['content-disposition'] ?? '', $h['content-type'] ?? '');

            if (str_contains($ctype, 'multipart/')) {
                $childBoundary = $this->extractBoundary($h['content-type'] ?? '');
                if ($childBoundary) {
                    [$childText, $childHtml, $childAttachments] = $this->parseMultipartBody($decoded, $childBoundary);
                    if ($text === '' && $childText !== '') $text = $childText;
                    if ($html === '' && $childHtml !== '') $html = $childHtml;
                    if (!empty($childAttachments)) $attachments = array_merge($attachments, $childAttachments);
                }
                continue;
            }

            if (str_contains($disp, 'attachment') || ($filename !== '' && !str_contains($ctype, 'text/'))) {
                $attachments[] = [
                    'filename'   => $filename ?: ('attachment_' . uniqid('', true)),
                    'mime_type'  => explode(';', $h['content-type'] ?? 'application/octet-stream')[0],
                    'content'    => $decoded,
                    'size_bytes' => strlen($decoded),
                ];
                continue;
            }

            if (str_contains($ctype, 'text/plain') && $text === '') {
                $text = $decoded;
                continue;
            }

            if (str_contains($ctype, 'text/html') && $html === '') {
                $html = $decoded;
            }
        }

        return [$text, $html, $attachments];
    }

    private function extractFilename(string $disposition, string $contentType): string
    {
        if (preg_match('/filename\*?="?([^";]+)"?/i', $disposition, $m)) {
            return $this->decodeHeader($m[1]);
        }
        if (preg_match('/name="?([^";]+)"?/i', $contentType, $m)) {
            return $this->decodeHeader($m[1]);
        }
        return '';
    }

    private function decodeTransfer(string $body, string $encoding): string
    {
        if ($encoding === 'base64') {
            // En adjuntos POP3 el base64 suele venir con saltos/espacios.
            $normalized = preg_replace('/\s+/', '', $body) ?? $body;
            $decoded = base64_decode($normalized, true);
            if ($decoded === false) {
                $decoded = base64_decode($normalized, false);
            }
            return $decoded === false ? '' : $decoded;
        }

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }

        return rtrim($body, "\r\n");
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') return '';
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) return $decoded;
        }
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }
        return $value;
    }

    private function stripMimeBoundaryArtifacts(string $content, ?string $boundary): string
    {
        if ($content === '') {
            return $content;
        }

        $clean = $content;

        if (!empty($boundary)) {
            $quoted = preg_quote($boundary, '/');
            $clean = preg_replace('/^\s*--' . $quoted . '(?:--)?\s*$/m', '', $clean) ?? $clean;
        }

        // Limpieza defensiva para delimitadores residuales genéricos.
        $clean = preg_replace('/^\s*--[A-Za-z0-9_=\-]{12,}(?:--)?\s*$/m', '', $clean) ?? $clean;
        $clean = preg_replace("/(\r?\n){3,}/", "\n\n", $clean) ?? $clean;

        return trim($clean);
    }

    private function parseAddressList(string $raw): array
    {
        $result = [];
        if ($raw === '') return $result;

        $items = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw) ?: [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $result[] = [
                'name' => $this->extractName($item),
                'email' => $this->extractEmail($item),
            ];
        }
        return $result;
    }

    private function extractEmail(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m)) {
            return trim($m[0]);
        }
        return trim($raw);
    }

    private function extractName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('/^"?([^"<]+)"?\s*<[^>]+>$/', $raw, $m)) {
            return trim($this->decodeHeader($m[1]));
        }
        return '';
    }

    private function parseAddresses($addressCollection): array
    {
        if (!is_array($addressCollection)) {
            return [];
        }
        $res = [];
        foreach ($addressCollection as $addr) {
            $res[] = [
                'name'  => (string)($addr['name'] ?? ''),
                'email' => (string)($addr['email'] ?? '')
            ];
        }
        return $res;
    }

    public function getLastErrors(): array
    {
        return $this->lastErrors;
    }
}
