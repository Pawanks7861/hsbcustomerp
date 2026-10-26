<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\ListItem;
use App\Chatbot\Support\MoneyFormat;
use App\Models\MaterialReceipt;
use App\Models\User;
use App\Services\Access;
use App\Services\Reports\ReportQueryService;

class InventoryHandler implements IntentHandler
{
    public function __construct(
        private Access $access,
        private ReportQueryService $reports,
    ) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $intent = (string) ($params['_intent'] ?? 'inventory.stock');

        return match ($intent) {
            'inventory.low_stock' => $this->lowStock($user),
            'inventory.recent_grns' => $this->recentGrns($user, $context),
            default => $this->stock($user, $params, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function stock(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $projectId = $params['project_id'] ?? $context->projectId();
        if (! $projectId) {
            return new ChatResponse(
                message: 'Stock balances are project-scoped in reports. Tell me the project, or ask after selecting one. You can also open the stock balance report.',
                confidence: 'medium',
                links: [['label' => 'Stock balance report', 'url' => '/reports/stock-balance']],
                intent: 'inventory.stock',
            );
        }

        $result = $this->reports->run('stock-balance', $user, [
            'project_id' => $projectId,
        ]);

        $rows = collect($result['rows'] ?? [])->take(8);
        if ($rows->isEmpty()) {
            return new ChatResponse(
                message: 'No stock balance rows were found for that project.',
                links: [['label' => 'Stock balance report', 'url' => '/reports/stock-balance?project_id='.$projectId]],
                intent: 'inventory.stock',
            );
        }

        $code = $params['code'] ?? null;
        if ($code) {
            $rows = $rows->filter(fn ($row) => strcasecmp((string) ($row['material_code'] ?? ''), (string) $code) === 0
                || str_contains(strtolower((string) ($row['material_name'] ?? '')), strtolower((string) $code)));
        }

        $items = $rows->map(function ($row) {
            return ListItem::make(
                title: (string) ($row['material_code'] ?? 'Item'),
                subtitle: (string) ($row['material_name'] ?? ''),
                amount: MoneyFormat::qty($row['available'] ?? 0, $row['unit'] ?? null),
                meta: 'On hand',
            );
        })->values()->all();

        return new ChatResponse(
            message: 'Current stock from the ERP stock balance report (**'.count($items).'** items shown).',
            links: [['label' => 'Full stock balance report', 'url' => '/reports/stock-balance?project_id='.$projectId]],
            suggestions: ['Which items have low stock?', 'Show recent GRNs'],
            items: $items,
            contextPatch: ['last_intent' => 'inventory.stock', 'project_id' => $projectId],
            intent: 'inventory.stock',
        );
    }

    private function lowStock(User $user): ChatResponse
    {
        return new ChatResponse(
            message: 'Minimum stock thresholds are no longer used. Open the stock balance report or Central Store to review available quantities and out-of-stock items.',
            links: [
                ['label' => 'Stock balance report', 'url' => '/reports/stock-balance'],
                ['label' => 'Central Store', 'url' => '/inventory/central'],
            ],
            suggestions: ['What is the current stock?', 'Show recent GRNs'],
            intent: 'inventory.low_stock',
        );
    }

    private function recentGrns(User $user, ConversationContext $context): ChatResponse
    {
        $query = MaterialReceipt::query()
            ->with(['project:id,project_name', 'vendor:id,name'])
            ->whereNotNull('purchase_order_id')
            ->latest('id');

        if ($context->projectId()) {
            $query->where('project_id', $context->projectId());
        } else {
            $query->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        $rows = $query->limit(8)->get();
        if ($rows->isEmpty()) {
            return new ChatResponse(message: 'No recent GRNs found for your accessible projects.', intent: 'inventory.recent_grns');
        }

        $items = $rows->map(function (MaterialReceipt $receipt) {
            return ListItem::make(
                title: (string) ($receipt->receipt_number ?? '#'.$receipt->id),
                subtitle: $receipt->vendor?->name ?? 'Vendor',
                date: $receipt->receipt_date ?? $receipt->created_at,
                meta: ucfirst((string) $receipt->status),
                url: '/projects/'.$receipt->project_id.'/store/'.$receipt->id,
            );
        })->all();

        return new ChatResponse(
            message: 'Here are **'.$rows->count().'** recent goods receipts.',
            suggestions: ['Which orders are awaiting receipt?'],
            items: $items,
            contextPatch: ['last_intent' => 'inventory.recent_grns'],
            intent: 'inventory.recent_grns',
        );
    }
}
