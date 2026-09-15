<?php

namespace Digizu\PageEditor;

use Digizu\PageEditor\Http\Middleware\PreparePageEditor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PageEditorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/page-editor.php', 'page-editor');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'page-editor');
        foreach (['cms', 'page-editor'] as $component) {
            Blade::component('page-editor::components.'.$component, $component);
        }
        Blade::prepareStringsForCompilationUsing(fn ($value) => \Digizu\PageEditor\Services\SourceScope::compile($value, Blade::getPath()));
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->callAfterResolving(Kernel::class, function ($kernel) {
            $kernel->appendMiddlewareToGroup('web', PreparePageEditor::class);
        });

        Gate::define('edit-page-content', function ($user) {
            $email = strtolower(trim((string) ($user->email ?? '')));
            $allowed = array_map(fn ($value) => strtolower(trim((string) $value)), config('page-editor.allowed_emails', []));
            return $email !== '' && in_array($email, $allowed, true);
        });

        $this->publishes([__DIR__.'/../config/page-editor.php' => config_path('page-editor.php')], 'page-editor-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/page-editor')], 'page-editor-views');
    }
}
