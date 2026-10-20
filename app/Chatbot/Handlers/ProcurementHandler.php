<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\EntityResolver;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\ListItem;
use App\Chatbot\Support\MoneyFormat;
use App\Models\MaterialReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access;
use App\Support\DocumentStatus;

class ProcurementHandler implements IntentHandler
{
    public function __construct(private Access $access, private EntityResolver $entities) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $intent = (string) ($params['_intent'] ?? 'procurement.pending_prs');

        return match ($intent) {
            'procurement.find_po' => $this->findPo($user, $params),
            'procurement.approved_pos' => $this->listPos($user, $context, DocumentStatus::APPROVED),
            'procurement.awaiting_receipt' => $this->awaitingReceipt($user, $context),
            default => $this->pendingPrs($user, $context),
        };
    }

    private function pendingPrs(User $user, ConversationContext $context): ChatResponse
    {
        $query = PurchaseRequest::query()
            ->with('project:id,project_name,project_code')
            ->where('status', DocumentStatus::SUBMITTED)
            ->latest('id');

        if ($context->projectId()) {
            $query->where('project_id', $context->projectId());
        } else {
            $query->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        $rows = $query->limit(8)->get();
        if ($rows->isEmpty()) {
            return new ChatResponse(
                message: 'There are no purchase requests currently waiting for approval'
                    .($context->projectId() ? ' on the selected project.' : '.'),
                links: [['label' => 'Approvals', 'url' => '/approvals']],
                contextPatch: ['last_intent' => 'procurement.pending_prs'],
                intent: 'procurement.pending_prs',
            );
        }

        $items = $rows->map(function (PurchaseRequest $pr) {
            return ListItem::make(
                title: $pr->pr_number,
                subtitle: $pr->project?->project_name ?? 'Project',
                date: $pr->request_date,
                meta: 'Waiting for approval',
                url: '/projects/'.$pr->project_id.'/purchase-requests/'.$pr->id,
            );
        })->all();

        return new ChatResponse(
            message: "Found **{$rows->count()}** purchase request(s) waiting for approval.",
            links: [['label' => 'Approvals inbox', 'url' => '/approvals']],
            suggestions: ['Show approved purchase orders', 'How do I create a purchase order?'],
            items: $items,
            contextPatch: ['last_intent' => 'procurement.pending_prs'],
            intent: 'procurement.pending_prs',
        );
    }

    private function listPos(User $user, ConversationContext $context, string $status): ChatResponse
    {
        $query = PurchaseOrder::query()
            ->with(['project:id,project_name', 'vendor:id,name'])
            ->where('status', $status)
            ->latest('po_date');

        if ($context->projectId()) {
            $query->where('project_id', $context->projectId());
        } else {
            $query->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        if ($context->vendorId()) {
            $query->where('vendor_id', $context->vendorId());
        }

        $rows = $query->limit(8)->get();
        if ($rows->isEmpty()) {
            return new ChatResponse(
                message: 'No approved purchase orders matched your filters.',
                intent: 'procurement.approved_pos',
            );
        }

        $items = $rows->map(function (PurchaseOrder $po) {
            return ListItem::make(
                title: $po->po_number,
                subtitle: $po->vendor?->name ?? 'Vendor',
                amount: MoneyFormat::inr($po->grand_total),
                date: $po->po_date,
                meta: $po->project?->project_name,
                url: '/projects/'.$po->project_id.'/purchase-orders/'.$po->id,
            );
        })->all();

        return new ChatResponse(
            message: 'Here are **'.$rows->count().'** recent approved purchase orders.',
            suggestions: ['Which orders are awaiting receipt?', 'Find PO'],
            items: $items,
            contextPatch: ['last_intent' => 'procurement.approved_pos'],
            intent: 'procurement.approved_pos',
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findPo(User $user, array $params): ChatResponse
    {
        $number = $params['document_number'] ?? null;
        if (! $number || ! str_starts_with(strtoupper((string) $number), 'PO')) {
            return new ChatResponse(
                message: 'Please include a PO number, for example “Find PO-2026-001”.',
                confidence: 'medium',
                intent: 'procurement.find_po',
            );
        }

        $po = $this->entities->findPurchaseOrder(strtoupper((string) $number), $user);
        if (! $po) {
            return new ChatResponse(
                message: 'I could not find that purchase order in records you can access.',
                intent: 'procurement.find_po',
            );
        }

        return new ChatResponse(
            message: "Purchase order **{$po->po_number}** is **{$po->status}**, dated {$po->po_date?->toDateString()}, total ".MoneyFormat::inr($po->grand_total).'.',
            links: [['label' => 'Open PO', 'url' => '/projects/'.$po->project_id.'/purchase-orders/'.$po->id]],
            contextPatch: [
                'last_intent' => 'procurement.find_po',
                'project_id' => $po->project_id,
                'vendor_id' => $po->vendor_id,
            ],
            intent: 'procurement.find_po',
        );
    }

    private function awaitingReceipt(User $user, ConversationContext $context): ChatResponse
    {
        $postedPoIds = MaterialReceipt::query()
            ->whereNotNull('purchase_order_id')
            ->where('status', MaterialReceipt::STATUS_POSTED)
            ->pluck('purchase_order_id');

        $query = PurchaseOrder::query()
            ->with(['project:id,project_name', 'vendor:id,name'])
            ->where('status', DocumentStatus::APPROVED)
            ->whereNotIn('id', $postedPoIds)
            ->latest('po_date');

        if ($context->projectId()) {
            $query->where('project_id', $context->projectId());
        } else {
            $query->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        $rows = $query->limit(8)->get();

        if ($rows->isEmpty()) {
            return new ChatResponse(
                message: 'No approved purchase orders currently look awaiting receipt under your access.',
                intent: 'procurement.awaiting_receipt',
            );
        }

        $items = $rows->map(function (PurchaseOrder $po) {
            return ListItem::make(
                title: $po->po_number,
                subtitle: $po->vendor?->name ?? 'Vendor',
                amount: MoneyFormat::inr($po->grand_total),
                date: $po->po_date,
                meta: 'Awaiting receipt',
                url: '/projects/'.$po->project_id.'/purchase-orders/'.$po->id,
            );
        })->all();

        return new ChatResponse(
            message: 'Found **'.$rows->count().'** approved purchase order(s) that may still need goods receipt. Confirm on the GRN screens before acting.',
            suggestions: ['Show recent GRNs', 'How do I record a goods receipt?'],
            items: $items,
            contextPatch: ['last_intent' => 'procurement.awaiting_receipt'],
            intent: 'procurement.awaiting_receipt',
        );
    }
}

