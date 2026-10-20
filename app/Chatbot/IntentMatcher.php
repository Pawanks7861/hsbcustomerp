<?php

namespace App\Chatbot;

use App\Chatbot\Support\ConversationContext;
use Illuminate\Support\Str;

class IntentMatcher
{
    public function __construct(private IntentRegistry $registry) {}

    /**
     * @return array{intent: ?IntentDefinition, score: float, alternatives: list<array{id: string, name: string, score: float}>}
     */
    public function match(string $normalizedMessage, ConversationContext $context): array
    {
        $followUp = $this->detectFollowUp($normalizedMessage, $context);
        if ($followUp !== null) {
            return [
                'intent' => $this->registry->get($followUp),
                'score' => 0.92,
                'alternatives' => [],
            ];
        }

        $scores = [];

        foreach ($this->registry->all() as $intent) {
            $score = $this->score($normalizedMessage, $intent);
            if ($score > 0) {
                $scores[] = ['intent' => $intent, 'score' => $score];
            }
        }

        usort($scores, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['intent']->priority <=> $b['intent']->priority;
            }

            return $b['score'] <=> $a['score'];
        });

        if ($scores === []) {
            return ['intent' => null, 'score' => 0.0, 'alternatives' => []];
        }

        $best = $scores[0];
        $alternatives = [];

        foreach (array_slice($scores, 1, 3) as $entry) {
            if ($entry['score'] >= 0.45 && abs($entry['score'] - $best['score']) <= 0.12) {
                $alternatives[] = [
                    'id' => $entry['intent']->id,
                    'name' => $entry['intent']->name,
                    'score' => round($entry['score'], 3),
                ];
            }
        }

        return [
            'intent' => $best['intent'],
            'score' => $best['score'],
            'alternatives' => $alternatives,
        ];
    }

    private function score(string $message, IntentDefinition $intent): float
    {
        $score = 0.0;
        $isHelp = str_starts_with($intent->id, 'help.');

        // Prefer help intents for how-to / create / guide wording.
        if ($isHelp && preg_match('/\b(how (do|can|to|should)|where (can|do|to)|steps to|help me|guide me)\b/', $message)) {
            $score = max($score, 0.9);
        }
        if ($isHelp && preg_match('/\b(create|add|make|new|record)\b.+\b(pr|po|grn|boq|wo|invoice|vendor|project|payment|expense|inventory|material|materials|stock|item|items)\b/', $message)) {
            $score = max($score, 0.93);
        }
        if ($isHelp && preg_match('/\b(pr|po|grn|boq|wo|invoice|vendor|project|payment|inventory|material|materials|stock|item|items)\b.+\b(create|add|make|new)\b/', $message)) {
            $score = max($score, 0.93);
        }
        if ($isHelp && preg_match('/\b(inventory|material|materials|stock)\b/', $message) && preg_match('/\b(how|add|create|view|check|see)\b/', $message)) {
            $score = max($score, 0.94);
        }

        foreach ($intent->phrases as $phrase) {
            $phrase = Str::lower($phrase);
            if ($message === $phrase) {
                return 1.0;
            }
            if (str_contains($message, $phrase)) {
                $score = max($score, 0.88 + min(0.1, strlen($phrase) / 200));
            }
            similar_text($message, $phrase, $percent);
            if ($percent >= 78) {
                $score = max($score, $percent / 100 * 0.9);
            }
        }

        $keywordHits = 0;
        $keywordPool = array_unique(array_merge($intent->keywords, $intent->synonyms));
        foreach ($keywordPool as $keyword) {
            $keyword = Str::lower($keyword);
            if ($keyword === '') {
                continue;
            }
            if (strlen($keyword) <= 3) {
                if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $message)) {
                    $keywordHits++;
                }
            } elseif (str_contains($message, $keyword)) {
                $keywordHits++;
            }
        }

        if ($keywordHits > 0) {
            $score = max($score, min(0.85, 0.35 + ($keywordHits * 0.18)));
        }

        // Demote data intents when the user is clearly asking for instructions / creation steps.
        if (! $isHelp && preg_match('/\b(how (do|can|to|should)|where (can|do|to)|steps to|help me)\b/', $message)) {
            $score = min($score, 0.5);
        }
        if (! $isHelp && preg_match('/\b(create|add|make|new)\b.+\b(pr|po|grn|boq|wo|invoice|vendor|project|payment)\b/', $message)) {
            $score = min($score, 0.45);
        }

        return round($score, 4);
    }

    private function detectFollowUp(string $message, ConversationContext $context): ?string
    {
        $last = $context->lastIntent();
        if ($last === null) {
            return null;
        }

        // Strong new-topic phrases should not reuse the previous intent.
        $newTopic = [
            'show outstanding invoice', 'unpaid purchase invoice', 'pending purchase',
            'how do i', 'how can i', 'how to', 'show my project', 'total payable',
            'stock balance', 'work order', 'show recent grn', 'open report',
        ];
        foreach ($newTopic as $needle) {
            if (str_contains($message, $needle)) {
                return null;
            }
        }

        $shortFilters = [
            'only for', 'just for', 'for that', 'for this', 'same for',
            'show last', 'top ', 'only ', 'filter ', 'what about', 'and for',
            'its ', 'their ', 'that project', 'that vendor', 'boq amount',
            'now show the last', 'last three', 'last 3',
        ];

        $isShort = str_word_count($message) <= 8;
        $looksLikeFilter = false;
        foreach ($shortFilters as $needle) {
            if (str_contains($message, $needle)) {
                $looksLikeFilter = true;
                break;
            }
        }

        if ($isShort && $looksLikeFilter) {
            return $last;
        }

        return null;
    }
}

