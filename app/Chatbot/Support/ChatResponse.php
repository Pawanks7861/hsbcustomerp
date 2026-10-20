<?php

namespace App\Chatbot\Support;

final class ChatResponse
{
    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<string>  $suggestions
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $contextPatch
     */
    public function __construct(
        public readonly string $message,
        public readonly string $confidence = 'high',
        public readonly array $links = [],
        public readonly array $suggestions = [],
        public readonly array $items = [],
        public readonly array $contextPatch = [],
        public readonly ?string $intent = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'confidence' => $this->confidence,
            'intent' => $this->intent,
            'links' => $this->links,
            'suggestions' => $this->suggestions,
            'items' => $this->items,
        ];
    }
}
