<?php

namespace App\Chatbot;

final class IntentDefinition
{
    /**
     * @param  list<string>  $phrases
     * @param  list<string>  $keywords
     * @param  list<string>  $synonyms
     * @param  list<string>  $permissions Any one permission grants access (OR). Empty = any authenticated user.
     * @param  list<string>  $requiredParams
     * @param  list<string>  $suggestions
     * @param  class-string<\App\Chatbot\Contracts\IntentHandler>  $handler
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $phrases,
        public readonly array $keywords,
        public readonly array $synonyms,
        public readonly array $permissions,
        public readonly string $handler,
        public readonly array $requiredParams = [],
        public readonly array $suggestions = [],
        public readonly bool $readOnly = true,
        public readonly int $priority = 100,
    ) {}
}
