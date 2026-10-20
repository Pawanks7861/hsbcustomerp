<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\KnowledgeBase;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Models\User;
use App\Services\Access;

class HelpHandler implements IntentHandler
{
    public function __construct(private KnowledgeBase $knowledge, private Access $access) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $message = (string) ($params['_normalized'] ?? '');
        $match = $this->knowledge->match($message);

        if ($match['entry'] === null) {
            return new ChatResponse(
                message: "I can help with how-to steps for projects, vendors, BOQ, imports, PRs, POs, GRNs, invoices, payments, expenses, and reports. Try asking “How do I create a purchase order?”",
                confidence: 'medium',
                suggestions: [
                    'How do I create a purchase order?',
                    'How do I import Excel?',
                    'How do I add a vendor?',
                ],
                intent: 'help.general',
            );
        }

        $entry = $match['entry'];
        $links = [];
        if (! empty($entry['route'])) {
            $allowed = empty($entry['permission'])
                || $this->access->allows($user, $entry['permission'])
                || $user->is_admin;
            if ($allowed) {
                $links[] = ['label' => 'Open related page', 'url' => $entry['route']];
            } else {
                return new ChatResponse(
                    message: 'That feature exists, but you do not currently have permission to use it. Ask your administrator if you need access.',
                    confidence: 'high',
                    intent: 'help.general',
                );
            }
        }

        return new ChatResponse(
            message: $entry['answer'],
            confidence: $match['score'] >= 0.8 ? 'high' : 'medium',
            links: $links,
            suggestions: ['How do I import Excel?', 'Show pending PRs', 'What is our total payable?'],
            contextPatch: ['last_intent' => 'help.general'],
            intent: 'help.general',
        );
    }
}
