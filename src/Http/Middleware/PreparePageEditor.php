<?php

namespace Digizu\PageEditor\Http\Middleware;

use Digizu\PageEditor\Services\PageEditorContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PreparePageEditor
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->remove('pageEditor');
        if ($request->isMethod('GET') && !$request->is(...config('page-editor.excluded_paths', []))) {
            $user = Auth::guard(config('page-editor.guard'))->user();
            $canEdit = $user && Gate::forUser($user)->allows('edit-page-content');
            $context = new PageEditorContext($request, $canEdit, $canEdit && $request->boolean('edit'));
            $request->attributes->set('pageEditor', $context);
        }
        $response = $next($request);
        if (isset($context) && $response instanceof \Illuminate\Http\Response && $response->isSuccessful() && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $response->setContent(app(\Digizu\PageEditor\Services\PageDocument::class)->process($response->getContent(), $context));
        }
        if (!empty($context->fields)) $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
