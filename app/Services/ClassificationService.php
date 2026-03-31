<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Classification;
use App\Models\Category;
use Illuminate\Support\Facades\Log;

class ClassificationService
{
    public function __construct(
        private RulesEngine $rulesEngine,
        private AiService $aiService
    ) {}

    /**
     * Categorías por defecto si el usuario no tiene ninguna configurada.
     */
    private const DEFAULT_CATEGORIES = [
        [
            'key'            => 'Interesantes',
            'name'           => 'Interesantes',
            'ai_instruction' => 'Emails importantes y relevantes que requieren atención: respuestas personales, trabajo, negocios, conversaciones directas con personas reales.',
        ],
        [
            'key'            => 'SPAM',
            'name'           => 'SPAM',
            'ai_instruction' => 'Correos no deseados, publicidad masiva, phishing, scams, ofertas irrelevantes o mensajes claramente no solicitados.',
        ],
        [
            'key'            => 'EnCopia',
            'name'           => 'EnCopia',
            'ai_instruction' => 'Mensajes en los que el usuario está en copia (CC), listas de distribución o comunicaciones masivas a grupos grandes.',
        ],
        [
            'key'            => 'Servicios',
            'name'           => 'Servicios',
            'ai_instruction' => 'Notificaciones automáticas de servicios: confirmaciones de pedidos, alertas de sistemas, facturas, newsletters de servicios contratados, bancos, redes sociales.',
        ],
    ];

    private function mapLabelToFolder(?string $label): string
    {
        $normalized = strtolower(trim((string)$label));
        return match ($normalized) {
            'spam' => 'SPAM',
            'interesantes' => 'Interesantes',
            'servicios' => 'Servicios',
            'encopia' => 'EnCopia',
            default => 'INBOX',
        };
    }

    /**
     * Clasifica un mensaje y guarda el resultado en la BD.
     *
     * @param Message $message
     * @param mixed   $account  (Account model con user_id, auto_classify, custom_classification_prompt)
     * @return Classification|null
     */
    public function classifyMessage(Message $message, $account): ?Classification
    {
        try {
            $userId = $account->user_id;

            // 1. Obtener categorías del usuario
            $userCategories = Category::where('user_id', $userId)->get();
            $categories     = $userCategories->isNotEmpty()
                ? $userCategories->map(fn($c) => [
                    'key'            => $c->key,
                    'name'           => $c->name,
                    'ai_instruction' => $c->ai_instruction ?? '',
                ])->toArray()
                : self::DEFAULT_CATEGORIES;

            // 2. Construir messageData desde el mensaje
            $messageData = [
                'from_name'    => $message->from_name    ?? '',
                'from_email'   => $message->from_email   ?? '',
                'to_addresses' => $message->to_addresses ?? [],
                'cc_addresses' => $message->cc_addresses ?? [],
                'subject'      => $message->subject      ?? '',
                'date'         => $message->date         ? $message->date->toDateTimeString() : '',
                'body_text'    => $message->body_text    ?? '',
                'snippet'      => $message->snippet      ?? '',
            ];

            // 3. Aplicar reglas previas a la IA
            $ruleResult = $this->rulesEngine->applyRules($messageData, $userId);

            $classificationData = null;

            if ($ruleResult !== null) {
                // Regla aplicada
                $classificationData = array_merge($ruleResult, [
                    'decided_at' => now(),
                ]);
            } else {
                // 4. Llamar al servicio IA
                $customPrompt = $account->custom_classification_prompt ?? null;
                $aiResult     = $this->aiService->classifyMessage($messageData, $categories, $customPrompt);

                $classificationData = [
                    'gpt_label'       => $aiResult['gpt_label']       ?? null,
                    'gpt_confidence'  => $aiResult['gpt_confidence']  ?? null,
                    'gpt_rationale'   => $aiResult['gpt_rationale']   ?? null,
                    'qwen_label'      => $aiResult['qwen_label']      ?? null,
                    'qwen_confidence' => $aiResult['qwen_confidence'] ?? null,
                    'qwen_rationale'  => $aiResult['qwen_rationale']  ?? null,
                    'final_label'     => $aiResult['final_label']     ?? 'Servicios',
                    'final_reason'    => $aiResult['final_reason']    ?? null,
                    'decided_by'      => $aiResult['decided_by']      ?? 'rule_fallback',
                    'decided_at'      => now(),
                ];
            }

            // 5. Crear o actualizar Classification en BD
            $classification = Classification::updateOrCreate(
                ['message_id' => $message->id],
                $classificationData
            );

            // Reflejar la clasificación en la carpeta visible del mensaje.
            $message->folder = $this->mapLabelToFolder($classification->final_label);
            $message->save();

            return $classification;
        } catch (\Throwable $e) {
            Log::error('ClassificationService: Error al clasificar mensaje', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Clasifica múltiples mensajes por ID.
     *
     * @param array  $messageIds  Array de UUIDs
     * @param mixed  $account
     * @return int   Número de mensajes clasificados exitosamente
     */
    public function classifyBatch(array $messageIds, $account): int
    {
        $successCount = 0;

        foreach ($messageIds as $messageId) {
            try {
                $message = Message::where('id', $messageId)
                    ->where('account_id', $account->id)
                    ->first();

                if (!$message) {
                    Log::warning('ClassificationService: Mensaje no encontrado en batch', ['message_id' => $messageId]);
                    continue;
                }

                $result = $this->classifyMessage($message, $account);

                if ($result !== null) {
                    $successCount++;
                }
            } catch (\Throwable $e) {
                Log::error('ClassificationService: Error en batch para mensaje', [
                    'message_id' => $messageId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $successCount;
    }
}
