<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ResponseException;

class ImapService
{
    private ?Client $client = null;
    private $account;
    private string $password;
    private string $currentFolderName = '';

    public function __construct($account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
    }

    /**
     * Conectar al servidor usando la librería Pure PHP de Webklex.
     */
    public function connect(int $maxRetries = 3): bool
    {
        $cm = new ClientManager();
        
        $encryption = 'ssl';
        if ((int)$this->account->imap_port === 143) {
            $encryption = 'notls';
        }

        $this->client = $cm->make([
            'host'          => $this->account->imap_host,
            'port'          => (int)$this->account->imap_port,
            'encryption'    => $encryption,
            'validate_cert' => (bool)($this->account->ssl_verify ?? true),
            'username'      => $this->account->username,
            'password'      => $this->password,
            'protocol'      => 'imap',
            'timeout'       => (int)($this->account->connection_timeout ?? 30),
        ]);

        try {
            $this->client->connect();
            Log::info('ImapService (Webklex): Conexión establecida', [
                'account' => $this->account->email_address,
                'host'    => $this->account->imap_host
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('ImapService (Webklex): Fallo de conexión', [
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
     * Selecciona una carpeta.
     */
    public function selectFolder(string $folder = 'INBOX'): bool
    {
        if (!$this->client || !$this->client->isConnected()) return false;
        $this->currentFolderName = $folder;
        return true;
    }

    /**
     * Obtiene los UIDs de mensajes nuevos.
     */
    public function getNewMessageUids(int $lastUid = 0): array
    {
        if (!$this->client || !$this->client->isConnected()) return [];

        try {
            $folder = $this->client->getFolder($this->currentFolderName ?: 'INBOX');
            
            if ($lastUid === 0) {
                $messages = $folder->messages()->all()->get();
            } else {
                // Buscar mensajes con UID superior al último sincronizado
                $messages = $folder->messages()->whereUidGreaterThan($lastUid)->get();
            }

            $uids = [];
            foreach ($messages as $msg) {
                $uids[] = (int)$msg->getUid();
            }
            sort($uids);
            return $uids;
        } catch (\Exception $e) {
            Log::error('ImapService (Webklex): Error en getNewMessageUids', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Obtiene headers de un mensaje.
     */
    public function fetchMessageHeaders(int $uid): ?array
    {
        try {
            $folder = $this->client->getFolder($this->currentFolderName ?: 'INBOX');
            $message = $folder->query()->whereUid($uid)->get()->first();

            if (!$message) return null;

            return [
                'uid'          => $uid,
                'message_id'   => (string)$message->getMessageId(),
                'subject'      => (string)$message->getSubject(),
                'from_name'    => (string)$message->getFrom()[0]->personal ?? '',
                'from_email'   => (string)$message->getFrom()[0]->mail ?? '',
                'to_addresses' => json_encode($this->parseAddresses($message->getTo())),
                'cc_addresses' => json_encode($this->parseAddresses($message->getCc())),
                'date'         => Carbon::parse($message->getDate()),
                'snippet'      => '',
            ];
        } catch (\Exception $e) {
            Log::error('ImapService (Webklex): Error en fetchHeaders', ['uid' => $uid, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Descarga el cuerpo y adjuntos.
     */
    public function fetchFullMessageBody(int $uid): ?array
    {
        try {
            $folder = $this->client->getFolder($this->currentFolderName ?: 'INBOX');
            $message = $folder->query()->whereUid($uid)->get()->first();

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
                'body_text'   => (string)$message->getTextBody(),
                'body_html'   => (string)$message->getHtmlBody(),
                'attachments' => $attachments,
            ];
        } catch (\Exception $e) {
            Log::error('ImapService (Webklex): Error en fetchBody', ['uid' => $uid, 'error' => $e->getMessage()]);
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

    private function isConnected(): bool
    {
        return $this->client && $this->client->isConnected();
    }
}
