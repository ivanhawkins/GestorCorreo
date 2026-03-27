<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MimeParser
{
    /**
     * Parsea un email crudo (string) y extrae todas sus partes.
     *
     * @return array{subject: string, from: array, to: array, cc: array, date: string, body_text: string, body_html: string, attachments: array}
     */
    public function parseRawMessage(string $rawEmail): array
    {
        $result = [
            'subject'     => '',
            'from'        => [],
            'to'          => [],
            'cc'          => [],
            'date'        => '',
            'body_text'   => '',
            'body_html'   => '',
            'attachments' => [],
        ];

        // Separar headers del body
        $parts = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        if (count($parts) < 2) {
            $result['body_text'] = $rawEmail;
            return $result;
        }

        [$headerSection, $bodySection] = $parts;

        // Parsear headers
        $headers = $this->parseHeaders($headerSection);

        $result['subject'] = $this->decodeHeader($headers['subject'] ?? '');
        $result['date']    = $headers['date'] ?? '';

        if (isset($headers['from'])) {
            $result['from'] = $this->parseEmailAddress($this->decodeHeader($headers['from']));
        }

        if (isset($headers['to'])) {
            $result['to'] = $this->parseAddressList($this->decodeHeader($headers['to']));
        }

        if (isset($headers['cc'])) {
            $result['cc'] = $this->parseAddressList($this->decodeHeader($headers['cc']));
        }

        // Determinar Content-Type y boundary
        $contentType = $headers['content-type'] ?? 'text/plain';
        $charset     = $this->extractParameter($contentType, 'charset', 'UTF-8');
        $boundary    = $this->extractParameter($contentType, 'boundary');

        if (stripos($contentType, 'multipart/') !== false && $boundary !== '') {
            // Parsear partes MIME multipart
            $this->parseMultipart($bodySection, $boundary, $result);
        } else {
            // Mensaje simple (no multipart)
            $encoding = strtolower($headers['content-transfer-encoding'] ?? '7bit');
            $decoded  = $this->decodeBodyPart($bodySection, $encoding);
            $decoded  = $this->convertCharset($decoded, $charset);

            if (stripos($contentType, 'text/html') !== false) {
                $result['body_html'] = $decoded;
                $result['body_text'] = strip_tags($decoded);
            } else {
                $result['body_text'] = $decoded;
            }
        }

        return $result;
    }

    /**
     * Parsea los headers de un bloque de texto de cabeceras HTTP/MIME.
     * Maneja folded headers (continuaciones con espacio o tab).
     */
    private function parseHeaders(string $headerSection): array
    {
        // Unfold (RFC 2822: líneas que empiezan con SP o HT son continuación)
        $unfolded = preg_replace('/\r?\n([ \t])/', '$1', $headerSection);
        $headers  = [];

        foreach (explode("\n", $unfolded) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key            = strtolower(trim($name));
            $headers[$key]  = trim($value);
        }

        return $headers;
    }

    /**
     * Parsea un bloque multipart recursivamente.
     */
    private function parseMultipart(string $body, string $boundary, array &$result): void
    {
        // El boundary está precedido por "--"
        $delimiter = '--' . $boundary;
        $parts     = explode($delimiter, $body);

        // La primera parte antes del primer boundary y el "--" final se descartan
        foreach ($parts as $part) {
            $trimmed = ltrim($part, "\r\n");
            if ($trimmed === '' || $trimmed === '--' || $trimmed === "--\r\n" || $trimmed === "--\n") {
                continue;
            }

            // Separar headers del cuerpo de la parte
            $subParts = preg_split('/\r?\n\r?\n/', $trimmed, 2);
            if (count($subParts) < 2) {
                continue;
            }

            [$partHeaders, $partBody] = $subParts;
            $parsedPartHeaders        = $this->parseHeaders($partHeaders);

            $partContentType     = $parsedPartHeaders['content-type'] ?? 'text/plain';
            $partCharset         = $this->extractParameter($partContentType, 'charset', 'UTF-8');
            $partEncoding        = strtolower($parsedPartHeaders['content-transfer-encoding'] ?? '7bit');
            $partDisposition     = $parsedPartHeaders['content-disposition'] ?? '';
            $partBoundary        = $this->extractParameter($partContentType, 'boundary');

            // Si es otra parte multipart, recursión
            if (stripos($partContentType, 'multipart/') !== false && $partBoundary !== '') {
                $this->parseMultipart($partBody, $partBoundary, $result);
                continue;
            }

            // Determinar si es adjunto
            $isAttachment = stripos($partDisposition, 'attachment') !== false;
            $filename     = $this->extractParameter($partDisposition, 'filename');
            if ($filename === '') {
                $filename = $this->extractParameter($partContentType, 'name');
            }
            $filename = $this->decodeHeader($filename);

            if ($isAttachment || ($filename !== '' && stripos($partContentType, 'text/') === false)) {
                // Es un adjunto
                $decoded = $this->decodeBodyPart(trim($partBody), $partEncoding);
                $result['attachments'][] = [
                    'filename'  => $filename !== '' ? $filename : 'attachment_' . uniqid(),
                    'mime_type' => strtolower(strtok($partContentType, ';')),
                    'content'   => $decoded,
                    'size_bytes' => strlen($decoded),
                ];
                continue;
            }

            // Es parte de texto
            $decoded = $this->decodeBodyPart(trim($partBody), $partEncoding);
            $decoded = $this->convertCharset($decoded, $partCharset);

            if (stripos($partContentType, 'text/html') !== false) {
                $result['body_html'] .= $decoded;
            } elseif (stripos($partContentType, 'text/plain') !== false) {
                $result['body_text'] .= $decoded;
            }
        }
    }

    /**
     * Decodifica el cuerpo de una parte MIME según su encoding.
     */
    private function decodeBodyPart(string $body, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                return base64_decode(str_replace(["\r", "\n"], '', $body));

            case 'quoted-printable':
                return quoted_printable_decode($body);

            case '7bit':
            case '8bit':
            case 'binary':
            default:
                return $body;
        }
    }

    /**
     * Convierte el charset de un string a UTF-8.
     */
    private function convertCharset(string $text, string $fromCharset): string
    {
        $fromCharset = strtoupper(trim($fromCharset));
        if ($fromCharset === '' || $fromCharset === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $fromCharset);
        return $converted !== false ? $converted : $text;
    }

    /**
     * Extrae el valor de un parámetro de una cabecera Content-Type o Content-Disposition.
     * Ej: "text/plain; charset=UTF-8" con param="charset" devuelve "UTF-8".
     */
    private function extractParameter(string $headerValue, string $param, string $default = ''): string
    {
        if (preg_match('/' . preg_quote($param, '/') . '\s*=\s*"?([^";,\s]+)"?/i', $headerValue, $matches)) {
            return trim($matches[1], '"\'');
        }
        return $default;
    }

    /**
     * Decodifica cabeceras MIME codificadas (=?UTF-8?B?...?= o =?ISO-8859-1?Q?...?=).
     */
    public function decodeHeader(string $header): string
    {
        if ($header === '') {
            return '';
        }

        // Usar imap_mime_header_decode si está disponible
        if (function_exists('imap_mime_header_decode')) {
            $elements = imap_mime_header_decode($header);
            if ($elements === false) {
                return $header;
            }

            $decoded = '';
            foreach ($elements as $element) {
                $charset = strtoupper($element->charset);
                $text    = $element->text;

                if ($charset === 'DEFAULT' || $charset === 'UTF-8') {
                    $decoded .= $text;
                } else {
                    $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                    $decoded  .= ($converted !== false) ? $converted : $text;
                }
            }

            return $decoded;
        }

        // Fallback: mb_decode_mimeheader
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($header);
        }

        // Fallback manual
        return preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            function (array $matches): string {
                $charset  = $matches[1];
                $encoding = strtoupper($matches[2]);
                $text     = $matches[3];

                if ($encoding === 'B') {
                    $decoded = base64_decode($text);
                } elseif ($encoding === 'Q') {
                    $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
                } else {
                    return $matches[0];
                }

                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                return ($converted !== false) ? $converted : $decoded;
            },
            $header
        );
    }

    /**
     * Parsea una dirección de email en formato "Nombre <email@example.com>" o "email@example.com".
     *
     * @return array{name: string, email: string}
     */
    public function parseEmailAddress(string $address): array
    {
        $address = trim($address);

        if (preg_match('/^(.*?)\s*<\s*([^>]+)\s*>$/', $address, $matches)) {
            return [
                'name'  => trim($matches[1], '" \''),
                'email' => strtolower(trim($matches[2])),
            ];
        }

        // Sin nombre, solo dirección
        if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return [
                'name'  => '',
                'email' => strtolower($address),
            ];
        }

        // Formato "email (nombre)"
        if (preg_match('/^([^\s]+@[^\s]+)\s*\((.+)\)$/', $address, $matches)) {
            return [
                'name'  => trim($matches[2]),
                'email' => strtolower(trim($matches[1])),
            ];
        }

        return [
            'name'  => '',
            'email' => strtolower($address),
        ];
    }

    /**
     * Parsea una lista de direcciones de email separadas por coma.
     *
     * @return array<array{name: string, email: string}>
     */
    public function parseAddressList(string $addresses): array
    {
        if (trim($addresses) === '') {
            return [];
        }

        $result = [];

        // Dividir por coma, pero respetando los < > que pueden contener comas
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<>]*>)/', $addresses);

        if ($parts === false) {
            $parts = explode(',', $addresses);
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $parsed = $this->parseEmailAddress($part);
            if ($parsed['email'] !== '') {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    /**
     * Guarda un adjunto en storage/app/public/attachments/{messageId}/filename.
     * Devuelve la ruta relativa guardada.
     */
    public function saveAttachment(array $attachmentData, string $messageId): string
    {
        try {
            $filename       = $attachmentData['filename'] ?? ('attachment_' . uniqid());
            $content        = $attachmentData['content'] ?? '';
            $safeMessageId  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $messageId);
            $safeFilename   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($filename));
            $uniqueFilename = uniqid('', true) . '_' . $safeFilename;
            $relativePath   = 'attachments/' . $safeMessageId . '/' . $uniqueFilename;

            Storage::disk('public')->put($relativePath, $content);

            return $relativePath;
        } catch (\Throwable $e) {
            Log::error('MimeParser: Error guardando adjunto', [
                'message_id' => $messageId,
                'filename'   => $attachmentData['filename'] ?? 'unknown',
                'error'      => $e->getMessage(),
            ]);
            return '';
        }
    }
}
