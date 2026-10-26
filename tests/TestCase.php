<?php

namespace Tests;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function vendorProfile(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ABC Cement',
            'party_type' => Vendor::VENDOR,
            'contact_person' => 'Ravi Patel',
            'phone' => '9999999999',
            'email' => 'vendor@example.com',
            'address' => '12 Industrial Area',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380001',
            'gst_number' => '24AAAAA0000A1Z5',
            'pan_number' => 'AAAAA0000A',
            'bank_name' => 'HDFC Bank',
            'account_number' => '123456789012',
            'ifsc' => 'HDFC0001234',
        ], $overrides);
    }

    protected function completePoApproval(PurchaseOrder $order, User $first, ?User $second = null): User
    {
        $second ??= User::factory()->admin()->create();

        $this->actingAs($first)
            ->post(route('projects.purchase-orders.approve', [$order->project_id, $order]))
            ->assertRedirect();
        $this->actingAs($second)
            ->post(route('projects.purchase-orders.approve', [$order->project_id, $order]))
            ->assertRedirect();

        return $second;
    }

    protected function markInvoiceApproved(PurchaseInvoice $invoice, ?User $user = null): void
    {
        $invoice->forceFill([
            'approval_status' => PurchaseInvoice::APPROVAL_APPROVED,
            'approved_at' => now(),
            'approved_by' => $user?->id ?? $invoice->created_by,
        ])->save();
    }
}
