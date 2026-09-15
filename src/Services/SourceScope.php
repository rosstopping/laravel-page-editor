<?php

namespace Digizu\PageEditor\Services;

class SourceScope
{
    public static function identity(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', base_path()).'/';
        if (str_starts_with($path, $root)) return substr($path, strlen($root));
        // Namespaced package views may live outside the application's directory.
        foreach (app('view')->getFinder()->getHints() as $namespace => $paths) {
            foreach ($paths as $directory) {
                $prefix = str_replace('\\', '/', $directory).'/';
                if (str_starts_with($path, $prefix)) return $namespace.'::'.substr($path, strlen($prefix));
            }
        }
        throw new \LogicException('CMS views outside the application must use a view namespace.');
    }

    public static function compile(string $blade, ?string $path): string
    {
        if (!$path) return $blade; // Inline Blade has no file; retain page scope.
        $source = htmlspecialchars(self::identity($path), ENT_QUOTES, 'UTF-8');
        return preg_replace_callback('~@verbatim\b.*?@endverbatim(*SKIP)(*F)|\{\{--.*?--\}\}(*SKIP)(*F)|<!--.*?-->(*SKIP)(*F)|<(script|style)\b[^>]*>.*?</\1>(*SKIP)(*F)|<x-cms(?=[\s/>])~is', fn () => '<x-cms source="'.$source.'"', $blade);
    }

    public static function key(string $scope, string $field): string
    {
        return 'cms_'.hash('sha256', $scope."\0".$field);
    }

    /** Source-based storage alone does not mean a field belongs to a shared component. */
    public static function isShared(?string $scope): bool
    {
        if ($scope === null) return false;
        if (str_starts_with($scope, 'named:')) return true;
        if (!str_starts_with($scope, 'view:')) return false;

        $source = str_replace('\\', '/', substr($scope, 5));
        return (bool) preg_match('~(?:^|/|::)(?:components|partials|layouts|includes)/~', $source);
    }
}
