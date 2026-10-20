<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\ListItem;
use App\Chatbot\Support\MoneyFormat;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\VendorBalanceService;
use App\Support\Money;

class VendorHandler implements IntentHandler
{
    public function __construct(
        private Access $access,
        private VendorBalanceService $balances,
    ) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        if (! $this->access->allows($user, 'vendors.ledger') && ! $this->access->allows($user, 'vendors.view') && ! $user->is_admin) {
            return new ChatResponse(message: 'You do not have permission to view vendor balances.', intent: 'vendors.total_payable');
        }

        if (! empty($params['vendor_ambiguous'])) {
            $lines = collect($params['vendor_ambiguous'])->map(fn ($v) => "• {$v['name']} (`{$v['vendor_code']}`)")->implode("\n");

            return new ChatResponse(
                message: "I found multiple vendors. Which one?\n{$lines}",
                confidence: 'medium',
                intent: 'vendors.vendor_balance',
            );
        }

        $intent = (string) ($params['_intent'] ?? 'vendors.total_payable');
        $vendorId = $params['vendor_id'] ?? $context->vendorId();

        if ($intent === 'vendors.vendor_balance' || $vendorId) {
            return $this->vendorBalance($user, $vendorId, $params, $context);
        }

        return $this->totalPayable($user, $context);
    }

    private function totalPayable(User $user, ConversationContext $context): ChatResponse
    {
        if (! $this->access->allows($user, 'vendors.ledger') && ! $user->is_admin) {
            return new ChatResponse(
                message: 'Vendor outstanding totals require the `vendors.ledger` permission.',
                intent: 'vendors.total_payable',
            );
        }

        $vendors = Vendor::query()->where('status', Vendor::ACTIVE)->orderBy('name')->get();
        $total = '0.00';
        $withBalance = 0;
        $top = [];

        foreach ($vendors as $vendor) {
            $summary = $this->balances->summary($vendor);
            $outstanding = $summary['outstanding_payable'];
            if ((float) $outstanding <= 0) {
                continue;
            }
            $withBalance++;
            $total = Money::add($total, $outstanding);
            $top[] = [
                'name' => $vendor->name,
                'code' => $vendor->vendor_code,
                'outstanding' => $outstanding,
                'id' => $vendor->id,
            ];
        }

        usort($top, fn ($a, $b) => (float) $b['outstanding'] <=> (float) $a['outstanding']);
        $top = array_slice($top, 0, 5);
        $items = collect($top)->map(fn ($row) => ListItem::make(
            title: $row['name'],
            subtitle: $row['code'] ? 'Code: '.$row['code'] : null,
            amount: MoneyFormat::inr($row['outstanding']),
            meta: 'Outstanding',
            url: '/vendors/'.$row['id'],
        ))->all();

        $message = 'Based on current ERP vendor balances, total **outstanding payable** is **'.MoneyFormat::inr($total)."** across {$withBalance} vendor(s) with a balance.";
        if ($items !== []) {
            $message .= "\n\nTop vendor balances:";
        }
        $message .= "\n\nAdvances are tracked separately and only reduce payable after adjustment against invoices.";

        return new ChatResponse(
            message: $message,
            links: [
                ['label' => 'Vendor outstanding report', 'url' => '/reports/vendor-outstanding'],
                ['label' => 'Vendors', 'url' => '/vendors'],
            ],
            suggestions: ['How much do we owe ABC Traders?', 'Show outstanding invoices'],
            items: $items,
            contextPatch: ['last_intent' => 'vendors.total_payable'],
            intent: 'vendors.total_payable',
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function vendorBalance(User $user, mixed $vendorId, array $params, ConversationContext $context): ChatResponse
    {
        if (! $this->access->allows($user, 'vendors.ledger') && ! $user->is_admin) {
            return new ChatResponse(message: 'Vendor ledger access is required for this answer.', intent: 'vendors.vendor_balance');
        }

        if (! $vendorId) {
            return new ChatResponse(
                message: 'Which vendor? Include the vendor name or code, for example “How much do we owe Acme Supplies?”',
                confidence: 'medium',
                suggestions: ['What is our total payable?', 'Show outstanding invoices'],
                intent: 'vendors.vendor_balance',
            );
        }

        $vendor = Vendor::query()->find($vendorId);
        if (! $vendor) {
            return new ChatResponse(message: 'I could not find that vendor in records you can access.', intent: 'vendors.vendor_balance');
        }

        $summary = $this->balances->summary($vendor);

        $message = "Based on the current ERP records, **{$vendor->name}** (`{$vendor->vendor_code}`) has:\n"
            .'- Outstanding payable: **'.MoneyFormat::inr($summary['outstanding_payable'])."**\n"
            .'- Available advance: **'.MoneyFormat::inr($summary['available_advance'])."**\n"
            .'- Refund due: **'.MoneyFormat::inr($summary['refund_due'])."**\n"
            .'- Invoice value: '.MoneyFormat::inr($summary['total_invoice_value'])."\n"
            .'- Invoice payments: '.MoneyFormat::inr($summary['invoice_payments']);

        return new ChatResponse(
            message: $message,
            links: [
                ['label' => 'Open vendor ledger', 'url' => '/vendors/'.$vendor->id.'/ledger'],
                ['label' => 'Open vendor', 'url' => '/vendors/'.$vendor->id],
            ],
            suggestions: ['Show outstanding invoices', 'What is our total payable?'],
            contextPatch: [
                'last_intent' => 'vendors.vendor_balance',
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
            ],
            intent: 'vendors.vendor_balance',
        );
    }
}
