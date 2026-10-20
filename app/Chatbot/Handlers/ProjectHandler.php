<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;

class ProjectHandler implements IntentHandler
{
    public function __construct(private Access $access) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $intent = (string) ($params['_intent'] ?? 'projects.list');

        if (! empty($params['project_ambiguous'])) {
            return $this->ambiguousProjects($params['project_ambiguous']);
        }

        if ($intent === 'projects.count') {
            $count = Project::query()->visibleTo($user)->count();

            return new ChatResponse(
                message: "You currently have access to **{$count}** project".($count === 1 ? '' : 's').'.',
                links: [['label' => 'Open Projects', 'url' => '/projects']],
                suggestions: ['Show my projects', 'Find project'],
                contextPatch: ['last_intent' => 'projects.count'],
                intent: 'projects.count',
            );
        }

        if ($intent === 'projects.find') {
            $project = $params['project'] ?? null;
            if (! $project || ! empty($project['ambiguous'])) {
                return new ChatResponse(
                    message: 'Which project do you mean? Include the project name or code, for example “Find project Sunrise”.',
                    confidence: 'medium',
                    suggestions: ['Show my projects'],
                    intent: 'projects.find',
                );
            }

            return new ChatResponse(
                message: "Project **{$project['project_name']}** (`{$project['project_code']}`).",
                links: [['label' => 'Open project', 'url' => '/projects/'.$project['id']]],
                suggestions: ['What is its BOQ amount?', 'Show work orders for this project'],
                contextPatch: [
                    'last_intent' => 'projects.find',
                    'project_id' => $project['id'],
                    'project_name' => $project['project_name'],
                ],
                intent: 'projects.find',
            );
        }

        $projects = Project::query()->visibleTo($user)->orderBy('project_name')->limit(8)->get();
        if ($projects->isEmpty()) {
            return new ChatResponse(
                message: 'No projects are available for your account.',
                links: [['label' => 'Projects', 'url' => '/projects']],
                intent: 'projects.list',
            );
        }

        $items = $projects->map(fn (Project $p) => [
            'label' => $p->project_name,
            'meta' => $p->project_code,
            'url' => '/projects/'.$p->id,
        ])->all();

        $lines = collect($items)->map(fn ($i) => "• {$i['label']} (`{$i['meta']}`)")->implode("\n");

        return new ChatResponse(
            message: "Here are your accessible projects:\n{$lines}",
            links: [['label' => 'All projects', 'url' => '/projects']],
            suggestions: ['What is the BOQ amount?', 'Show pending PRs'],
            items: $items,
            contextPatch: ['last_intent' => 'projects.list'],
            intent: 'projects.list',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function ambiguousProjects(array $matches): ChatResponse
    {
        $lines = collect($matches)->map(fn ($p) => "• {$p['project_name']} (`{$p['project_code']}`)")->implode("\n");

        return new ChatResponse(
            message: "I found multiple projects. Which one did you mean?\n{$lines}",
            confidence: 'medium',
            intent: 'projects.find',
        );
    }
}
