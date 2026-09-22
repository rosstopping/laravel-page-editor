<?php
namespace Digizu\PageEditor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ImageUploadController
{
    public function __invoke(Request $request, string $page)
    {
        $user = Auth::guard(config('page-editor.guard'))->user();
        abort_unless($user && Gate::forUser($user)->allows('edit-page-content'), 403);
        $data = $request->validate([
            'manifest' => ['required', 'string', 'regex:/^[a-zA-Z0-9]{40}$/'],
            'field' => ['required', 'string', 'max:100'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.config('page-editor.images.max_kb', 10240), 'dimensions:max_width=12000,max_height=12000'],
        ]);
        $manifest = $request->session()->get('page-editor.manifests.'.$data['manifest']);
        abort_unless($manifest && $manifest['page'] === $page && $manifest['at'] >= time() - 7200
            && ($manifest['fields'][$data['field']]['format'] ?? '') === 'image', 403, 'Reload the editor before uploading.');
        $disk = config('page-editor.images.disk', 'public');
        $path = app(\Digizu\PageEditor\Services\PageContentStore::class)->transaction(
            fn () => $request->file('image')->storePublicly(config('page-editor.images.directory', 'cms-images'), $disk)
        );
        abort_unless($path, 500, 'Unable to store image.');
        return response()->json(['src' => Storage::disk($disk)->url($path)])->header('Cache-Control', 'private, no-store');
    }
}
