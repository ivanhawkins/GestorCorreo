<?php

namespace App\Services;

use App\Models\ServiceWhitelist;
use App\Models\SenderRule;

class RulesEngine
{
    /**
     * Aplica reglas ANTES de llamar a la IA.
     * Devuelve null si no aplica ninguna regla (debe pasar por IA).
     * Devuelve array de clasificación si aplica una regla.
     *
     * @param array $messageData  ['from_email', 'to_addresses', 'cc_addresses', ...]
     * @param int   $userId
     * @return array|null
     */
    public function applyRules(array $messageData, int $userId): ?array
    {
        $fromEmail   = $messageData['from_email']   ?? '';
        $toAddresses = $messageData['to_addresses'] ?? [];
        $ccAddresses = $messageData['cc_addresses'] ?? [];

        // Normalizar listas de direcciones a arrays
        if (is_string($toAddresses)) {
            $toAddresses = json_decode($toAddresses, true) ?? [];
        }
        if (is_string($ccAddresses)) {
            $ccAddresses = json_decode($ccAddresses, true) ?? [];
        }

        // ----------------------------------------------------------------
        // Regla 1: Whitelist de servicios (dominio del remitente)
        // ----------------------------------------------------------------
        if ($fromEmail) {
            $domain = $this->extractDomain($fromEmail);

            if ($domain) {
                $inWhitelist = ServiceWhitelist::where('user_id', $userId)
                    ->where('is_active', true)
                    ->get()
                    ->first(function ($entry) use ($domain, $fromEmail) {
                        $pattern = $entry->domain_pattern;

                        // Patrón puede ser dominio exacto o wildcard como *.example.com
                        if (str_starts_with($pattern, '*.')) {
                            $baseDomain = substr($pattern, 2);
                            return str_ends_with($domain, $baseDomain);
                        }

                        // Coincidencia exacta de dominio o email completo
                        return $domain === $pattern || $fromEmail === $pattern;
                    });

                if ($inWhitelist) {
                    return [
                        'final_label'     => 'Servicios',
                        'final_reason'    => "Dominio '{$domain}' en whitelist de servicios.",
                        'decided_by'      => 'rule_whitelist',
                        'gpt_label'       => null,
                        'gpt_confidence'  => null,
                        'gpt_rationale'   => null,
                        'qwen_label'      => null,
                        'qwen_confidence' => null,
                        'qwen_rationale'  => null,
                    ];
                }
            }
        }

        // ----------------------------------------------------------------
        // Regla 2: Múltiples destinatarios (>2 en to+cc)
        // ----------------------------------------------------------------
        $totalRecipients = count($toAddresses) + count($ccAddresses);

        if ($totalRecipients > 2) {
            return [
                'final_label'     => 'EnCopia',
                'final_reason'    => "Mensaje con {$totalRecipients} destinatarios (to+cc), marcado como EnCopia.",
                'decided_by'      => 'rule_multiple_recipients',
                'gpt_label'       => null,
                'gpt_confidence'  => null,
                'gpt_rationale'   => null,
                'qwen_label'      => null,
                'qwen_confidence' => null,
                'qwen_rationale'  => null,
            ];
        }

        // Ninguna regla aplica
        return null;
    }

    /**
     * Extrae el dominio de una dirección de email.
     * "usuario@example.com" → "example.com"
     */
    private function extractDomain(string $email): string
    {
        $email = trim($email);

        // Manejar formato "Nombre <email>"
        if (preg_match('/<([^>]+)>/', $email, $matches)) {
            $email = $matches[1];
        }

        $parts = explode('@', $email);

        if (count($parts) < 2) {
            return '';
        }

        return strtolower(trim(end($parts)));
    }
}
