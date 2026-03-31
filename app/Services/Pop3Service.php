<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class Pop3Service
{
    private ?Client $client = null;
    private $account;
    private string $password;
    private array $lastErrors = [];

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    /**
     * Conectar al servidor POP3 usando Webklex Pure PHP implementation.
     */
    public function connect(): bool
    {
        $cm = new ClientManager();
        
        $encryption = 'ssl';
        if ((int)$this->account->imap_port === 110) {
            $encryption = 'notls';
        }

        $this->client = $cm->make([
            'host'          => $this->account->imap_host,
            'port'          => (int)$this->account->imap_port,
            'encryption'    => $encryption,
            'validate_cert' => (bool)($this->account->ssl_verify ?? true),
            'username'      => $this->account->username,
            'password'      => $this->password,
            'protocol'      => 'pop3',
            'timeout'       => (int)($this->account->connection_timeout ?? 30),
        ]);

        try {
            $this->client->connect();
            Log::info('Pop3Service (Webklex): Conexión establecida', [
                'account' => $this->account->email_address
            ]);
            return true;
        } catch (\Exception $e) {
            $this->lastErrors = [$e->getMessage()];
            Log::error('Pop3Service (Webklex): Fallo de conexión', [
                'account' => $this->account->email_address,
                'error'   => $e->getMessage()
            ]);
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->client) {
            $this->client->disconnect();
        }
    }

    /**
     * Devuelve el total de mensajes.
     */
    public function getMessageCount(): int
    {
        if (!$this->client || !$this->client->isConnected()) return 0;
        try {
            $folder = $this->client->getFolder('INBOX');
            return $folder->messages()->all()->get()->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Obtiene todos los UIDLs (identificadores únicos).
     */
    public function getAllUidls(): array
    {
        if (!$this->client || !$this->client->isConnected()) return [];
        try {
            $uidls = [];
            $folder = $this->client->getFolder('INBOX');
            $messages = $folder->messages()->all()->get();
            
            foreach ($messages as $msg) {
                // En POP3, a veces no hay UIDs reales, usamos Message-ID o hash.
                $uidls[$msg->getMsglist()] = (string)($msg->getUid() ?: $msg->getMessageId() ?: md5($msg->getSubject()));
            }
            return $uidls;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Descarga un mensaje completo.
     */
    public function fetchMessage(int $msgNum): ?array
    {
        try {
            $folder = $this->client->getFolder('INBOX');
            // Nota: aquí buscamos por índice en POP3
            $messages = $folder->messages()->all()->get();
            $message = null;
            foreach ($messages as $m) {
                if ($m->getMsglist() === $msgNum) {
                    $message = $m;
                    break;
                }
            }

            if (!$message) return null;

            $attachments = [];
            foreach ($message->getAttachments() as $at) {
                $attachments[] = [
                    'filename'   => $at->getName(),
                    'mime_type'  => $at->getMimeType() ?? 'application/octet-stream',
                    'content'    => $at->getContent(),
                    'size_bytes' => strlen($at->getContent()),
                ];
            }

            return [
                'message_id'     => (string)$message->getMessageId(),
                'subject'        => (string)$message->getSubject(),
                'from_name'      => (string)$message->getFrom()[0]->personal ?? '',
                'from_email'     => (string)$message->getFrom()[0]->mail ?? '',
                'to_addresses'   => json_encode($this->parseAddresses($message->getTo())),
                'cc_addresses'   => json_encode($this->parseAddresses($message->getCc())),
                'date'           => (string)$message->getDate(),
                'snippet'        => '', 
                'body_text'      => (string)$message->getTextBody(),
                'body_html'      => (string)$message->getHtmlBody(),
                'has_attachments' => count($attachments) > 0,
                'attachments'    => $attachments,
            ];
        } catch (\Exception $e) {
            Log::error('Pop3Service (Webklex): Error en fetchMessage', ['msgNum' => $msgNum, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function parseAddresses($addressCollection): array
    {
        $res = [];
        foreach ($addressCollection as $addr) {
            $res[] = [
                'name'  => (string)$addr->personal,
                'email' => (string)$addr->mail
            ];
        }
        return $res;
    }

    public function getLastErrors(): array
    {
        return $this->lastErrors;
    }
}
