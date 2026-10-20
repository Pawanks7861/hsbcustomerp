<?php

namespace App\Chatbot\Contracts;

use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Models\User;

interface IntentHandler
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function handle(User $user, array $params, ConversationContext $context): ChatResponse;
}
