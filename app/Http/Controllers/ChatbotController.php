<?php

namespace App\Http\Controllers;

use App\Chatbot\ChatbotService;
use App\Chatbot\Support\ConversationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(private ChatbotService $chatbot) {}

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.ChatbotService::MAX_MESSAGE_LENGTH],
        ]);

        try {
            $answer = $this->chatbot->ask($request->user(), $data['message']);
        } catch (Throwable $e) {
            Log::warning('ERP assistant request failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => true,
                'reply' => [
                    'message' => 'I ran into a problem answering that. Please try rephrasing, or open the related ERP page from the sidebar.',
                    'confidence' => 'low',
                    'intent' => null,
                    'links' => [['label' => 'Dashboard', 'url' => '/']],
                    'suggestions' => $this->chatbot->defaultSuggestions(),
                    'items' => [],
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'reply' => $answer,
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        return response()->json([
            'suggestions' => $this->chatbot->defaultSuggestions(),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        ConversationContext::load($request->user())->clear();

        return response()->json(['ok' => true]);
    }
}
