<?php

namespace Digizu\PageEditor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ImageLibraryController
{
    public function __invoke(Request $request, string $page)
    {
        $user = Auth::guard(config('page-editor.guard'))->user();
        abort_unless($user && Gate::forUser($user)->allows('edit-page-content'), 403);
        $data = $request->validate([
            'manifest' => ['required', 'string', 'regex:/^[a-zA-Z0-9]{40}$/'],
            'field' => ['required', 'string', 'max:100'],
            'batch' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $manifest = $request->session()->get('page-editor.manifests.'.$data['manifest']);
        abort_unless($manifest && $manifest['page'] === $page && $manifest['at'] >= time() - 7200
            && ($manifest['fields'][$data['field']]['format'] ?? '') === 'image', 403, 'Reload the editor before browsing images.');

        $directory = trim(config('page-editor.images.directory', 'cms-images'), '/');
        abort_if($directory === '' || in_array('..', explode('/', $directory), true), 500, 'Configure a dedicated CMS image directory.');
        $disk = Storage::disk(config('page-editor.images.disk', 'public'));
        $files = collect($disk->allFiles($directory))
            ->filter(fn ($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true))
            ->map(fn ($path) => ['path' => $path, 'modified' => $disk->lastModified($path)])
            ->sort(fn ($a, $b) => ($b['modified'] <=> $a['modified']) ?: strcmp($a['path'], $b['path']))->values();
        $offset = (($data['batch'] ?? 1) - 1) * 24;
        $images = $files->slice($offset, 24)->map(fn ($file) => [
            'src' => $disk->url($file['path']),
            'name' => basename($file['path']),
            'uploaded_at' => gmdate('c', $file['modified']),
        ])->values();

        return response()->json(['images' => $images, 'has_more' => $files->count() > $offset + 24])
            ->header('Cache-Control', 'private, no-store');
    }
}
