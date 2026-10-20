<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Models\User;
use App\Services\Access;
use App\Services\Reports\ReportCatalog;

class ReportHandler implements IntentHandler
{
    public function __construct(private Access $access) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $message = (string) ($params['_normalized'] ?? '');
        $map = [
            'purchase-order-register' => ['purchase order register', 'po register'],
            'invoice-outstanding' => ['invoice outstanding', 'outstanding invoice report'],
            'vendor-outstanding' => ['vendor outstanding', 'vendor outstanding report'],
            'stock-balance' => ['stock balance', 'stock report'],
            'expense-summary' => ['expense summary', 'expense report'],
            'boq-quantity-consumption' => ['boq quantity', 'quantity consumption', 'boq consumption'],
            'project-summary' => ['project summary'],
            'procurement-summary' => ['procurement summary'],
            'debit-credit-note-register' => ['debit', 'credit note register', 'dn cn'],
        ];

        $matched = null;
        foreach ($map as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    $matched = $slug;
                    break 2;
                }
            }
        }

        if ($matched === null) {
            $catalog = collect(ReportCatalog::all())->map(fn ($r) => '• '.$r['name'])->implode("\n");

            return new ChatResponse(
                message: "I couldn't identify which report you mean. Available reports:\n{$catalog}",
                confidence: 'medium',
                links: [['label' => 'Open Reports', 'url' => '/reports']],
                suggestions: [
                    'Show the vendor outstanding report',
                    'Show the invoice outstanding report',
                    'Show stock balance',
                ],
                intent: 'reports.open',
            );
        }

        $report = ReportCatalog::find($matched);
        $url = '/reports/'.$matched;
        if ($context->projectId()) {
            $url .= '?project_id='.$context->projectId();
        }

        return new ChatResponse(
            message: 'I can open the **'.($report['name'] ?? $matched).'** report. It uses the same filters and calculations as the Reports module.',
            links: [
                ['label' => 'Open '.($report['name'] ?? 'report'), 'url' => $url],
                ['label' => 'All reports', 'url' => '/reports'],
            ],
            contextPatch: ['last_intent' => 'reports.open'],
            intent: 'reports.open',
        );
    }
}
