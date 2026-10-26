<?php

namespace Tests\Unit;

use App\Support\StoredUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoredUploadTest extends TestCase
{
    public function test_download_name_strips_paths_and_header_characters(): void
    {
        $this->assertSame('bill.pdf', StoredUpload::downloadName('../../etc/bill.pdf', 'projects/1/invoices/x.pdf'));
        $injected = StoredUpload::downloadName("safe.pdf\r\nContent-Type: text/html", 'x.pdf');
        $this->assertStringNotContainsString("\r", $injected);
        $this->assertStringNotContainsString("\n", $injected);
        $this->assertStringNotContainsString('"', $injected);
        $this->assertSame('x.pdf', StoredUpload::downloadName('..', 'folder/x.pdf'));
    }

    public function test_store_uses_a_uuid_name_and_ignores_traversal_in_the_original_filename(): void
    {
        Storage::fake('local');

        $path = StoredUpload::store(
            UploadedFile::fake()->create('../../etc/passwd.pdf', 20, 'application/pdf'),
            'projects/4/invoices',
        );

        $this->assertDoesNotMatchRegularExpression('/\.\./', $path);
        $this->assertMatchesRegularExpression('#^projects/4/invoices/[0-9a-f-]{36}\.pdf$#', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_serve_rejects_path_traversal_and_forces_attachment_for_html(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('idn/9/note.html', '<script>alert(1)</script>');

        $this->assertSame(404, $this->statusFrom(fn () => StoredUpload::serve('../idn/9/note.html')));
        $this->assertSame(404, $this->statusFrom(fn () => StoredUpload::serve('idn/9/note.html', expectedPrefix: 'idn/8')));

        $response = StoredUpload::serve('idn/9/note.html', 'payload.html', expectedPrefix: 'idn/9');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertStringNotContainsString('storage', strtolower((string) $response->headers->get('content-disposition')));
    }

    private function statusFrom(callable $callback): int
    {
        try {
            $callback();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }
}
