<?php

namespace Digizu\PageEditor\Http\Controllers;

use Digizu\PageEditor\Services\ContentTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ContentTransferController
{
    public function export(ContentTransfer $transfer)
    {
        $this->authorize();
        return response()->download($transfer->export(), 'cms-export-'.now()->format('Y-m-d-His').'.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request, ContentTransfer $transfer)
    {
        $this->authorize();
        $request->validate([
            'confirm' => ['required', 'accepted'],
            'archive' => ['required', 'file', 'max:'.config('page-editor.transfer.max_upload_kb', 102400)],
        ]);
        $transfer->import($request->file('archive')->getRealPath());
        $request->session()->forget(['page-editor.manifests', 'page-editor.published']);
        return response()->json(['imported' => true])->header('Cache-Control', 'private, no-store');
    }

    private function authorize(): void
    {
        $user = Auth::guard(config('page-editor.guard'))->user();
        abort_unless($user && Gate::forUser($user)->allows('edit-page-content'), 403);
    }
}
