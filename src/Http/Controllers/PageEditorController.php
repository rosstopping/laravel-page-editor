<?php

namespace Digizu\PageEditor\Http\Controllers;

use Digizu\PageEditor\Services\PageContentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PageEditorController extends \Illuminate\Routing\Controller
{
    public function __invoke(Request $request, string $page, PageContentStore $store)
    {
        $user = Auth::guard(config('page-editor.guard'))->user();
        abort_unless($user && Gate::forUser($user)->allows('edit-page-content'), 403);
        $token = $request->input('manifest');
        abort_unless(is_string($token) && preg_match('/^[a-zA-Z0-9]{40}$/', $token), 403);
        $manifest = $request->session()->get('page-editor.manifests.'.$token);
        abort_unless($manifest && $manifest['page'] === $page && $manifest['at'] >= time() - 7200, 403, 'Reload the editor to refresh your session.');
        foreach ($manifest['fields'] as $field) abort_unless(isset($field['storage']), 409, 'The editor was upgraded. Reload before saving again.');
        $defaults = array_map(fn ($field) => $field['default'], $manifest['fields']);
        $rules = [
            'version' => ['required', 'integer', 'min:0'],
            'action' => ['required', 'in:draft,publish'],
            'content' => ['required', 'array:'.implode(',', array_keys($defaults))],
        ];
        foreach ($defaults as $key => $value) $rules['content.'.$key] = ['present', 'nullable', 'string', 'max:16000'];
        $data = $request->validate($rules);
        $content = [];
        foreach ($data['content'] as $key => $value) {
            if (($manifest['fields'][$key]['format'] ?? '') === 'link') {
                $content[$key] = \Digizu\PageEditor\Services\LinkValue::normalize($value ?? '', 'content.'.$key);
                continue;
            }
            if (($manifest['fields'][$key]['format'] ?? '') === 'image') {
                $content[$key] = \Digizu\PageEditor\Services\ImageValue::normalize($value ?? '', 'content.'.$key);
                continue;
            }
            $content[$key] = ($manifest['fields'][$key]['format'] ?? 'rich') === 'plain'
                ? ($value ?? '') : \Digizu\PageEditor\Services\FormattedText::normalize($value ?? '');
        }
        foreach ($content as $key => $value) {
            if (in_array($manifest['fields'][$key]['format'] ?? '', ['image', 'link'], true)) continue;
            $text = html_entity_decode(strip_tags(\Digizu\PageEditor\Services\FormattedText::render($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (mb_strlen($text) > 2000) throw \Illuminate\Validation\ValidationException::withMessages(['content.'.$key => 'Text must not exceed 2000 characters.']);
        }
        $content = array_filter($content, fn ($value, $key) => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH);
        $state = ($manifest['scoped'] ?? false)
            ? $store->changeScoped($data['version'], $data['action'], $content, $manifest['fields'], $manifest['published'] ?? [], $user->getAuthIdentifier())
            : $store->change($page, $data['version'], $data['action'], $content, $user->getAuthIdentifier(), $manifest['fields']);
        if ($data['action'] === 'publish') $request->session()->flash('page-editor.published.'.$page, 'Published. Visitors now see this version.');
        if ($data['action'] === 'publish') $state['redirect_url'] = $manifest['exit_url'] ?? null;
        return response()->json($state)
            ->header('Cache-Control', 'private, no-store');
    }
}
