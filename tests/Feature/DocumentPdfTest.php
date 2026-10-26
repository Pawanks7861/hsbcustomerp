<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\CompanySetting;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_request_pdf_renders_download_and_stays_in_project(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $request] = $this->purchaseRequest();
        $other = Project::factory()->create();
        $this->company();

        $view = $this->actingAs($user)->get(route('projects.purchase-requests.pdf', [$project, $request]));
        $this->assertPdf($view, 'inline');
        $this->assertPdfContains($view, $request->pr_number);
        $this->assertPdfContains($view, 'Site OPC bags');
        $this->assertPdfContains($view, 'OPC cement bags');
        $this->assertPdfContains($view, 'Site requirement approved');
        $this->assertPdfContains($view, 'Acme Construction');
        $pdfText = $this->pdfText($view->getContent());
        $this->assertStringNotContainsString('BOQ QTY EXCEEDED', $pdfText);
        $this->assertStringNotContainsString('SecretBoqItemNameXYZ', $pdfText);
        $this->assertStringNotContainsString('BOQ-CODE-HIDDEN-XYZ', $pdfText);
        $this->assertStringNotContainsString('BOQ-HIDDEN-XYZ', $pdfText);
        $this->assertStringNotContainsString('HiddenBoqTitleXYZ', $pdfText);

        $download = $this->actingAs($user)->get(route('projects.purchase-requests.pdf.download', [$project, $request]));
        $this->assertPdf($download, 'attachment');
        $this->assertStringContainsString($request->pr_number.'.pdf', (string) $download->headers->get('content-disposition'));
        $downloadText = $this->pdfText($download->getContent());
        $this->assertStringNotContainsString('SecretBoqItemNameXYZ', $downloadText);
        $this->assertStringNotContainsString('BOQ-CODE-HIDDEN-XYZ', $downloadText);

        $this->actingAs($user)
            ->get(route('projects.purchase-requests.pdf', [$other, $request]))
            ->assertNotFound();
    }

    public function test_purchase_order_pdf_uses_snapshot_lines_and_stored_totals(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order] = $this->purchaseOrder();
        $this->company();

        $order->vendor->update(['name' => 'Renamed Vendor', 'address' => 'New live address', 'city' => 'Surat']);

        $view = $this->actingAs($user)->get(route('projects.purchase-orders.pdf', [$project, $order]));
        $this->assertPdf($view, 'inline');
        $this->assertPdfContains($view, $order->po_number);
        $this->assertPdfContains($view, '14 Industrial Area');
        $this->assertPdfContains($view, 'Ahmedabad Gujarat');
        $this->assertPdfContains($view, '24ACMEGST0001Z');
        $this->assertPdfContains($view, 'accounts@acme.test');
        $this->assertPdfContains($view, 'https://acme.test');
        $this->assertPdfContains($view, 'PO-2026-001 - Supply of cement');
        $this->assertPdfContains($view, 'Order date: 09 Oct 2026');
        $this->assertPdfContains($view, 'Snapshot Supplies');
        $this->assertPdfContains($view, '24SNAPSHOTGST');
        $this->assertPdfContains($view, 'North Block');
        $this->assertPdfContains($view, 'PR-2026-001');
        $this->assertPdfContains($view, 'Payment in 15 days');
        $this->assertPdfContains($view, 'Locked cement line');
        $this->assertPdfContains($view, '2523');
        $this->assertPdfContains($view, 'Punjab');
        $this->assertPdfContains($view, 'CGST');
        $this->assertPdfContains($view, 'SGST');
        $this->assertPdfContains($view, '16.20');
        $this->assertPdfContains($view, 'Freight');
        $this->assertPdfContains($view, 'Round off');
        $this->assertPdfContains($view, '212.40');
        $text = $this->pdfText($view->getContent());
        $this->assertStringNotContainsString('SecretBoqItemNameXYZ', $text);
        $this->assertStringNotContainsString('BOQ-CODE-HIDDEN-XYZ', $text);
        $this->assertStringNotContainsString('BOQ-HIDDEN-XYZ', $text);
        $this->assertStringNotContainsString('HiddenBoqTitleXYZ', $text);
        $this->assertStringNotContainsString('BOQ:', $text);
        $this->assertStringNotContainsString('##', $text);
        $this->assertStringNotContainsString('#PO', $text);
        $this->assertStringNotContainsString('#PR', $text);
        $text = $this->pdfText($view->getContent());
        $this->assertStringNotContainsString('Renamed Vendor', $text);
        $this->assertStringNotContainsString('Surat', $text);

        $order->update(['subject' => null, 'terms_conditions' => null]);
        $plainText = $this->pdfText($this->actingAs($user)->get(route('projects.purchase-orders.pdf', [$project, $order->fresh()]))->getContent());
        $this->assertStringNotContainsString('PO-2026-001 -', $plainText);
        $this->assertStringNotContainsString('Payment in 15 days', $plainText);
        $this->assertStringNotContainsString('Terms', $plainText);
        $this->assertStringContainsString('PO-2026-001', $plainText);

        $download = $this->actingAs($user)->get(route('projects.purchase-orders.pdf.download', [$project, $order]));
        $this->assertPdf($download, 'attachment');
        $downloadText = $this->pdfText($download->getContent());
        $this->assertStringContainsString('Locked cement line', $downloadText);
        $this->assertStringNotContainsString('SecretBoqItemNameXYZ', $downloadText);
        $this->assertStringNotContainsString('BOQ-CODE-HIDDEN-XYZ', $downloadText);

        $this->actingAs($user)
            ->get(route('projects.purchase-orders.pdf', [Project::factory()->create(), $order]))
            ->assertNotFound();
    }

    public function test_material_receipt_pdf_shows_linked_order_and_project(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order] = $this->purchaseOrder();
        $vendor = $order->vendor;
        $receipt = MaterialReceipt::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'receipt_number' => 'MR-2026-001',
            'receipt_date' => '2026-10-09',
            'received_by' => 'Store Keeper',
        ]);
        $receipt->items()->create([
            'description' => 'Received cement bags',
            'unit' => 'Bag',
            'quantity_received' => '4.000',
        ]);
        $this->company();

        $view = $this->actingAs($user)->get(route('projects.store.pdf', [$project, $receipt]));
        $this->assertPdf($view, 'inline');
        $this->assertPdfContains($view, 'MR-2026-001');
        $this->assertPdfContains($view, $project->project_name);
        $this->assertPdfContains($view, $order->po_number);
        $this->assertPdfContains($view, $vendor->name);

        $download = $this->actingAs($user)->get(route('projects.store.pdf.download', [$project, $receipt]));
        $this->assertPdf($download, 'attachment');
        $this->assertStringContainsString('MR-2026-001.pdf', (string) $download->headers->get('content-disposition'));

        $this->actingAs($user)
            ->get(route('projects.store.pdf', [Project::factory()->create(), $receipt]))
            ->assertNotFound();
    }

    public function test_purchase_invoice_pdf_uses_balance_service_amounts(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order] = $this->purchaseOrder();
        $invoice = PurchaseInvoice::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'invoice_number' => 'INV-2026-001',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => '1000.00',
            'tax_amount' => '180.00',
            'total_amount' => '1180.00',
            'invoice_file_path' => 'projects/1/invoices/bill.pdf',
            'status' => PurchaseInvoice::STATUS_PARTIAL,
            'created_by' => $user->id,
        ]);
        VendorPayment::query()->create([
            'vendor_id' => $order->vendor_id,
            'project_id' => $project->id,
            'purchase_invoice_id' => $invoice->id,
            'payment_date' => '2026-10-10',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'upi',
            'amount' => '400.00',
            'created_by' => $user->id,
        ]);
        $this->company();

        $view = $this->actingAs($user)->get(route('projects.purchase-invoices.pdf', [$project, $invoice]));
        $this->assertPdf($view, 'inline');
        $this->assertPdfContains($view, 'INV-2026-001');
        $this->assertPdfContains($view, $order->po_number);
        $this->assertPdfContains($view, '1,180.00');
        $this->assertPdfContains($view, '780.00');
        $this->assertPdfContains($view, 'Attachment Available');

        $download = $this->actingAs($user)->get(route('projects.purchase-invoices.pdf.download', [$project, $invoice]));
        $this->assertPdf($download, 'attachment');

        $this->actingAs($user)
            ->get(route('projects.purchase-invoices.pdf', [Project::factory()->create(), $invoice]))
            ->assertNotFound();
    }

    public function test_pdf_renders_with_and_without_a_logo(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order] = $this->purchaseOrder();

        $this->company();
        $plain = $this->actingAs($user)->get(route('projects.purchase-orders.pdf', [$project, $order]));
        $this->assertPdf($plain, 'inline');
        $this->assertPdfContains($plain, 'Acme Construction');

        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('company/logo-test.png', $png);
        CompanySetting::query()->first()->update(['logo_path' => 'company/logo-test.png']);

        $withLogo = $this->actingAs($user)->get(route('projects.purchase-orders.pdf', [$project, $order]));
        $this->assertPdf($withLogo, 'inline');
        $this->assertPdfContains($withLogo, 'Acme Construction');
        $this->assertStringContainsString('/Image', $withLogo->getContent());
    }

    /**
     * @return array{0: Project, 1: PurchaseRequest}
     */
    private function purchaseRequest(): array
    {
        $project = Project::factory()->create(['project_name' => 'North Block', 'project_code' => 'NB']);
        $boq = Boq::factory()->create([
            'project_id' => $project->id,
            'title' => 'HiddenBoqTitleXYZ',
            'boq_number' => 'BOQ-HIDDEN-XYZ',
        ]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => 'BOQ-CODE-HIDDEN-XYZ',
            'description' => 'SecretBoqItemNameXYZ',
            'unit' => 'Bag',
            'quantity' => 10,
        ]);
        $user = User::factory()->admin()->create(['name' => 'Requester']);
        $request = PurchaseRequest::query()->create([
            'project_id' => $project->id,
            'boq_id' => $boq->id,
            'boq_item_id' => $item->id,
            'pr_number' => 'PR-2026-001',
            'request_date' => '2026-10-09',
            'requested_by' => $user->id,
            'description' => 'Cement for footing',
            'requested_boq_quantity' => '12.000',
            'status' => 'submitted',
            'is_boq_quantity_exceeded' => true,
            'boq_quantity_at_submission' => '10.000',
            'previous_requested_quantity_at_submission' => '0.000',
            'excess_quantity_at_submission' => '2.000',
            'over_quantity_approved_by' => $user->id,
            'over_quantity_approved_at' => '2026-10-09 10:30:00',
            'over_quantity_approval_note' => 'Site requirement approved',
        ]);
        $request->items()->create([
            'name' => 'Site OPC bags',
            'description' => 'OPC cement bags',
            'service_code' => 'CEM-01',
            'unit' => 'Bag',
            'quantity' => '12.000',
            'estimated_rate' => '20.00',
            'estimated_amount' => '240.00',
            'remarks' => 'Priority',
        ]);

        return [$project, $request];
    }

    /**
     * @return array{0: Project, 1: PurchaseOrder}
     */
    private function purchaseOrder(): array
    {
        [$project, $request] = $this->purchaseRequest();
        $vendor = Vendor::factory()->create([
            'name' => 'Snapshot Supplies',
            'address' => '12 Yard Road',
            'phone' => '9811111111',
            'email' => 'vendor@example.com',
            'gst_number' => '24SNAPSHOTGST',
        ]);
        $order = PurchaseOrder::query()->create([
            'project_id' => $project->id,
            'boq_id' => $request->boq_id,
            'boq_item_id' => $request->boq_item_id,
            'purchase_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'po_number' => 'PO-2026-001',
            'subject' => 'Supply of cement',
            'po_date' => '2026-10-09',
            'supplier_name' => 'Snapshot Supplies',
            'supplier_address' => '12 Yard Road',
            'supplier_phone' => '9811111111',
            'supplier_email' => 'vendor@example.com',
            'gst_number' => '24SNAPSHOTGST',
            'vendor_name_snapshot' => 'Snapshot Supplies',
            'vendor_address_snapshot' => '12 Yard Road',
            'vendor_city_snapshot' => 'Ahmedabad',
            'vendor_state_snapshot' => 'Gujarat',
            'vendor_pincode_snapshot' => '380015',
            'vendor_phone_snapshot' => '9811111111',
            'vendor_email_snapshot' => 'vendor@example.com',
            'vendor_gst_snapshot' => '24SNAPSHOTGST',
            'delivery_date' => '2026-10-20',
            'delivery_address' => 'North Block site',
            'place_of_supply' => 'Punjab',
            'subtotal' => '180.00',
            'discount_total' => '0.00',
            'taxable_value' => '180.00',
            'gst_percentage' => '18.00',
            'cgst_percentage' => '9.00',
            'sgst_percentage' => '9.00',
            'gst_amount' => '32.40',
            'cgst_amount' => '16.20',
            'sgst_amount' => '16.20',
            'freight' => '0.00',
            'round_off' => '0.00',
            'grand_total' => '212.40',
            'terms_conditions' => 'Payment in 15 days',
            'remarks' => 'Deliver before noon',
            'status' => 'approved',
            'created_by' => $request->requested_by,
            'approved_by' => $request->requested_by,
            'approved_at' => '2026-10-09 11:00:00',
        ]);
        $order->items()->create([
            'name' => 'Locked cement line',
            'purchase_request_item_id' => $request->items()->first()->id,
            'description' => 'Locked cement line',
            'hsn_sac' => '2523',
            'service_code' => 'CEM-01',
            'unit' => 'Bag',
            'quantity' => '2.000',
            'rate' => '90.00',
            'amount' => '180.00',
            'discount_percentage' => '0.00',
            'discount_amount' => '0.00',
            'taxable_amount' => '180.00',
            'cgst_amount' => '16.20',
            'sgst_amount' => '16.20',
            'line_total' => '212.40',
        ]);

        return [$project, $order->fresh('vendor')];
    }

    private function company(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'Acme Construction',
            'address' => '14 Industrial Area',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380001',
            'phone' => '0790000000',
            'email' => 'accounts@acme.test',
            'website' => 'https://acme.test',
            'gst_number' => '24ACMEGST0001Z',
        ]);
    }

    private function assertPdf(TestResponse $response, string $disposition): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString($disposition, (string) $response->headers->get('content-disposition'));
    }

    private function assertPdfContains(TestResponse $response, string $needle): void
    {
        $text = $this->pdfText($response->getContent());
        $this->assertTrue(str_contains($text, $needle), 'PDF missing '.$needle.'. Extracted: '.substr($text, 0, 400));
    }

    private function pdfText(string $binary): string
    {
        $plain = '';

        if (! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $binary, $matches)) {
            return '';
        }

        foreach ($matches[1] as $stream) {
            $chunk = @gzuncompress($stream);
            $chunk = is_string($chunk) ? $chunk : @gzuncompress($stream."\r");
            if (! is_string($chunk) || ! str_contains($chunk, 'TJ')) {
                continue;
            }

            if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $chunk, $strings)) {
                foreach ($strings[1] as $literal) {
                    $plain .= str_replace("\0", '', stripcslashes($literal));
                }
            }
        }

        return $plain;
    }
}
