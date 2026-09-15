<?php

namespace Tests\Unit;

use Digizu\PageEditor\Services\PageContentStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PageContentStoreTest extends TestCase
{
    private string $directory;
    private Container $previousContainer;
    private PageContentStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->directory = sys_get_temp_dir().'/page-editor-store-test-'.bin2hex(random_bytes(8));
        $app = new Application($this->directory);
        $app->instance('config', new Repository(['page-editor' => ['path' => $this->directory]]));
        $this->store = new PageContentStore;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_missing_page_reads_do_not_create_storage_or_lock_files(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $state = $this->store->read('page-'.$i);
            $this->assertSame(0, $state['version']);
            $this->assertSame([], $state['published']);
        }
        $this->assertDirectoryDoesNotExist($this->directory);

        mkdir($this->directory);
        $this->store->read('_scoped');
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_reads_observe_new_publications_and_stale_writes_still_conflict(): void
    {
        $this->store->read('page');
        $this->store->change('page', 0, 'publish', ['heading' => 'First'], 1);
        $this->assertSame('First', $this->store->read('page')['published']['heading']);
        $this->store->change('page', 1, 'draft', ['heading' => 'Draft'], 1);
        $state = $this->store->read('page');
        $this->assertSame('First', $state['published']['heading']);
        $this->assertSame('Draft', $state['draft']['heading']);
        $this->assertCount(1, glob($this->directory.'/pages/*.lock'));

        try {
            $this->store->change('page', 1, 'publish', ['heading' => 'Stale'], 2);
            $this->fail('A stale write must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($state, $this->store->read('page'));
    }

    public function test_invalid_json_is_not_treated_as_missing_content(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/page.json', '{broken');
        $this->expectException(\JsonException::class);
        $this->store->read('page', 'page');
    }

    public function test_reads_reject_path_traversal(): void
    {
        try {
            $this->store->read('../outside');
            $this->fail('Invalid storage keys must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_reads_do_not_wait_for_a_writer_lock(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('Requires pcntl.');
        $this->store->change('page', 0, 'publish', ['heading' => 'Published'], 1);
        $lock = fopen($this->directory.'/pages/'.hash('sha256', 'page').'.lock', 'c');
        flock($lock, LOCK_EX);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === -1) throw new \RuntimeException('Unable to fork reader.');
        if ($pid === 0) {
            fclose($sockets[0]);
            fclose($lock);
            $state = $this->store->read('page');
            fwrite($sockets[1], $state['published']['heading']);
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        try {
            $read = [$sockets[0]];
            $write = $except = [];
            $this->assertSame(1, stream_select($read, $write, $except, 3), 'Reader blocked on the writer lock.');
            $this->assertSame('Published', fread($sockets[0], 1024));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            fclose($sockets[0]);
            pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_concurrent_reads_see_complete_atomic_publications(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('Requires pcntl.');
        $this->store->change('page', 0, 'publish', ['heading' => str_repeat('1', 16000)], 1);
        $pid = pcntl_fork();
        if ($pid === -1) throw new \RuntimeException('Unable to fork writer.');
        if ($pid === 0) {
            for ($version = 1; $version <= 40; $version++) {
                $this->store->change('page', $version, 'publish', ['heading' => str_repeat((string) (($version + 1) % 10), 16000)], 1);
                usleep(1000);
            }
            exit(0);
        }
        $reads = 0;
        try {
            do {
                $state = $this->store->read('page');
                $this->assertSame(str_repeat((string) ($state['version'] % 10), 16000), $state['published']['heading']);
                $this->assertSame($state['published'], $state['draft']);
                $reads++;
                $finished = pcntl_waitpid($pid, $status, WNOHANG);
            } while ($finished === 0);
        } finally {
            if (($finished ?? 0) === 0) pcntl_waitpid($pid, $status);
        }
        $this->assertGreaterThan(0, $reads);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(41, $this->store->read('page')['version']);
    }

    private function context(string $path): \Digizu\PageEditor\Services\PageEditorContext
    {
        $request = \Illuminate\Http\Request::create('https://example.test/'.$path.'?edit=1');
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        return new \Digizu\PageEditor\Services\PageEditorContext($request, true, true);
    }

    public function test_reserved_page_cannot_read_or_overwrite_the_shared_store(): void
    {
        $fields = ['heading' => ['storage' => 'shared-heading', 'default' => 'Default', 'format' => 'rich']];
        $this->store->changeScoped(0, 'publish', ['heading' => 'Other published'], $fields, [], 1);
        $this->store->changeScoped(1, 'draft', ['heading' => 'Other private draft'], $fields, [], 1);
        $shared = $this->store->readScoped();
        $this->assertSame([], $this->store->read('_scoped', '_scoped')['published']);

        $context = $this->context('_scoped');
        $context->text('seo_title', 'Page title', 'plain');
        $state = $context->bootstrap();
        $manifest = $context->request->session()->get('page-editor.manifests.'.$state['manifest']);
        $this->assertNull($manifest['legacy_page']);
        $this->assertStringNotContainsString('Other', json_encode($state));
        $result = $this->store->change($context->page, $state['version'], 'publish', ['seo_title' => 'Changed'], 1, $context->fields, $manifest['legacy_page']);
        $this->assertStringNotContainsString('Other', json_encode($result));
        $this->assertSame($shared, $this->store->readScoped());
        $this->assertSame('Changed', $this->store->read($context->page)['published']['seo_title']);
    }

    public function test_legacy_content_migrates_on_save_without_exposing_hidden_fields_or_resurrecting_overrides(): void
    {
        mkdir($this->directory);
        $legacy = [
            'version' => 4,
            'draft' => ['heading' => 'Draft', 'hidden' => 'Secret draft'],
            'published' => ['heading' => 'Published', 'hidden' => 'Secret published'],
            'history' => [
                ['version' => 4, 'action' => 'draft', 'content' => ['heading' => 'Earlier', 'hidden' => 'Secret history']],
                ['version' => 3, 'action' => 'draft', 'content' => ['hidden' => 'Secret only']],
            ],
            'fingerprints' => ['draft' => ['hidden' => 'Secret fingerprint']],
        ];
        file_put_contents($this->directory.'/about.json', json_encode($legacy));
        $context = $this->context('about');
        $this->assertSame('Draft', $context->text('heading', 'Default'));
        $state = $context->bootstrap();
        $this->assertSame('Published', $state['published']['heading']);
        $this->assertStringNotContainsString('Secret', json_encode($state));
        $this->assertCount(1, $state['history']);
        $this->assertDirectoryDoesNotExist($this->directory.'/pages');
        $manifest = $context->request->session()->get('page-editor.manifests.'.$state['manifest']);
        $this->assertSame('about', $manifest['legacy_page']);

        $result = $this->store->change($context->page, 4, 'draft', ['heading' => 'New draft'], 1, $context->fields, $manifest['legacy_page']);
        $this->assertStringNotContainsString('Secret', json_encode($result));
        $migrated = $this->store->read($context->page);
        $this->assertSame($legacy['published'], $migrated['published']);
        $this->assertSame('Secret draft', $migrated['draft']['hidden']);
        $this->assertSame($legacy['history'], array_slice($migrated['history'], 1));
        $this->assertSame($legacy, json_decode(file_get_contents($this->directory.'/about.json'), true));
        $this->store->change($context->page, 5, 'publish', [], 1, $context->fields, 'about');
        $fresh = $this->context('about');
        $this->assertSame('Default', $fresh->text('heading', 'Default'));
        $this->assertSame(['hidden' => 'Secret published'], $this->store->read($context->page)['published']);
    }

    public function test_literal_legacy_hash_aliases_do_not_share_page_identity_or_content(): void
    {
        $nested = $this->context('news/story');
        $alias = $this->context($nested->page);
        $this->assertNotSame($nested->page, $alias->page);
        mkdir($this->directory);
        file_put_contents($this->directory.'/'.$nested->page.'.json', json_encode([
            'version' => 1, 'draft' => ['heading' => 'Nested secret'], 'published' => [], 'history' => [],
        ]));
        $this->assertSame('Nested secret', $nested->text('heading', 'Default'));
        $this->assertSame('Default', $alias->text('heading', 'Default'));
        $this->assertNotSame($nested->fields['heading']['storage'], $alias->fields['heading']['storage']);
        $this->assertStringNotContainsString('Nested secret', json_encode($alias->bootstrap()));
    }

    public function test_existing_scoped_page_values_keep_their_identity_after_upgrade(): void
    {
        $storage = \Digizu\PageEditor\Services\SourceScope::key('page:about', 'seo_title');
        $fields = ['seo_title' => ['storage' => $storage, 'default' => 'Default', 'format' => 'plain']];
        $this->store->changeScoped(0, 'publish', ['seo_title' => 'Published SEO'], $fields, [], 1);
        $context = $this->context('about');
        $this->assertSame('Published SEO', $context->text('seo_title', 'Default', 'plain'));
        $this->assertSame($storage, $context->fields['seo_title']['storage']);
    }

}
