<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SmtpService
{
    private $account;
    private string $password;

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    // -------------------------------------------------------------------------
    // Envío de email
    // -------------------------------------------------------------------------

    /**
     * Envía un email usando el servidor SMTP configurado en la cuenta.
     *
     * @param  array{
     *     to: string|array,
     *     cc?: string|array,
     *     bcc?: string|array,
     *     subject: string,
     *     body_text?: string,
     *     body_html?: string,
     *     reply_to?: string,
     *     attachments?: array<array{path: string, name?: string, mime_type?: string}>
     * } $emailData
     * @return array{status: 'success'|'error', message: string}
     */
    public function sendEmail(array $emailData): array
    {
        try {
            $mailer   = $this->buildMailer();
            $email    = $this->buildEmail($emailData);

            $mailer->send($email);

            Log::info('SmtpService: Email enviado correctamente', [
                'account' => $this->account->email_address,
                'to'      => $emailData['to'],
                'subject' => $emailData['subject'] ?? '(sin asunto)',
            ]);

            return [
                'status'  => 'success',
                'message' => 'Email enviado correctamente.',
            ];
        } catch (TransportExceptionInterface $e) {
            Log::error('SmtpService: Error de transporte al enviar email', [
                'account' => $this->account->email_address ?? 'unknown',
                'to'      => $emailData['to'] ?? '',
                'subject' => $emailData['subject'] ?? '',
                'error'   => $e->getMessage(),
                'debug'   => $e->getDebug(),
            ]);

            return [
                'status'  => 'error',
                'message' => 'Error de transporte SMTP: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('SmtpService: Excepción al enviar email', [
                'account' => $this->account->email_address ?? 'unknown',
                'to'      => $emailData['to'] ?? '',
                'subject' => $emailData['subject'] ?? '',
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'status'  => 'error',
                'message' => 'Error al enviar el email: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verifica la conexión al servidor SMTP sin enviar ningún mensaje.
     */
    public function testConnection(): bool
    {
        try {
            $dsn       = $this->buildDsn();
            $transport = Transport::fromDsn($dsn);

            // Intentar crear el transport (implica resolución DNS y posiblemente handshake)
            // Para forzar la conexión real, intentamos enviar un "noop" verificando si el transport
            // se puede instanciar. Symfony Mailer no expone un método de test directo,
            // así que creamos el transport y verificamos que no lance excepción.
            $mailer = new Mailer($transport);

            // Crear un email vacío solo para testear (no se enviará)
            // En Symfony Mailer, la conexión real ocurre al llamar send().
            // Enviamos a una dirección que se descartará o usamos un email bounce-safe.
            // Lo más fiable es intentar el envío a la propia cuenta y capturar cualquier error de auth/conexión.
            // Sin embargo, para no enviar, usamos una verificación de la cadena DSN.
            // La librería symfony/mailer no expone ping() o similar.
            // Adoptamos el enfoque de intentar conectar vía socket raw:
            $connected = $this->checkSmtpSocket();

            if ($connected) {
                Log::info('SmtpService: Test de conexión exitoso', [
                    'account' => $this->account->email_address,
                    'host'    => $this->account->smtp_host,
                    'port'    => $this->account->smtp_port,
                ]);
            } else {
                Log::warning('SmtpService: Test de conexión fallido', [
                    'account' => $this->account->email_address ?? 'unknown',
                    'host'    => $this->account->smtp_host,
                    'port'    => $this->account->smtp_port,
                ]);
            }

            return $connected;
        } catch (\Throwable $e) {
            Log::error('SmtpService: Excepción en testConnection()', [
                'account' => $this->account->email_address ?? 'unknown',
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Métodos privados de ayuda
    // -------------------------------------------------------------------------

    /**
     * Construye el Mailer de Symfony con el transporte SMTP dinámico de la cuenta.
     */
    private function buildMailer(): Mailer
    {
        $dsn       = $this->buildDsn();
        $transport = Transport::fromDsn($dsn);
        return new Mailer($transport);
    }

    /**
     * Construye el DSN de Symfony Mailer para el SMTP de la cuenta.
     * Formato: smtp://username:password@host:port
     *          smtps://username:password@host:port  (SSL implícito, puerto 465)
     */
    private function buildDsn(): string
    {
        $host     = $this->account->smtp_host;
        $port     = (int) $this->account->smtp_port;
        $username = rawurlencode($this->account->username);
        $password = rawurlencode($this->password);
        $verify   = (bool) ($this->account->ssl_verify ?? true);
        $timeout  = (int) ($this->account->connection_timeout ?? 30);

        // Puerto 465 = SMTP over SSL (SMTPS), 587 = STARTTLS, 25 = plain
        if ($port === 465) {
            $scheme = 'smtps';
        } else {
            $scheme = 'smtp';
        }

        $dsn = "{$scheme}://{$username}:{$password}@{$host}:{$port}";

        // Opciones adicionales
        $options = [
            'timeout' => $timeout,
        ];

        if (!$verify) {
            $options['verify_peer'] = 0;
        }

        if (!empty($options)) {
            $queryParams = http_build_query($options);
            $dsn        .= '?' . $queryParams;
        }

        return $dsn;
    }

    /**
     * Construye el objeto Email de Symfony Mime con los datos proporcionados.
     *
     * @param array $emailData
     */
    private function buildEmail(array $emailData): Email
    {
        $email = new Email();

        // From: usar la dirección del email de la cuenta
        $fromName  = $emailData['from_name'] ?? '';
        $fromEmail = $this->account->email_address;

        if ($fromName !== '') {
            $email->from(new Address($fromEmail, $fromName));
        } else {
            $email->from(new Address($fromEmail));
        }

        // To
        $toAddresses = $this->parseAddressesForSymfony($emailData['to'] ?? []);
        if (empty($toAddresses)) {
            throw new \InvalidArgumentException('Se requiere al menos un destinatario en "to".');
        }
        $email->to(...$toAddresses);

        // Cc
        if (!empty($emailData['cc'])) {
            $ccAddresses = $this->parseAddressesForSymfony($emailData['cc']);
            if (!empty($ccAddresses)) {
                $email->cc(...$ccAddresses);
            }
        }

        // Bcc
        if (!empty($emailData['bcc'])) {
            $bccAddresses = $this->parseAddressesForSymfony($emailData['bcc']);
            if (!empty($bccAddresses)) {
                $email->bcc(...$bccAddresses);
            }
        }

        // Reply-To
        if (!empty($emailData['reply_to'])) {
            $replyToAddresses = $this->parseAddressesForSymfony($emailData['reply_to']);
            if (!empty($replyToAddresses)) {
                $email->replyTo(...$replyToAddresses);
            }
        }

        // Subject
        $email->subject($emailData['subject'] ?? '(sin asunto)');

        // Body
        if (!empty($emailData['body_html'])) {
            $email->html($emailData['body_html']);
        }

        if (!empty($emailData['body_text'])) {
            $email->text($emailData['body_text']);
        }

        if (empty($emailData['body_html']) && empty($emailData['body_text'])) {
            $email->text('');
        }

        // Attachments
        if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachment) {
                try {
                    $this->addAttachmentToEmail($email, $attachment);
                } catch (\Throwable $e) {
                    Log::warning('SmtpService: Error añadiendo adjunto al email', [
                        'account'  => $this->account->email_address ?? 'unknown',
                        'filename' => $attachment['name'] ?? $attachment['path'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        return $email;
    }

    /**
     * Añade un adjunto al objeto Email de Symfony.
     *
     * @param array{path?: string, content?: string, name?: string, mime_type?: string} $attachment
     */
    private function addAttachmentToEmail(Email $email, array $attachment): void
    {
        $mimeType = $attachment['mime_type'] ?? 'application/octet-stream';
        $name     = $attachment['name'] ?? null;

        if (!empty($attachment['path']) && file_exists($attachment['path'])) {
            // Adjunto desde ruta de archivo
            $email->attachFromPath($attachment['path'], $name, $mimeType);
        } elseif (!empty($attachment['content'])) {
            // Adjunto desde contenido binario en memoria
            $email->attach($attachment['content'], $name, $mimeType);
        }
    }

    /**
     * Convierte una lista de direcciones (string o array) a objetos Address de Symfony.
     *
     * @param  string|array $addresses
     * @return Address[]
     */
    private function parseAddressesForSymfony($addresses): array
    {
        if (is_string($addresses)) {
            // Puede ser una sola dirección o una lista JSON
            if (str_starts_with(trim($addresses), '[')) {
                $decoded = json_decode($addresses, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $addresses = $decoded;
                } else {
                    // Tratar como dirección simple o lista separada por comas
                    $addresses = array_map('trim', explode(',', $addresses));
                }
            } else {
                $addresses = array_map('trim', explode(',', $addresses));
            }
        }

        $result = [];
        foreach ((array) $addresses as $address) {
            if (is_array($address)) {
                // Formato ['name' => '...', 'email' => '...']
                $email = $address['email'] ?? '';
                $name  = $address['name'] ?? '';

                if ($email === '') {
                    continue;
                }

                $result[] = $name !== ''
                    ? new Address($email, $name)
                    : new Address($email);
            } elseif (is_string($address) && $address !== '') {
                // Formato "Nombre <email@example.com>" o "email@example.com"
                $address = trim($address);
                if (preg_match('/^(.*?)\s*<\s*([^>]+)\s*>$/', $address, $matches)) {
                    $name  = trim($matches[1], '" \'');
                    $email = trim($matches[2]);
                    $result[] = $name !== ''
                        ? new Address($email, $name)
                        : new Address($email);
                } elseif (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $result[] = new Address($address);
                }
            }
        }

        return $result;
    }

    /**
     * Verifica la conexión SMTP abriendo un socket TCP al host:port de la cuenta.
     * Lee el banner de bienvenida (220) para confirmar que el servidor responde.
     */
    private function checkSmtpSocket(): bool
    {
        $host    = $this->account->smtp_host;
        $port    = (int) $this->account->smtp_port;
        $timeout = (int) ($this->account->connection_timeout ?? 10);
        $verify  = (bool) ($this->account->ssl_verify ?? true);

        $errno  = 0;
        $errstr = '';

        // Para SSL implícito (puerto 465), usar stream_context con ssl://
        if ($port === 465) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer'      => $verify,
                    'verify_peer_name' => $verify,
                ],
            ]);

            $socket = @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        }

        if ($socket === false) {
            Log::warning('SmtpService: No se pudo conectar al socket SMTP', [
                'host'   => $host,
                'port'   => $port,
                'errno'  => $errno,
                'errstr' => $errstr,
            ]);
            return false;
        }

        // Leer el banner de bienvenida (el servidor SMTP debe responder con 220)
        $banner = fgets($socket, 512);
        fclose($socket);

        if ($banner === false) {
            return false;
        }

        // El servidor SMTP responde con "220 ..." si está listo
        return str_starts_with(trim($banner), '220');
    }
}
