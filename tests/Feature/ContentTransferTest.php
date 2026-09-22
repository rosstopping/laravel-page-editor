<?php

namespace Tests\Feature;

use Digizu\PageEditor\Services\PageContentStore;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentTransferTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cms-transfer-http-'.bin2hex(random_bytes(8));
        config(['page-editor.path' => $this->directory, 'page-editor.allowed_emails' => ['editor@example.com'], 'page-editor.images.disk' => 'public']);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_transfer_requires_an_authorized_editor(): void
    {
        $this->postJson('/_editor/transfer/export')->assertForbidden();
        $this->postJson('/_editor/transfer/import')->assertForbidden();
        $user = new User;
        $user->email = 'other@example.com';
        $this->actingAs($user);
        $this->postJson('/_editor/transfer/export')->assertForbidden();
        $this->postJson('/_editor/transfer/import')->assertForbidden();
    }

    public function test_export_download_and_confirmed_import_work_in_production(): void
    {
        $this->app['env'] = 'production';
        $user = new User;
        $user->id = 1;
        $user->email = 'editor@example.com';
        $this->actingAs($user)->withSession(['_token' => 'transfer-token'])->withHeader('X-CSRF-TOKEN', 'transfer-token');
        app(PageContentStore::class)->changeScoped(0, 'publish', [], [], [], 1);
        $response = $this->post('/_editor/transfer/export');
        $response->assertOk()->assertDownload()->assertHeader('content-type', 'application/zip');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->postJson('/_editor/transfer/import')->assertUnprocessable()->assertJsonValidationErrors(['archive', 'confirm']);
            $this->withSession(['page-editor.manifests' => ['old' => ['version' => 1]]])->post('/_editor/transfer/import', [
                'confirm' => '1', 'archive' => new UploadedFile($path, 'cms.zip', 'application/zip', null, true),
            ], ['Accept' => 'application/json'])->assertOk()->assertJson(['imported' => true])->assertSessionMissing('page-editor.manifests');
            $this->assertSame(2, app(PageContentStore::class)->readScoped()['version']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function test_invalid_archive_is_a_validation_error_and_preserves_content(): void
    {
        $user = new User;
        $user->email = 'editor@example.com';
        $this->actingAs($user);
        app(PageContentStore::class)->change('page', 0, 'publish', ['title' => 'Keep'], 1);
        $this->post('/_editor/transfer/import', [
            'confirm' => '1', 'archive' => UploadedFile::fake()->createWithContent('bad.zip', 'not a zip'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->assertSame('Keep', app(PageContentStore::class)->read('page')['published']['title']);
    }
}
