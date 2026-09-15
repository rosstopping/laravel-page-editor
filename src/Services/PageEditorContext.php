<?php

namespace Digizu\PageEditor\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageEditorContext
{
    public array $fields = [];
    private ?array $stored = null;
    private ?array $shared = null;
    private bool $scoped = false;
    public readonly string $page;
    private readonly ?string $legacyPage;

    public function __construct(public Request $request, public bool $canEdit, public bool $editing)
    {
        $path = $request->path();
        $this->page = 'page-'.hash('sha256', $path);
        // Do not treat internal names or literal legacy hash aliases as page data.
        $this->legacyPage = $path === '_scoped' || preg_match('/\Apage-[a-f0-9]{64}\z/', $path)
            ? null : (preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $path) ? $path : $this->page);
    }

    public function text(string $field, string $default, string $format = 'rich', ?string $scope = null): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,99}$/', $field)) throw new \InvalidArgumentException('Editable field names must use letters, numbers and underscores.');
        $key = $scope ? SourceScope::key($scope, $field) : $field;
        if (isset($this->fields[$key]) && $this->fields[$key]['default'] !== $default) throw new \InvalidArgumentException('Duplicate editable field with different defaults: '.$field);
        $this->scoped = $this->scoped || $scope !== null;
        // Keep existing shared-store identities for safe legacy page names.
        $this->fields[$key] = [
            'label' => Str::headline(preg_replace('/^home_/', '', $field)),
            'default' => $default, 'format' => $format, 'legacy' => $field,
            'shared' => SourceScope::isShared($scope),
            'storage' => SourceScope::key($scope ?? 'page:'.($this->legacyPage ?? $this->page), $field),
        ];
        $state = $this->state();
        return ($this->editing ? $state['draft'] : $state['published'])[$key] ?? $default;
    }

    public function state(): array
    {
        $legacy = $this->stored ??= app(PageContentStore::class)->read($this->page, $this->legacyPage);
        $shared = $this->shared ??= app(PageContentStore::class)->readScoped();
        // A page that was saved using scoped storage must keep reading its metadata there.
        $usesShared = $this->scoped;
        foreach ($this->fields as $field) $usesShared = $usesShared || isset($shared['managed'][$field['storage']]);
        if (!$usesShared) return $this->applyDefaults(PageContentStore::projectPage($legacy, $this->fields));
        $this->scoped = true;
        $state = PageContentStore::project($shared, $this->fields);
        foreach ($this->fields as $key => $field) {
            if (isset($shared['managed'][$field['storage']])) continue;
            foreach (['draft', 'published'] as $kind) {
                if (array_key_exists($field['legacy'], $legacy[$kind])) $state[$kind][$key] = $legacy[$kind][$field['legacy']];
                if (isset($legacy['fingerprints'][$kind][$field['legacy']])) $state['fingerprints'][$kind][$key] = $legacy['fingerprints'][$kind][$field['legacy']];
            }
        }
        return $this->applyDefaults($state);
    }

    private function applyDefaults(array $state): array
    {
        foreach ($this->fields as $key => &$field) {
            $field['updated_in_code'] = false;
            foreach (['draft', 'published'] as $kind) {
                // Untracked text-only edits can be retained when an existing field becomes a link.
                if ($field['format'] === 'link' && isset($state[$kind][$key]) && !isset($state['fingerprints'][$kind][$key]) && !is_array(json_decode($state[$kind][$key], true))) {
                    $link = json_decode($field['default'], true);
                    $text = html_entity_decode(strip_tags(FormattedText::render($state[$kind][$key])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (trim($text) !== '') $link['text'] = $text;
                    $state[$kind][$key] = LinkValue::normalize(json_encode($link));
                }
                if (!DefaultValue::outdated($state, $kind, $key, $field)) continue;
                unset($state[$kind][$key]);
                $field['updated_in_code'] = true;
            }
        }
        return $state;
    }

    public function bootstrap(): array
    {
        $state = $this->state();
        $defaults = array_map(fn ($field) => $field['default'], $this->fields);
        $state['draft'] = array_replace($defaults, array_intersect_key($state['draft'], $defaults));
        $state['defaults'] = $defaults;
        // A session-bound manifest authorises only fields actually rendered by the server.
        $token = Str::random(40);
        $manifests = $this->request->session()->get('page-editor.manifests', []);
        $manifests[$token] = ['storage_version' => 2, 'legacy_page' => $this->legacyPage, 'page' => $this->page, 'exit_url' => $this->request->fullUrlWithoutQuery(['edit']), 'fields' => $this->fields, 'scoped' => $this->scoped, 'published' => $state['published'], 'at' => time()];
        $this->request->session()->put('page-editor.manifests', array_slice($manifests, -20, null, true));
        $state['manifest'] = $token;
        return $state;
    }
}
