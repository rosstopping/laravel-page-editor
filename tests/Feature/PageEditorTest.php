<?php

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Digizu\PageEditor\Services\PageContentStore;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PageEditorTest extends TestCase
{
    private string $directory;
    private string $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vvip-editor-'.bin2hex(random_bytes(8));
        config(['page-editor.path' => $this->directory, 'page-editor.excluded_paths' => [], 'page-editor.allowed_emails' => ['editor@example.com']]);
        $this->page = 'page-'.hash('sha256', 'admin/editor-fixture');
        $this->app['events']->forget('composing: *');
        Route::middleware('web')->get('/admin/editor-fixture', fn () => Blade::render(
            '<!doctype html><html><head><title>Original SEO</title><meta name="description" content="Original description"></head><body><h1><x-cms scope="page" field="title">Original heading</x-cms></h1></body></html>'
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function editor(): string
    {
        $user = new User;
        $user->id = 123;
        $user->email = 'EDITOR@example.com';
        $this->actingAs($user)->get('/admin/editor-fixture?edit=1')->assertOk()->assertSee('Revision history');
        return array_key_last(session('page-editor.manifests'));
    }

    public function test_changes_tab_receives_published_values_and_defaults_separately_from_drafts(): void
    {
        $this->editor();
        $html = $this->get('/admin/editor-fixture?edit=1')->assertOk()
            ->assertSee('Review changes')->assertSee('Unpublished changes')->assertSee('CMS overrides')
            ->assertSee('Highlight changed fields on the page')->getContent();
        preg_match('~<script type="application/json" id="cms-bootstrap">(.*?)</script>~s', $html, $matches);
        $bootstrap = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Original heading', $bootstrap['state']['defaults']['title']);
        $this->assertSame('Original heading', $bootstrap['state']['draft']['title']);
        $this->assertSame([], $bootstrap['state']['published']);
        $this->assertArrayHasKey('title', $bootstrap['fields']);
    }

    public function test_only_admins_with_a_rendered_manifest_can_save_known_fields(): void
    {
        $url = '/_editor/'.$this->page;
        $this->postJson($url, [])->assertForbidden();
        $user = new User; $user->id = 123; $user->master = true; $user->email = 'not-allowed@example.com';
        $this->actingAs($user)->postJson($url, [])->assertForbidden();
        $token = $this->editor();
        $this->postJson($url, ['version'=>0, 'action'=>'draft', 'content'=>['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'seo_title'=>'Original SEO', 'seo_description'=>'Original description', 'title'=>'New']])->assertForbidden();
        $this->postJson('/_editor/other', ['manifest'=>$token])->assertForbidden();
        $this->postJson($url, ['manifest'=>$token, 'version'=>0, 'action'=>'draft', 'content'=>['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'seo_title'=>'Original SEO', 'seo_description'=>'Original description', 'title'=>'New', 'unknown'=>'No']])->assertUnprocessable();
    }

    public function test_markup_defaults_draft_isolation_publish_conflicts_and_reset(): void
    {
        $store = app(PageContentStore::class);
        $this->get('/admin/editor-fixture?edit=1')->assertOk()->assertSee('Original heading')->assertDontSee('data-editable-field');
        $token = $this->editor();
        $url = '/_editor/'.$this->page;
        $payload = ['manifest'=>$token, 'version'=>0, 'action'=>'draft', 'content'=>['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'seo_title'=>'Original SEO', 'seo_description'=>'Original description', 'title'=>'Private draft']];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('version', 1);
        $this->assertSame([], $store->read($this->page)['published']);
        $this->get('/admin/editor-fixture')->assertSee('Original heading')->assertDontSee('Private draft');
        $this->get('/admin/editor-fixture?edit=1')->assertSee('Private draft');
        $this->postJson($url, $payload)->assertConflict();
        $payload['version']=1; $payload['action']='publish';
        $this->postJson($url, $payload)->assertOk();
        $this->get('/admin/editor-fixture')->assertSee('Private draft');
        $state=$store->read($this->page);
        $this->assertCount(3, $state['history']);
        $this->assertSame(123, $state['history'][0]['user_id']);
        $payload['version']=2; $payload['content']=['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'seo_title'=>'Original SEO', 'seo_description'=>'Original description', 'title'=>'Original heading'];
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame([], $store->read($this->page)['published']);
        $this->get('/admin/editor-fixture')->assertSee('Original heading');
    }

    public function test_published_overrides_are_escaped(): void
    {
        app(PageContentStore::class)->change($this->page, 0, 'publish', ['title'=>'<script>alert(1)</script>'], 123);
        $this->get('/admin/editor-fixture')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('data-editable-field');
    }
    public function test_empty_allowlist_denies_access_and_custom_guard_is_used(): void
    {
        config(['auth.guards.content-editor' => ['driver' => 'session', 'provider' => 'users'], 'page-editor.guard' => 'content-editor']);
        $user = new User; $user->id = 123; $user->email = 'editor@example.com';
        $this->actingAs($user, 'web')->get('/admin/editor-fixture?edit=1')->assertOk()->assertDontSee('data-editable-field');
        $this->postJson('/_editor/'.$this->page, [])->assertForbidden();
        $this->actingAs($user, 'content-editor')->get('/admin/editor-fixture?edit=1')->assertOk()->assertSee('data-editable-field');
        config(['page-editor.allowed_emails' => []]);
        $this->get('/admin/editor-fixture?edit=1')->assertOk()->assertDontSee('data-editable-field');
        $this->postJson('/_editor/'.$this->page, [])->assertForbidden();
    }

    public function test_basic_formatting_is_saved_and_rendered_without_attributes_or_executable_html(): void
    {
        $token = $this->editor();
        $this->postJson('/_editor/'.$this->page, [
            'manifest' => $token, 'version' => 0, 'action' => 'publish',
            'content' => ['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'seo_title' => 'Original SEO', 'seo_description' => 'Original description', 'title' => '__cms_html__:<strong onclick="alert(1)">Bold</strong> <em>Italic</em> <u>Underline</u><img src=x onerror=alert(1)>'],
        ])->assertOk();
        $html = $this->get('/admin/editor-fixture')->assertOk()->getContent();
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<em>Italic</em>', $html);
        $this->assertStringContainsString('<u>Underline</u>', $html);
        $this->assertStringNotContainsString('<strong onclick=', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_metadata_and_assets_work_without_a_scope_or_host_asset_imports(): void
    {
        $token = $this->editor();
        $this->postJson('/_editor/'.$this->page, [
            'manifest'=>$token, 'version'=>0, 'action'=>'publish',
            'content'=>['og_title'=>'', 'og_description'=>'', 'og_image'=>'', 'og_url'=>'', 'og_type'=>'', 'og_site_name'=>'', 'title'=>'Original heading', 'seo_title'=>'New SEO title', 'seo_description'=>'New "description"'],
        ])->assertOk();
        $html=$this->get('/admin/editor-fixture')->assertOk()->getContent();
        $this->assertStringContainsString('<title>New SEO title</title>', $html);
        $this->assertStringContainsString('content="New &quot;description&quot;"', $html);
        $this->get('/admin/editor-fixture?edit=1')->assertSee('cms-bootstrap')->assertSee('_editor/assets/editor.js', false)->assertSee('_editor/assets/editor.css', false);
        $this->get('/_editor/assets/editor.js')->assertOk()->assertHeader('Content-Type', 'application/javascript');
        $this->get('/_editor/assets/alpine.js')->assertOk();
        $this->get('/_editor/assets/not-allowed')->assertNotFound();
    }

    public function test_open_graph_fields_are_discovered_and_published_without_losing_repeated_images(): void
    {
        Route::middleware('web')->get('/admin/og-fixture', fn () => '<html><head><title>Title</title><meta property="og:title" content="Social"><meta property="og:image" content="one.jpg"><meta property="og:image" content="two.jpg"><meta property="og:image:alt" content="Alt"></head><body>Page</body></html>');
        $user = new User; $user->id = 123; $user->email = 'editor@example.com';
        $this->actingAs($user)->get('/admin/og-fixture?edit=1')->assertOk()->assertSee('SEO &amp; social', false);
        $manifests = session('page-editor.manifests');
        $token = array_key_last($manifests);
        $manifest = $manifests[$token];
        $this->assertArrayHasKey('og_image_2', $manifest['fields']);
        $this->assertArrayHasKey('og_image_alt', $manifest['fields']);
        $content = array_map(fn ($field) => $field['default'], $manifest['fields']);
        $content['og_title'] = 'New social title';
        $content['og_description'] = 'New social description';
        $content['og_image_2'] = 'changed.jpg';
        $this->postJson('/_editor/'.$manifest['page'], ['manifest'=>$token, 'version'=>0, 'action'=>'publish', 'content'=>$content])->assertOk();
        $html = $this->get('/admin/og-fixture')->assertOk()->getContent();
        $this->assertStringContainsString('content="New social title"', $html);
        $this->assertStringContainsString('property="og:description" content="New social description"', $html);
        $this->assertStringContainsString('content="one.jpg"', $html);
        $this->assertStringContainsString('content="changed.jpg"', $html);
    }

    public function test_source_scopes_share_drafts_and_publications_without_sharing_page_headings_or_seo(): void
    {
        app('view')->addNamespace('scope-test', __DIR__.'/../fixtures/views');
        foreach (['a', 'b'] as $name) Route::middleware('web')->get('/admin/scope-'.$name, fn () => view('scope-test::'.$name));
        $user = new User; $user->id = 123; $user->email = 'editor@example.com';
        $bootstrap = function ($url) {
            $html = $this->get($url.'?edit=1')->assertOk()->getContent();
            preg_match('/<script[^>]*id="cms-bootstrap"[^>]*>(.*?)<\/script>/s', $html, $match);
            return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        };
        $this->actingAs($user);
        $a = $bootstrap('/admin/scope-a');
        $shared = array_search('Newsletter default', $a['state']['defaults']);
        $heading = array_search('Heading A', $a['state']['defaults']);
        $this->assertNotFalse($shared);
        $this->assertTrue($a['fields'][$shared]['shared']);
        $this->assertFalse($a['fields'][$heading]['shared']);
        $this->assertFalse($a['fields']['seo_title']['shared']);
        $this->assertNotSame($heading, $shared);
        $payload = ['manifest' => $a['state']['manifest'], 'version' => $a['state']['version'], 'action' => 'draft',
            'content' => array_replace($a['state']['draft'], [$shared => 'Shared newsletter', $heading => 'Changed A', 'seo_title' => 'SEO A'])];
        $endpoint = '/_editor/page-'.hash('sha256', 'admin/scope-a');
        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('version', 1);
        $b = $bootstrap('/admin/scope-b');
        $this->assertSame('Shared newsletter', $b['state']['draft'][$shared]);
        $this->assertSame('Page B', $b['state']['draft']['seo_title']);
        $this->assertArrayNotHasKey($heading, $b['fields']);
        $this->assertStringNotContainsString('Changed A', json_encode($b));
        $this->get('/admin/scope-b')->assertSee('Newsletter default')->assertDontSee('Shared newsletter');
        $payload['version'] = 1; $payload['action'] = 'publish';
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/scope-b')->assertSee('Shared newsletter')->assertSee('Heading B')->assertSee('<title>Page B</title>', false)->assertDontSee('Changed A');
        $this->get('/admin/scope-a')->assertSee('Changed A')->assertSee('<title>SEO A</title>', false);
        $this->postJson('/_editor/page-'.hash('sha256', 'admin/scope-b'), ['manifest' => $b['state']['manifest'], 'version' => 1, 'action' => 'publish', 'content' => $b['state']['draft']])->assertConflict();
        $a = $bootstrap('/admin/scope-a');
        $payload = ['manifest' => $a['state']['manifest'], 'version' => $a['state']['version'], 'action' => 'publish', 'content' => $a['state']['defaults']];
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/scope-b')->assertSee('Newsletter default')->assertDontSee('Shared newsletter');
        $this->assertSame([], app(PageContentStore::class)->readScoped()['published']);
    }
    public function test_adopting_legacy_overrides_keeps_published_values_private_until_publish_and_reset_does_not_resurrect_them(): void
    {
        $store = app(PageContentStore::class);
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory.'/old-page.json', json_encode(['version' => 1, 'draft' => ['heading' => 'Legacy published'], 'published' => ['heading' => 'Legacy published'], 'history' => []]));
        $scope = 'view:resources/views/components/shared.blade.php';
        $request = \Illuminate\Http\Request::create('/old-page');
        $context = new \Digizu\PageEditor\Services\PageEditorContext($request, true, true);
        $this->assertSame('Legacy published', $context->text('heading', 'Default', 'rich', $scope));
        $key = array_key_first($context->fields);
        $state = $store->changeScoped(0, 'draft', [$key => 'Private draft'], $context->fields, $context->state()['published'], 123);
        $this->assertSame('Legacy published', $state['published'][$key]);
        $this->assertSame('Private draft', $state['draft'][$key]);
        $other = new \Digizu\PageEditor\Services\PageEditorContext(\Illuminate\Http\Request::create('/other-page'), false, false);
        $this->assertSame('Legacy published', $other->text('heading', 'Default', 'rich', $scope));
        $store->changeScoped(1, 'publish', [], $context->fields, [], 123);
        $reset = new \Digizu\PageEditor\Services\PageEditorContext($request, false, false);
        $this->assertSame('Default', $reset->text('heading', 'Default', 'rich', $scope));
        $this->assertSame(['heading' => 'Legacy published'], $store->read('page-'.hash('sha256', 'old-page'), 'old-page')['published']);
    }
    public function test_images_validate_uploads_and_keep_draft_replacements_private(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        Route::middleware('web')->get('/admin/image-fixture', fn () => Blade::render('<html><head><title>Images</title></head><body><x-cms type="image" scope="test-image" field="photo" src="/original.jpg" alt="Original alt" class="rounded-full" /></body></html>'));
        $page = 'page-'.hash('sha256', 'admin/image-fixture');
        $endpoint = '/_editor/'.$page;
        $this->post($endpoint.'/images')->assertForbidden();
        $this->getJson($endpoint.'/images')->assertForbidden();
        $user = new User; $user->id = 123; $user->email = 'editor@example.com';
        $html = $this->actingAs($user)->get('/admin/image-fixture?edit=1')->assertOk()->getContent();
        preg_match('/<script[^>]*id="cms-bootstrap"[^>]*>(.*?)<\/script>/s', $html, $match);
        $boot = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $key = array_key_first(array_filter($boot['fields'], fn ($field) => $field['format'] === 'image'));
        $this->assertStringContainsString('class="rounded-full"', $html);
        $upload = ['manifest' => $boot['state']['manifest'], 'field' => $key, 'image' => \Illuminate\Http\UploadedFile::fake()->image('photo.jpg')];
        $result = $this->postJson($endpoint.'/images', $upload)->assertOk()->json();
        $this->assertStringContainsString('/cms-images/', $result['src']);
        $this->assertTrue($boot['fields'][$key]['shared']);
        $query = ['manifest' => $boot['state']['manifest'], 'field' => $key];
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $disk->put('outside/private.jpg', 'not a CMS upload');
        $disk->put('cms-images/not-an-image.svg', '<svg/>');
        $this->getJson($endpoint.'/images?'.http_build_query($query))->assertOk()
            ->assertJsonCount(1, 'images')->assertJsonPath('images.0.src', $result['src'])
            ->assertJsonPath('has_more', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($endpoint.'/images?'.http_build_query(array_replace($query, ['field' => 'seo_title'])))->assertForbidden();
        $this->getJson('/_editor/wrong/images?'.http_build_query($query))->assertForbidden();
        for ($i = 0; $i < 25; $i++) $disk->put('cms-images/example-'.$i.'.jpg', 'fixture');
        $first = $this->getJson($endpoint.'/images?'.http_build_query($query))->assertOk()->assertJsonCount(24, 'images')->assertJsonPath('has_more', true)->json('images');
        $second = $this->getJson($endpoint.'/images?'.http_build_query($query + ['batch' => 2]))->assertOk()->assertJsonCount(2, 'images')->assertJsonPath('has_more', false)->json('images');
        $this->assertCount(26, array_unique(array_column(array_merge($first, $second), 'src')));

        $this->postJson($endpoint.'/images', array_replace($upload, ['field' => 'seo_title']))->assertForbidden();
        $this->postJson($endpoint.'/images', array_replace($upload, ['image' => \Illuminate\Http\UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]))->assertUnprocessable();
        $payload = ['manifest' => $boot['state']['manifest'], 'version' => 0, 'action' => 'draft', 'content' => $boot['state']['draft']];
        $payload['content'][$key] = json_encode(['src' => 'javascript:alert(1)', 'alt' => '']);
        $this->postJson($endpoint, $payload)->assertUnprocessable();
        $payload['content'][$key] = json_encode(['src' => $result['src'], 'alt' => 'New "alt"']);
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/image-fixture')->assertSee('/original.jpg')->assertDontSee($result['src'], false);
        $this->get('/admin/image-fixture?edit=1')->assertSee($result['src'], false);
        $payload['version'] = 1; $payload['action'] = 'publish';
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/image-fixture')->assertSee($result['src'], false)->assertSee('alt="New &quot;alt&quot;"', false)->assertDontSee('data-cms-image=');
        $payload['version'] = 2; $payload['content'] = $boot['state']['defaults'];
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/image-fixture')->assertSee('/original.jpg');
    }
    public function test_publish_returns_a_non_editing_url_preserving_other_query_parameters(): void
    {
        $this->editor();
        $this->get('/admin/editor-fixture?month=6&edit=1')->assertOk()->assertSee('Minimise editor');
        $token = array_key_last(session('page-editor.manifests'));
        $manifest = session('page-editor.manifests.'.$token);
        $defaults = array_map(fn ($field) => $field['default'], $manifest['fields']);
        $response = $this->postJson('/_editor/'.$this->page, [
            'manifest' => $token, 'version' => 0, 'action' => 'publish', 'content' => $defaults,
        ])->assertOk();
        $this->assertSame('/admin/editor-fixture', parse_url($response->json('redirect_url'), PHP_URL_PATH));
        $this->assertSame('month=6', parse_url($response->json('redirect_url'), PHP_URL_QUERY));
        $this->get($response->json('redirect_url'))->assertOk()->assertDontSee('cms-bootstrap')->assertSee('Published. Visitors now see this version.');
    }
    public function test_reset_is_local_only_and_clears_only_cms_content_and_uploads(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        \Illuminate\Support\Facades\Storage::fake('public');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $disk->put('cms-images/upload.jpg', 'cms upload');
        $disk->put('existing-site/photo.jpg', 'keep');
        $store = app(PageContentStore::class);
        $store->change('fixture', 0, 'publish', ['heading' => 'Edited'], 123);
        file_put_contents($this->directory.'/legacy.json', '{}');
        file_put_contents($this->directory.'/_scoped.json', '{}');
        file_put_contents($this->directory.'/keep.txt', 'keep');
        $this->app->instance('env', 'production');
        $this->postJson('/_editor/local/reset', ['confirm' => true])->assertNotFound();
        $this->assertFileExists($this->directory.'/pages/'.hash('sha256', 'fixture').'.json');
        $this->app->instance('env', 'local');
        $this->postJson('/_editor/local/reset', ['confirm' => true])->assertForbidden();
        $this->editor();
        $this->get('/admin/editor-fixture?edit=1')->assertSee('Reset CMS');
        $this->postJson('/_editor/local/reset', [])->assertUnprocessable();
        $this->postJson('/_editor/local/reset', ['confirm' => true])->assertOk()->assertJsonPath('reset', true);
        $this->assertFileDoesNotExist($this->directory.'/pages/'.hash('sha256', 'fixture').'.json');
        $this->assertFileDoesNotExist($this->directory.'/legacy.json');
        $this->assertFileDoesNotExist($this->directory.'/_scoped.json');
        $this->assertFileExists($this->directory.'/keep.txt');
        $disk->assertMissing('cms-images/upload.jpg');
        $disk->assertExists('existing-site/photo.jpg');
        $this->assertNull(session('page-editor.manifests'));
        $this->assertSame([], $store->read('fixture')['published']);
    }
    public function test_changed_markup_wins_for_page_and_shared_fields_without_writing_on_render(): void
    {
        foreach (['page', 'shared-code-test'] as $scope) {
            $path = '/admin/default-test-'.$scope;
            $page = 'page-'.hash('sha256', ltrim($path, '/'));
            $endpoint = '/_editor/'.$page;
            config(['editor-test.default' => 'Original default']);
            Route::middleware('web')->get($path, fn () => Blade::render(
                '<html><head><title>SEO default</title></head><body><h1><x-cms scope="'.$scope.'" field="headline">'.e(config('editor-test.default')).'</x-cms></h1><x-cms scope="'.$scope.'" field="other">Other default</x-cms></body></html>'
            ));
            $user = new User; $user->id = 123; $user->email = 'editor@example.com';
            $this->actingAs($user);
            $boot = function () use ($path) {
                $html = $this->get($path.'?edit=1')->assertOk()->getContent();
                preg_match('/<script[^>]*id="cms-bootstrap"[^>]*>(.*?)<\/script>/s', $html, $match);
                return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            };
            $initial = $boot();
            $key = array_search('Original default', $initial['state']['defaults']);
            $other = array_search('Other default', $initial['state']['defaults']);
            $payload = ['manifest' => $initial['state']['manifest'], 'version' => $initial['state']['version'], 'action' => 'publish',
                'content' => array_replace($initial['state']['draft'], [$key => 'Published CMS edit', $other => 'Keep this edit'])];
            $this->postJson($endpoint, $payload)->assertOk();
            $this->get($path)->assertSee('Published CMS edit');
            $editing = $boot();
            $payload['manifest'] = $editing['state']['manifest'];
            $payload['version'] = $editing['state']['version'];
            $payload['action'] = 'draft';
            $payload['content'][$key] = 'Private CMS draft';
            $this->postJson($endpoint, $payload)->assertOk();

            config(['editor-test.default' => 'New code default']);
            $file = $this->directory.'/'.($scope === 'page' ? 'pages/'.hash('sha256', $page) : '_scoped').'.json';
            $before = file_get_contents($file);
            $this->get($path)->assertSee('New code default')->assertDontSee('Published CMS edit')->assertSee('Keep this edit');
            $updated = $boot();
            $this->assertSame('New code default', $updated['state']['draft'][$key]);
            $this->assertTrue($updated['fields'][$key]['updated_in_code']);
            $this->assertFalse($updated['fields'][$other]['updated_in_code']);
            $this->assertSame($before, file_get_contents($file), 'Rendering must not rewrite content storage.');

            $this->postJson($endpoint, ['manifest' => $updated['state']['manifest'], 'version' => $updated['state']['version'],
                'action' => 'draft', 'content' => $updated['state']['draft']])->assertOk();
            $clean = json_decode(file_get_contents($file), true);
            $storageKey = $scope === 'page' ? $key : $updated['fields'][$key]['storage'];
            $this->assertArrayNotHasKey($storageKey, $clean['draft']);
            $this->assertArrayNotHasKey($storageKey, $clean['published']);
            $archived = array_filter($clean['history'], fn ($entry) => $entry['action'] === 'code-update');
            $this->assertContains('Published CMS edit', array_column(array_column($archived, 'content'), $storageKey));
            $this->assertContains('Private CMS draft', array_column(array_column($archived, 'content'), $storageKey));
            $otherStorage = $scope === 'page' ? $other : $updated['fields'][$other]['storage'];
            foreach ($archived as $entry) $this->assertSame('Keep this edit', $entry['content'][$otherStorage]);
            $this->assertFalse($boot()['fields'][$key]['updated_in_code']);
            $this->get($path)->assertSee('New code default')->assertSee('Keep this edit');

            // New editorial changes are based on the new default and remain valid.
            $fresh = $boot();
            $this->postJson($endpoint, ['manifest' => $fresh['state']['manifest'], 'version' => $fresh['state']['version'], 'action' => 'publish',
                'content' => array_replace($fresh['state']['draft'], [$key => 'Fresh CMS edit'])])->assertOk();
            config(['editor-test.default' => " New  code\n default "]);
            $this->get($path)->assertSee('Fresh CMS edit');
        }
    }

    public function test_fingerprints_preserve_legacy_overrides_and_detect_formatting_changes(): void
    {
        $field = ['default' => 'Original', 'format' => 'rich'];
        $store = app(PageContentStore::class);
        $store->change('legacy-fingerprint', 0, 'publish', ['title' => 'Existing CMS'], 123);
        $state = $store->read('legacy-fingerprint');
        $this->assertFalse(\Digizu\PageEditor\Services\DefaultValue::outdated($state, 'published', 'title', $field));
        $store->change('legacy-fingerprint', 1, 'draft', ['title' => 'Draft'], 123, ['title' => $field]);
        $state = $store->read('legacy-fingerprint');
        $this->assertSame('Existing CMS', $state['published']['title']);
        $this->assertTrue(\Digizu\PageEditor\Services\DefaultValue::outdated($state, 'published', 'title', ['default' => '__cms_html__:<strong>Original</strong>', 'format' => 'rich']));
        $this->assertSame(
            \Digizu\PageEditor\Services\DefaultValue::fingerprint(['default' => '__cms_html__:<b>Original</b>', 'format' => 'rich']),
            \Digizu\PageEditor\Services\DefaultValue::fingerprint(['default' => '__cms_html__:<strong>Original</strong>', 'format' => 'rich'])
        );
    }

    public function test_shared_indicator_distinguishes_reusable_views_from_page_storage(): void
    {
        foreach ([null, 'view:resources/views/static/home-content.blade.php', 'view:resources/views/pages/home.blade.php', 'view:resources/views/event/show.blade.php', 'view:package::pages/index.blade.php'] as $scope) {
            $this->assertFalse(\Digizu\PageEditor\Services\SourceScope::isShared($scope));
        }
        foreach (['named:newsletter', 'view:resources/views/components/newsletter.blade.php', 'view:resources/views/partials/footer.blade.php', 'view:resources/views/layouts/app.blade.php', 'view:package::includes/banner.blade.php', 'view:package::components/newsletter.blade.php'] as $scope) {
            $this->assertTrue(\Digizu\PageEditor\Services\SourceScope::isShared($scope));
        }
    }

    public function test_links_keep_markup_attributes_and_draft_publish_default_precedence(): void
    {
        config(['editor-test.href' => '/original']);
        Route::middleware('web')->get('/admin/link-fixture', fn () => Blade::render(
            '<html><head><title>Links</title></head><body><x-cms type="link" scope="page" field="cta" href="'.config('editor-test.href').'" class="original-button" target="_blank" rel="nofollow">Read reviews<x-slot:after><span class="arrow" aria-hidden="true">→</span></x-slot:after></x-cms></body></html>'
        ));
        $endpoint = '/_editor/page-'.hash('sha256', 'admin/link-fixture');
        $this->get('/admin/link-fixture')->assertOk()->assertSee('href="/original"', false)->assertSee('class="original-button"', false)
            ->assertSee('rel="nofollow noopener noreferrer"', false)->assertSee('class="arrow"', false)->assertDontSee('data-cms-link=');
        $user = new User; $user->id = 123; $user->email = 'editor@example.com';
        $html = $this->actingAs($user)->get('/admin/link-fixture?edit=1')->assertOk()->assertSee('data-cms-link="cta"', false)->getContent();
        preg_match('/<script[^>]*id="cms-bootstrap"[^>]*>(.*?)<\/script>/s', $html, $match);
        $boot = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('link', $boot['fields']['cta']['format']);
        $payload = ['manifest' => $boot['state']['manifest'], 'version' => $boot['state']['version'], 'action' => 'draft',
            'content' => array_replace($boot['state']['draft'], ['cta' => json_encode(['href' => 'javascript:alert(1)', 'text' => 'Bad'])])];
        $this->postJson($endpoint, $payload)->assertUnprocessable();
        $payload['content']['cta'] = json_encode(['href' => '/reviews?sort=latest#guests', 'text' => 'Guest stories <b>now</b>']);
        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('version', 1);
        $this->get('/admin/link-fixture')->assertSee('href="/original"', false)->assertDontSee('Guest stories');
        $payload['version'] = 1; $payload['action'] = 'publish';
        $this->postJson($endpoint, $payload)->assertOk();
        $this->get('/admin/link-fixture')->assertSee('href="/reviews?sort=latest#guests"', false)->assertSee('Guest stories &lt;b&gt;now&lt;/b&gt;', false)
            ->assertSee('class="arrow"', false)->assertSee('class="original-button"', false);
        config(['editor-test.href' => '/new-code-destination']);
        $this->get('/admin/link-fixture')->assertSee('href="/new-code-destination"', false)->assertSee('Read reviews')->assertDontSee('Guest stories');
    }

    public function test_link_url_allowlist_and_legacy_text_conversion(): void
    {
        foreach (['https://example.com/a?b=1#c', 'http://example.com', '/reviews', '#guests', '?sort=latest', 'mailto:hello@example.com?subject=Hello%20there', 'tel:+441234567890'] as $url) {
            $this->assertTrue(\Digizu\PageEditor\Services\LinkValue::safeUrl($url), $url);
        }
        foreach (['', 'javascript:alert(1)', 'data:text/html,test', '//example.com', '/\\example.com', "https://example.com\n", 'mailto:bad', 'mailto:hello@example.com?subject=x%0ABcc:other@example.com', 'tel:abc'] as $url) {
            $this->assertFalse(\Digizu\PageEditor\Services\LinkValue::safeUrl($url), $url);
        }
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory.'/legacy-link.json', json_encode(['version' => 1, 'draft' => ['cta' => 'Existing label'], 'published' => ['cta' => 'Existing label'], 'history' => []]));
        $context = new \Digizu\PageEditor\Services\PageEditorContext(\Illuminate\Http\Request::create('/legacy-link'), false, false);
        $value = $context->text('cta', json_encode(['href' => '/reviews', 'text' => 'Read reviews']), 'link');
        $this->assertSame(['href' => '/reviews', 'text' => 'Existing label'], json_decode($value, true));
    }

    public function test_reserved_page_publish_is_isolated_and_old_manifests_require_reload(): void
    {
        $store = app(PageContentStore::class);
        $fields = ['heading' => ['storage' => 'unrelated-heading', 'default' => 'Default', 'format' => 'rich']];
        $store->changeScoped(0, 'publish', ['heading' => 'Unrelated published'], $fields, [], 123);
        $store->changeScoped(1, 'draft', ['heading' => 'Unrelated private draft'], $fields, [], 123);
        $before = $store->readScoped();
        Route::middleware('web')->get('/_scoped', fn () => '<html><head><title>Reserved page</title></head><body>Page</body></html>');
        $this->editor();
        $html = $this->get('/_scoped?edit=1')->assertOk()->getContent();
        preg_match('/<script[^>]*id="cms-bootstrap"[^>]*>(.*?)<\/script>/s', $html, $match);
        $bootstrap = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Unrelated', json_encode($bootstrap));
        $token = $bootstrap['state']['manifest'];
        $manifest = session('page-editor.manifests.'.$token);
        $payload = ['manifest' => $token, 'version' => $bootstrap['state']['version'], 'action' => 'publish',
            'content' => array_replace($bootstrap['state']['draft'], ['seo_title' => 'New title'])];
        $this->postJson('/_editor/'.$manifest['page'], $payload)->assertOk()->assertDontSee('Unrelated');
        $this->assertSame($before, $store->readScoped());
        $this->get('/_scoped')->assertSee('<title>New title</title>', false);

        unset($manifest['storage_version']);
        session()->put('page-editor.manifests.'.$token, $manifest);
        $payload['version']++;
        $this->postJson('/_editor/'.$manifest['page'], $payload)->assertConflict();
        $this->assertSame($before, $store->readScoped());
    }

}
