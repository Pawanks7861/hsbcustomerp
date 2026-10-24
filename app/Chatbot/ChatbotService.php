<?php

namespace App\Chatbot;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class ChatbotService
{
    public const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private IntentRegistry $registry,
        private IntentMatcher $matcher,
        private EntityResolver $entities,
        private Access $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ask(User $user, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return (new ChatResponse(
                message: 'Type a question about your ERP — for example “What is our total payable?”',
                confidence: 'low',
                suggestions: $this->defaultSuggestions(),
            ))->toArray();
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return (new ChatResponse(
                message: 'Please keep questions under '.self::MAX_MESSAGE_LENGTH.' characters.',
                confidence: 'low',
            ))->toArray();
        }

        $rateKey = 'erp-assistant:'.$user->id;
        $assistantLimit = (int) config('security.throttle.assistant_service', 30);
        if (RateLimiter::tooManyAttempts($rateKey, $assistantLimit)) {
            return (new ChatResponse(
                message: 'You are sending questions too quickly. Please wait a moment and try again.',
                confidence: 'low',
            ))->toArray();
        }
        RateLimiter::hit($rateKey, 60);

        if ($this->looksMalicious($message)) {
            return (new ChatResponse(
                message: 'I can only answer read-only ERP questions. I cannot run commands or bypass security rules.',
                confidence: 'high',
            ))->toArray();
        }

        if ($this->looksLikeMutation($message)) {
            return (new ChatResponse(
                message: 'I am read-only. I can explain how to perform actions and link you to the right page, but I cannot create, approve, post, pay, or delete records from chat.',
                confidence: 'high',
                suggestions: [
                    'How do I create a purchase order?',
                    'How do I record a vendor payment?',
                ],
            ))->toArray();
        }

        $context = ConversationContext::load($user);
        $normalized = $this->entities->normalize($message);

        try {
            $params = $this->entities->extract($message, $user);
        } catch (Throwable $e) {
            Log::warning('ERP assistant entity extraction failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $params = [];
        }

        $params['_raw'] = $message;
        $params['_normalized'] = $normalized;

        // Carry forward context entities when the message does not name a new one.
        if (empty($params['project_id']) && $context->projectId()) {
            $params['project_id'] = $context->projectId();
        }
        if (empty($params['vendor_id']) && $context->vendorId()) {
            $params['vendor_id'] = $context->vendorId();
        }

        $match = $this->matcher->match($normalized, $context);

        if ($match['intent'] === null || $match['score'] < 0.45) {
            $response = new ChatResponse(
                message: "I can't answer that reliably yet. I can currently help with projects, BOQ, procurement, inventory, vendors, invoices, expenses, work orders, imports help, and available reports.",
                confidence: 'low',
                suggestions: $this->defaultSuggestions(),
            );

            return $response->toArray();
        }

        if ($match['score'] < 0.62 && $match['alternatives'] !== []) {
            $options = collect($match['alternatives'])
                ->prepend(['id' => $match['intent']->id, 'name' => $match['intent']->name])
                ->unique('id')
                ->take(3)
                ->map(fn ($row) => '• '.$row['name'])
                ->implode("\n");

            return (new ChatResponse(
                message: "I'm not fully sure what you mean. Did you want one of these?\n{$options}",
                confidence: 'medium',
                suggestions: collect($match['alternatives'])->pluck('name')->take(3)->all(),
            ))->toArray();
        }

        $intent = $match['intent'];
        if (! $this->authorized($user, $intent->permissions)) {
            return (new ChatResponse(
                message: 'You do not have permission to view that information.',
                confidence: 'high',
                intent: $intent->id,
            ))->toArray();
        }

        $params['_intent'] = $intent->id;

        try {
            /** @var IntentHandler $handler */
            $handler = app($intent->handler);
            $response = $handler->handle($user, $params, $context);
        } catch (Throwable $e) {
            Log::warning('ERP assistant handler failed', [
                'intent' => $intent->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return (new ChatResponse(
                message: 'I ran into a problem retrieving that information. Please try again or open the related ERP page directly.',
                confidence: 'low',
                links: [['label' => 'Dashboard', 'url' => '/']],
            ))->toArray();
        }

        $patch = array_merge(['last_intent' => $intent->id], $response->contextPatch);
        $context->merge($patch);
        $context->save();

        $payload = $response->toArray();
        if ($payload['suggestions'] === []) {
            $payload['suggestions'] = $intent->suggestions;
        }
        $payload['confidence'] = $match['score'] >= 0.8 ? 'high' : ($match['score'] >= 0.62 ? 'medium' : $payload['confidence']);

        return $payload;
    }

    /**
     * @return list<string>
     */
    public function defaultSuggestions(): array
    {
        return [
            'What is our total payable?',
            'Show pending purchase requisitions',
            'How do I create a purchase order?',
            'Show my projects',
            'Show outstanding invoices',
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorized(User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        if ($user->is_admin) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->access->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function looksMalicious(string $message): bool
    {
        $patterns = [
            '/\b(drop\s+table|union\s+select|information_schema)\b/i',
            '/(--|;--|\/\*|\*\/)/',
            '/\b(ignore (all )?(permissions|rules)|bypass (security|auth))\b/i',
            '/<\s*script\b/i',
            '/\b(shell_exec|system\(|passthru|eval\()\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeMutation(string $message): bool
    {
        return (bool) preg_match(
            '/\b(delete|approve|reject|post|pay now|create invoice now|finalize|import now|transfer stock now)\b/i',
            $message
        ) && ! preg_match('/\b(how (do|can|to)|where can)\b/i', $message);
    }
}
