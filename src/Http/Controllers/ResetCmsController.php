<?php

namespace Digizu\PageEditor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ResetCmsController
{
    public function __invoke(Request $request)
    {
        abort_unless(app()->environment('local'), 404);
        $user = Auth::guard(config('page-editor.guard'))->user();
        abort_unless($user && Gate::forUser($user)->allows('edit-page-content'), 403);
        $request->validate(['confirm' => ['required', 'accepted']]);

        $disk = config('page-editor.images.disk', 'public');
        $images = trim(config('page-editor.images.directory', 'cms-images'), '/');
        // A local reset must never clear a remote bucket or an entire storage disk.
        abort_unless(config("filesystems.disks.{$disk}.driver") === 'local'
            && preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~', $images), 422, 'Reset requires a dedicated image directory on a local storage disk.');
        $directory = config('page-editor.path');
        $resolved = realpath($directory);
        abort_if($resolved && in_array($resolved, array_filter([realpath(base_path()), realpath(storage_path()), realpath(storage_path('app')), realpath(storage_path('app/public')), DIRECTORY_SEPARATOR]), true), 422, 'Reset requires a dedicated CMS content directory.');

        if (Storage::disk($disk)->directoryExists($images)) {
            abort_unless(Storage::disk($disk)->deleteDirectory($images), 500, 'Could not clear CMS image uploads.');
        }
        // Only CMS JSON documents; keep lock files so existing lock handles remain valid.
        foreach (File::glob($directory.'/*.json') as $path) {
            abort_unless(File::delete($path), 500, 'Could not clear CMS content.');
        }
        $request->session()->forget(['page-editor.manifests', 'page-editor.published']);

        return response()->json(['reset' => true])->header('Cache-Control', 'private, no-store');
    }
}
