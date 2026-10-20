<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\EntityResolver;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\ListItem;
use App\Chatbot\Support\MoneyFormat;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Access;

class WorkOrderHandler implements IntentHandler
{
    public function __construct(private Access $access, private EntityResolver $entities) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        if (! empty($params['document_number']) && str_starts_with(strtoupper((string) $params['document_number']), 'WO')) {
            $wo = $this->entities->findWorkOrder(strtoupper((string) $params['document_number']), $user);
            if (! $wo) {
                return new ChatResponse(message: 'I could not find that work order in records you can access.', intent: 'work_orders.list');
            }

            return new ChatResponse(
                message: "Work order **{$wo->work_order_number}** is **{$wo->status}**"
                    .($wo->contractor_name ? " for {$wo->contractor_name}" : '')
                    .', total '.MoneyFormat::inr($wo->grand_total).'.',
                links: [['label' => 'Open work order', 'url' => '/projects/'.$wo->project_id.'/work-orders/'.$wo->id]],
                contextPatch: [
                    'last_intent' => 'work_orders.list',
                    'project_id' => $wo->project_id,
                    'vendor_id' => $wo->vendor_id,
                ],
                intent: 'work_orders.list',
            );
        }

        $query = WorkOrder::query()
            ->with(['project:id,project_name', 'vendor:id,name'])
            ->whereNotIn('status', [WorkOrder::CANCELLED, WorkOrder::SUPERSEDED])
            ->latest('id');

        if ($context->projectId() || ! empty($params['project_id'])) {
            $query->where('project_id', $params['project_id'] ?? $context->projectId());
        } else {
            $query->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        if ($context->vendorId()) {
            $query->where('vendor_id', $context->vendorId());
        }

        $rows = $query->limit(8)->get();
        if ($rows->isEmpty()) {
            return new ChatResponse(message: 'No work orders matched your filters.', intent: 'work_orders.list');
        }

        $items = $rows->map(function (WorkOrder $wo) {
            return ListItem::make(
                title: $wo->work_order_number,
                subtitle: $wo->contractor_name ?: $wo->vendor?->name ?: 'Contractor',
                amount: MoneyFormat::inr($wo->grand_total),
                date: $wo->work_order_date ?? $wo->created_at,
                meta: ucfirst((string) $wo->status),
                url: '/projects/'.$wo->project_id.'/work-orders/'.$wo->id,
            );
        })->all();

        return new ChatResponse(
            message: 'Here are **'.$rows->count().'** work order(s).',
            suggestions: ['Find work order WO-2026-001', 'Show my projects'],
            items: $items,
            contextPatch: ['last_intent' => 'work_orders.list'],
            intent: 'work_orders.list',
        );
    }
}
