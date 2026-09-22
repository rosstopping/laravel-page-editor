<?php

namespace Tests\Unit;

use Digizu\PageEditor\Services\ContentTransfer;
use Digizu\PageEditor\Services\PageContentStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ContentTransferTest extends TestCase
{
    private string $directory;
    private Container $previous;
    private mixed $previousFacade;
    private ContentTransfer $transfer;
    private PageContentStore $store;
    private array $archives = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->directory = sys_get_temp_dir().'/cms-transfer-test-'.bin2hex(random_bytes(8));
        $app = new Application($this->directory);
        $app->instance('config', new Repository([
            'page-editor' => ['path' => $this->directory.'/content', 'images' => ['disk' => 'cms', 'directory' => 'uploads']],
            'filesystems' => ['disks' => ['cms' => ['driver' => 'local', 'root' => $this->directory.'/images', 'url' => 'https://production.test/storage']]],
        ]));
        $app->instance('filesystem', new FilesystemManager($app));
        $app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->store = new PageContentStore;
        $this->transfer = new ContentTransfer($this->store);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) if (is_file($path)) unlink($path);
        (new Filesystem)->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previous);
    }

    private function export(): string
    {
        return $this->archives[] = $this->transfer->export();
    }

    private function archive(array $documents, array $images = [], array $files = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-test-');
        $this->archives[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['format' => 'digizu-page-editor', 'version' => 1, 'documents' => $documents, 'images' => $images]));
        foreach ($files as $name => $data) $zip->addFromString($name, $data);
        $zip->close();
        return $path;
    }

    private function document(): array
    {
        return ['version' => 1, 'draft' => ['heading' => 'Draft'], 'published' => ['heading' => 'Published'], 'history' => []];
    }

    public function test_round_trip_preserves_drafts_history_and_remaps_images_including_history(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZ8AAAAASUVORK5CYII=');
        Storage::disk('cms')->put('uploads/hero.png', $png);
        $image = json_encode(['src' => Storage::disk('cms')->url('uploads/hero.png'), 'alt' => 'Hero']);
        $fields = ['hero' => ['storage' => 'cms_hero', 'default' => $image, 'format' => 'image']];
        $this->store->changeScoped(0, 'publish', ['hero' => $image], $fields, [], 1);
        $this->store->changeScoped(1, 'draft', ['hero' => $image], $fields, [], 1);
        $before = $this->store->readScoped();
        $archive = $this->export();
        config(['page-editor.path' => $this->directory.'/local', 'filesystems.disks.cms.url' => 'http://local.test/storage']);
        app('filesystem')->forgetDisk('cms');
        $this->transfer->import($archive);
        $after = $this->store->readScoped();
        $this->assertSame(3, $after['version']);
        $this->assertSame($before['fingerprints'], $after['fingerprints']);
        $this->assertSame($before['managed'], $after['managed']);
        $this->assertCount(count($before['history']), $after['history']);
        $newImage = json_decode($after['draft']['cms_hero'], true);
        $this->assertStringStartsWith('http://local.test/storage/uploads/', $newImage['src']);
        $this->assertSame('Hero', $newImage['alt']);
        $this->assertSame($after['draft'], $after['published']);
        $this->assertSame($after['draft'], $after['history'][0]['content']);
        $this->assertSame($png, Storage::disk('cms')->get('uploads/'.basename($newImage['src'])));
        $this->assertTrue(Storage::disk('cms')->exists('uploads/hero.png'));
    }

    public function test_import_replaces_destination_content_and_invalidates_old_editor_versions(): void
    {
        $this->store->change('old', 0, 'publish', ['heading' => 'Old'], 1);
        $this->store->changeScoped(0, 'publish', [], [], [], 1);
        $archive = $this->archive(['_scoped.json' => $this->document()]);
        $this->transfer->import($archive);
        $this->assertSame([], $this->store->read('old')['published']);
        $this->assertSame(2, $this->store->read('old')['version']);
        $this->assertSame('Published', $this->store->readScoped()['published']['heading']);
        try {
            $this->store->changeScoped(1, 'draft', [], [], [], 1);
            $this->fail('Stale editor must conflict.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_legacy_documents_round_trip_and_lock_files_are_excluded(): void
    {
        $this->store->change('page', 0, 'draft', ['heading' => 'Modern'], 1);
        file_put_contents($this->directory.'/content/legacy.json', json_encode($this->document()));
        $archive = $this->export();
        $zip = new ZipArchive;
        $zip->open($archive);
        $this->assertSame(1, $zip->numFiles);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        $this->assertCount(2, $manifest['documents']);
        config(['page-editor.path' => $this->directory.'/other']);
        $this->transfer->import($archive);
        $this->assertSame('Published', $this->store->read('legacy', 'legacy')['published']['heading']);
        $this->assertSame('Modern', $this->store->read('page')['draft']['heading']);
    }

    public function test_invalid_archives_do_not_change_content_or_images(): void
    {
        $this->store->change('page', 0, 'publish', ['heading' => 'Keep'], 1);
        $before = $this->store->read('page');
        $cases = [
            $this->archive(['../outside.json' => $this->document()]),
            $this->archive(['_scoped.json' => ['version' => 2]]),
            $this->archive([], [], ['../escape.php' => 'bad']),
            $this->archive([], ['images/0.png' => '/image.png'], ['images/0.png' => '<?php bad']),
            $this->archive([], [], ['images/0.png' => 'unlisted']),
        ];
        foreach ($cases as $archive) {
            try { $this->transfer->import($archive); $this->fail('Invalid archive accepted.'); }
            catch (ValidationException) {}
            $this->assertSame($before, $this->store->read('page'));
            $this->assertSame([], Storage::disk('cms')->allFiles('uploads'));
        }
    }

    public function test_failed_image_write_cleans_up_new_uploads_and_keeps_content(): void
    {
        $this->store->change('page', 0, 'publish', ['heading' => 'Keep'], 1);
        $before = $this->store->read('page');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZ8AAAAASUVORK5CYII=');
        $archive = $this->archive(['_scoped.json' => $this->document()],
            ['images/0.png' => '/one.png', 'images/1.png' => '/two.png'],
            ['images/0.png' => $png, 'images/1.png' => $png]);
        $actual = Storage::disk('cms');
        $disk = $this->createMock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $writes = 0;
        $disk->method('put')->willReturnCallback(function ($path, $data, $options) use ($actual, &$writes) {
            return ++$writes === 1 ? $actual->put($path, $data, $options) : false;
        });
        $disk->method('url')->willReturnCallback(fn ($path) => $actual->url($path));
        $disk->expects($this->exactly(2))->method('delete')->willReturnCallback(fn ($path) => $actual->delete($path));
        Storage::swap(new class($disk) {
            public function __construct(private $disk) {}
            public function disk($name) { return $this->disk; }
        });
        try {
            $this->transfer->import($archive);
            $this->fail('Image failure must abort import.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unable to import CMS image.', $e->getMessage());
        }
        $this->assertSame($before, $this->store->read('page'));
        $this->assertSame([], $actual->allFiles('uploads'));
    }

    public function test_uncompressed_size_limit_is_checked_before_changes(): void
    {
        config(['page-editor.transfer.max_uncompressed_kb' => 0]);
        $this->expectException(ValidationException::class);
        $this->transfer->import($this->archive(['_scoped.json' => $this->document()]));
    }
}
