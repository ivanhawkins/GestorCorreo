<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Classification;
use App\Models\Message;
use App\Services\ClassificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClassifyController extends Controller
{
    public function __construct(private ClassificationService $classificationService) {}

    /**
     * POST /classify/{messageId}
     * Clasifica un mensaje específico y devuelve la Classification creada/actualizada.
     */
    public function classify(Request $request, string $messageId): JsonResponse
    {
        $user = $request->user();

        // Obtener IDs de cuentas del usuario
        $accountIds = Account::where('user_id', $user->id)
            ->where('is_deleted', false)
            ->pluck('id');

        // Buscar el mensaje y verificar que pertenece al usuario
        $message = Message::whereIn('account_id', $accountIds)->find($messageId);

        if (!$message) {
            return response()->json(['error' => 'Mensaje no encontrado.'], 404);
        }

        $account = Account::find($message->account_id);

        if (!$account) {
            return response()->json(['error' => 'Cuenta asociada no encontrada.'], 404);
        }

        try {
            $classification = $this->classificationService->classifyMessage($message, $account);

            if (!$classification) {
                return response()->json(['error' => 'No se pudo clasificar el mensaje.'], 500);
            }

            return response()->json(['classification' => $classification]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error durante la clasificación: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /classifications/{messageId}
     */
    public function show(Request $request, string $messageId): JsonResponse
    {
        $user = $request->user();
        $accountIds = Account::where('user_id', $user->id)->where('is_deleted', false)->pluck('id');
        $message = Message::whereIn('account_id', $accountIds)->find($messageId);
        if (!$message) return response()->json(['error' => 'Mensaje no encontrado.'], 404);

        $classification = Classification::where('message_id', $messageId)->first();
        if (!$classification) return response()->json(['error' => 'Sin clasificación.'], 404);

        return response()->json($classification);
    }

    /**
     * PUT /messages/{messageId}/classify  — alias FastAPI
     */
    public function updateLabel(Request $request, string $messageId): JsonResponse
    {
        $user = $request->user();
        $accountIds = Account::where('user_id', $user->id)->where('is_deleted', false)->pluck('id');
        $message = Message::whereIn('account_id', $accountIds)->find($messageId);
        if (!$message) return response()->json(['error' => 'Mensaje no encontrado.'], 404);

        $label = $request->input('classification_label');
        $classification = Classification::updateOrCreate(
            ['message_id' => $messageId],
            ['final_label' => $label, 'decided_by' => 'manual', 'final_reason' => 'Etiquetado manualmente']
        );

        return response()->json(['updated' => 1, 'classification' => $classification]);
    }

    /**
     * POST /classify/pending/{accountId}
     */
    public function classifyPending(Request $request, int $accountId): JsonResponse
    {
        $user = $request->user();
        $account = Account::where('id', $accountId)->where('user_id', $user->id)->first();
        if (!$account) return response()->json(['error' => 'Cuenta no encontrada.'], 404);

        $pendingIds = Message::where('account_id', $accountId)
            ->whereDoesntHave('classification')
            ->pluck('id')->toArray();

        $classified = $this->classificationService->classifyBatch($pendingIds, $account);

        return response()->json([
            'status'          => 'success',
            'message'         => "Clasificados {$classified} mensajes.",
            'classified'      => $classified,
            'total_processed' => count($pendingIds),
        ]);
    }
}
