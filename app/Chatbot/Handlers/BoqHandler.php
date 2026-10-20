<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\MoneyFormat;
use App\Models\Boq;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;
use App\Support\Money;

class BoqHandler implements IntentHandler
{
    public function __construct(private Access $access) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $projectId = $params['project_id'] ?? $context->projectId();
        if (! $projectId) {
            return new ChatResponse(
                message: 'Which project should I use for the BOQ total? For example: “BOQ amount for Sunrise” or open a project first and ask again.',
                confidence: 'medium',
                suggestions: ['Show my projects', 'Find project'],
                intent: 'boq.total',
            );
        }

        $project = Project::query()->find($projectId);
        if (! $project || ! $this->access->canAccessProject($user, $project)) {
            return new ChatResponse(
                message: 'That project is not accessible with your current permissions.',
                confidence: 'high',
                intent: 'boq.total',
            );
        }

        $boqs = Boq::query()->where('project_id', $project->id)->get();
        $total = '0.00';
        foreach ($boqs as $boq) {
            $total = Money::add($total, $boq->grandTotal());
        }

        return new ChatResponse(
            message: "For project **{$project->project_name}**, the current BOQ total is **".MoneyFormat::inr($total)."** across {$boqs->count()} BOQ document(s). This follows the project’s current revision totals used in the ERP.",
            links: [
                ['label' => 'Open project BOQs', 'url' => '/projects/'.$project->id.'/boqs'],
                ['label' => 'BOQ quantity consumption report', 'url' => '/reports/boq-quantity-consumption?project_id='.$project->id],
            ],
            suggestions: ['Show pending PRs', 'Open the BOQ quantity consumption report'],
            contextPatch: [
                'last_intent' => 'boq.total',
                'project_id' => $project->id,
                'project_name' => $project->project_name,
            ],
            intent: 'boq.total',
        );
    }
}
