<?php

namespace Tests\Feature;

use App\Imports\ImportEngine;
use App\Imports\Support\DuplicateMode;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\IdnAttachment;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class Phase2Sec08AttachmentTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_invoice_pdf_upload_is_stored_safely_and_served_inline(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($admin, $vendor);

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-SAFE',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => 100,
            'invoice_file' => UploadedFile::fake()->create('../../etc/passwd.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertDoesNotMatchRegularExpression('/\.\./', (string) $invoice->invoice_file_path);
        $this->assertMatchesRegularExpression('#^projects/'.$project->id.'/invoices/[0-9a-f-]{36}\.pdf$#', $invoice->invoice_file_path);
        Storage::disk('local')->assertExists($invoice->invoice_file_path);

        $response = $this->actingAs($admin)
            ->get(route('projects.purchase-invoices.attachment', [$project, $invoice]))
            ->assertOk();

        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_invoice_rejects_html_svg_mismatch_and_oversized_files(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($admin, $vendor);
        $payload = [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-BAD',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => 100,
        ];

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            ...$payload,
            'invoice_file' => $this->realUpload('bill.pdf', '<script>alert(1)</script><html><body>x</body></html>'),
        ])->assertSessionHasErrors('invoice_file');

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            ...$payload,
            'invoice_file' => $this->realUpload(
                'scan.jpg',
                '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            ),
        ])->assertSessionHasErrors('invoice_file');

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            ...$payload,
            'invoice_file' => UploadedFile::fake()->create('bill.exe', 20, 'application/x-msdownload'),
        ])->assertSessionHasErrors('invoice_file');

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            ...$payload,
            'invoice_file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf'),
        ])->assertSessionHasErrors('invoice_file');

        $this->assertSame(0, PurchaseInvoice::query()->count());
    }

    public function test_invoice_attachment_is_forbidden_or_hidden_without_access(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($admin, $vendor);

        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-ACL',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => 100,
            'invoice_file' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $other = Project::factory()->create();

        auth()->logout();
        $this->get(route('projects.purchase-invoices.attachment', [$project, $invoice]))
            ->assertRedirect();

        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        $this->actingAs($requester)
            ->get(route('projects.purchase-invoices.attachment', [$project, $invoice]))
            ->assertForbidden();

        $selected = User::factory()->withRoles('accounts')->create(['project_access' => 'selected']);
        $selected->projects()->sync([$other->id]);
        $this->actingAs($selected)
            ->get(route('projects.purchase-invoices.attachment', [$project, $invoice]))
            ->assertNotFound();
        $this->actingAs($selected)
            ->get(route('projects.purchase-invoices.attachment', [$other, $invoice]))
            ->assertNotFound();
    }

    public function test_store_images_are_inline_and_cannot_be_html_or_cross_linked(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($admin, $vendor);
        $line = $order->items()->first();

        $this->actingAs($admin)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-09',
            'purchase_order_id' => $order->id,
            'received_by' => 'Site Engineer',
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'quantity_received' => 1,
            ]],
            'images' => [UploadedFile::fake()->image('site.jpg')],
        ])->assertRedirect();

        $receipt = $project->materialReceipts()->firstOrFail();
        $image = $receipt->images()->firstOrFail();
        $this->assertMatchesRegularExpression('#^projects/'.$project->id.'/receipts/[0-9a-f-]{36}\.jpe?g$#', $image->image_path);

        $response = $this->actingAs($admin)
            ->get(route('projects.store.images.show', [$project, $receipt, $image]))
            ->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('content-type'));

        $this->actingAs($admin)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-09',
            'received_by' => 'Site Engineer',
            'items' => [['description' => 'Manual bags', 'quantity_received' => 1]],
            'images' => [$this->realUpload('photo.jpg', '<html><script>alert(1)</script></html>')],
        ])->assertSessionHasErrors('images.0');

        $other = Project::factory()->create();
        $this->actingAs($admin)
            ->get(route('projects.store.images.show', [$other, $receipt, $image]))
            ->assertNotFound();

        $this->actingAs($admin)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-10',
            'received_by' => 'Site Engineer',
            'items' => [['description' => 'Other bags', 'quantity_received' => 1]],
            'images' => [UploadedFile::fake()->image('other.jpg')],
        ])->assertRedirect();

        $foreign = $project->materialReceipts()->latest('id')->firstOrFail()->images()->firstOrFail();
        $this->actingAs($admin)
            ->get(route('projects.store.images.show', [$project, $receipt, $foreign]))
            ->assertNotFound();
    }

    public function test_idn_and_adjustment_attachments_reject_html_and_serve_legacy_html_as_download(): void
    {
        $admin = User::factory()->admin()->create();
        $source = Project::factory()->create();
        $destination = Project::factory()->create();
        $material = Material::query()->create([
            'material_code' => 'MAT-SEC08',
            'name' => 'Cement',
            'unit' => 'Bag',
            'is_active' => true,
        ]);
        $from = InventoryLocation::query()->where('project_id', $source->id)->where('is_default', true)->firstOrFail();
        $to = InventoryLocation::query()->where('project_id', $destination->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => 1]],
            'attachments' => [$this->realUpload('challan.pdf', '<script>alert(1)</script><html><body>x</body></html>')],
        ])->assertSessionHasErrors('attachments.0');

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => 1]],
            'attachments' => [UploadedFile::fake()->create('challan.pdf', 20, 'application/pdf')],
        ])->assertRedirect();

        $note = InternalDeliveryNote::query()->firstOrFail();
        $file = $note->attachments()->firstOrFail();
        $pdf = $this->actingAs($admin)
            ->get(route('inventory.transfers.attachments.show', [$note, $file]))
            ->assertOk();
        $this->assertStringContainsString('inline', (string) $pdf->headers->get('content-disposition'));

        Storage::disk('local')->put('idn/'.$note->id.'/legacy.html', '<html><body>stored xss</body></html>');
        $legacy = IdnAttachment::query()->create([
            'internal_delivery_note_id' => $note->id,
            'path' => 'idn/'.$note->id.'/legacy.html',
            'original_name' => "../../payload.html\r\nContent-Type: text/html",
        ]);
        $download = $this->actingAs($admin)
            ->get(route('inventory.transfers.attachments.show', [$note, $legacy]))
            ->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertSame('application/octet-stream', $download->headers->get('content-type'));
        $this->assertStringNotContainsString("\r", (string) $download->headers->get('content-disposition'));

        $outsider = User::factory()->withRoles('storekeeper')->create(['project_access' => 'selected']);
        $outsider->projects()->sync([Project::factory()->create()->id]);
        $this->actingAs($outsider)
            ->get(route('inventory.transfers.attachments.show', [$note, $file]))
            ->assertNotFound();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $source), [
            'material_id' => $material->id,
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => 'opening',
            'quantity' => 1,
            'reason' => 'Opening',
            'attachment' => $this->realUpload('note.pdf', '<script>alert(1)</script><html><body>x</body></html>'),
        ])->assertSessionHasErrors('attachment');

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $source), [
            'material_id' => $material->id,
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => 'opening',
            'quantity' => 1,
            'reason' => 'Opening',
            'attachment' => UploadedFile::fake()->image('photo.png'),
        ])->assertRedirect();

        $adjustment = StockAdjustment::query()->firstOrFail();
        $image = $this->actingAs($admin)
            ->get(route('projects.inventory.adjustments.attachment', [$source, $adjustment]))
            ->assertOk();
        $this->assertStringContainsString('inline', (string) $image->headers->get('content-disposition'));
        $this->assertSame('image/png', $image->headers->get('content-type'));

        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        $this->actingAs($requester)
            ->get(route('projects.inventory.adjustments.attachment', [$source, $adjustment]))
            ->assertForbidden();
    }

    public function test_expense_attachment_rejects_html_and_stores_a_safe_name(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $category = ExpenseCategory::query()->where('name', 'Labour')->firstOrFail();
        $base = [
            'date' => '2026-10-15',
            'category_id' => $category->id,
            'description' => 'Masonry labour',
            'amount' => 100,
            'payment_status' => Expense::PAYMENT_PAID,
        ];

        $this->actingAs($admin)->post(route('projects.expenses.store', $project), [
            ...$base,
            'attachment' => $this->realUpload('receipt.pdf', '<html>xss</html>'),
        ])->assertSessionHasErrors('attachment');

        $this->actingAs($admin)->post(route('projects.expenses.store', $project), [
            ...$base,
            'attachment' => UploadedFile::fake()->create('../../receipt.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $expense = Expense::query()->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $expense->status);
        $this->assertMatchesRegularExpression('#^expenses/[0-9a-f-]{36}\.pdf$#', (string) $expense->attachment_path);
        Storage::disk('local')->assertExists($expense->attachment_path);
    }

    public function test_import_and_company_logo_reject_html_content(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('imports.preview', 'vendors'), [
            'file' => $this->realUpload('vendors.xlsx', '<html><body>not a sheet</body></html>'),
            'duplicate_mode' => DuplicateMode::CREATE_ONLY,
        ])->assertSessionHasErrors('file');

        try {
            app(ImportEngine::class)->preview(
                'vendors',
                $this->realUpload('vendors.xlsx', '<html><body>not a sheet</body></html>'),
                $admin,
            );
            $this->fail('HTML disguised as xlsx should be rejected by the import engine.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'logo' => $this->realUpload('logo.png', '<html>x</html>'),
        ])->assertSessionHasErrors('logo');

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();
    }

    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sec08-'.uniqid('', true).'-'.basename(str_replace('\\', '/', $name));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }
}
