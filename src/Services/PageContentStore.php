<?php

namespace Digizu\PageEditor\Services;

use RuntimeException;

class PageContentStore
{
    public function read(string $page, ?string $legacyPage = null): array
    {
        $path = $this->pagePath($page);
        clearstatcache(true, $path);
        if (!is_file($path) && $legacyPage !== null && $legacyPage !== '_scoped') {
            $this->validatePage($legacyPage);
            $path = config('page-editor.path').'/'.$legacyPage.'.json';
        }
        return $this->readFile($path);
    }

    public function readScoped(): array
    {
        return $this->readFile(config('page-editor.path').'/_scoped.json');
    }

    private function validatePage(string $page): void
    {
        abort_unless(preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $page), 404);
    }

    private function pagePath(string $page): string
    {
        $this->validatePage($page);
        return config('page-editor.path').'/pages/'.hash('sha256', $page).'.json';
    }

    private function readFile(string $path): array
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return [
                'version' => 0, 'draft' => [], 'published' => [],
                'history' => [['version' => 0, 'action' => 'original', 'at' => null, 'user_id' => null, 'content' => []]],
            ];
        }

        // Writers atomically replace the JSON file, so an open reader sees one
        // complete snapshot without creating directories or acquiring a lock.
        $json = file_get_contents($path);
        if ($json === false) throw new RuntimeException('Unable to read page content.');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function change(string $page, int $version, string $action, array $content, int|string $userId, array $fields = [], ?string $legacyPage = null): array
    {
        $state = $this->locked($this->pagePath($page), fn () => $this->read($page, $legacyPage), function ($state) use ($version, $action, $content, $userId, $fields) {
            abort_if($state['version'] !== $version, 409, 'Someone else saved this page. Reload before editing again.');
            $this->discardOutdated($state, $fields);
            $this->recordFingerprints($state, $fields, $action);
            foreach ($action === 'publish' ? ['draft', 'published'] : ['draft'] as $kind) {
                $state[$kind] = $fields ? array_replace(array_diff_key($state[$kind], $fields), $content) : $content;
            }
            $state['version']++;
            array_unshift($state['history'], [
                'version' => $state['version'], 'action' => $action,
                'at' => now()->toIso8601String(), 'user_id' => $userId, 'content' => $state['draft'],
                'changed' => array_keys($fields ?: $content),
            ]);
            $state['history'] = array_slice($state['history'], 0, 50);
            return $state;
        });
        return $fields ? self::projectPage($state, $fields) : $state;
    }

    /** One transaction for all fields in a page, including shared components and SEO. */
    public function changeScoped(int $version, string $action, array $content, array $fields, array $published, int|string $userId): array
    {
        $state = $this->locked(config('page-editor.path').'/_scoped.json', fn () => $this->readScoped(), function ($state) use ($version, $action, $content, $fields, $published, $userId) {
            abort_if($state['version'] !== $version, 409, 'Content changed in another editor. Reload before editing again.');
            $storageFields = [];
            foreach ($fields as $field) $storageFields[$field['storage']] = $field;
            $this->discardOutdated($state, $storageFields);
            $this->recordFingerprints($state, $storageFields, $action);
            foreach ($fields as $key => $field) {
                $storage = $field['storage'];
                // Adopt legacy published values without publishing a new draft.
                if (!isset($state['managed'][$storage]) && array_key_exists($key, $published)) $state['published'][$storage] = $published[$key];
                $state['managed'][$storage] = true;
                unset($state['draft'][$storage]);
                if (array_key_exists($key, $content)) $state['draft'][$storage] = $content[$key];
                if ($action === 'publish') {
                    unset($state['published'][$storage]);
                    if (array_key_exists($key, $content)) $state['published'][$storage] = $content[$key];
                }
            }
            $state['version']++;
            array_unshift($state['history'], [
                'version' => $state['version'], 'action' => $action,
                'at' => now()->toIso8601String(), 'user_id' => $userId,
                'content' => $state['draft'], 'changed' => array_column($fields, 'storage'),
            ]);
            $state['history'] = array_slice($state['history'], 0, 50);
            return $state;
        });
        return self::project($state, $fields);
    }

    public static function projectPage(array $state, array $fields): array
    {
        foreach ($fields as $key => &$field) $field['storage'] = $key;
        unset($field);
        return self::project($state, $fields);
    }

    /** Never send other pages' private fields or history to the browser. */
    public static function project(array $state, array $fields): array
    {
        $select = function (array $content) use ($fields) {
            $result = [];
            foreach ($fields as $key => $field) {
                if (array_key_exists($field['storage'], $content)) $result[$key] = $content[$field['storage']];
            }
            return $result;
        };
        $history = [];
        foreach ($state['history'] as $entry) {
            if (isset($entry['changed']) && !array_intersect($entry['changed'], array_column($fields, 'storage'))) continue;
            $content = $select($entry['content']);
            if ($entry['content'] && !$content) continue;
            $entry['content'] = $content;
            unset($entry['changed']);
            $history[] = $entry;
        }
        return ['version' => $state['version'], 'draft' => $select($state['draft']), 'published' => $select($state['published']), 'history' => $history, 'fingerprints' => ['draft' => $select($state['fingerprints']['draft'] ?? []), 'published' => $select($state['fingerprints']['published'] ?? [])]];
    }

    /** Cleanup only happens inside a save transaction, never during rendering. */
    private function discardOutdated(array &$state, array $fields): void
    {
        foreach (['draft', 'published'] as $kind) {
            $outdated = [];
            $previous = $state[$kind];
            foreach ($fields as $key => $field) {
                if (!DefaultValue::outdated($state, $kind, $key, $field)) continue;
                $outdated[$key] = $state[$kind][$key];
                unset($state[$kind][$key], $state['fingerprints'][$kind][$key]);
            }
            if ($outdated) {
                // Preserve even legacy overrides that had no corresponding revision.
                array_unshift($state['history'], [
                    'version' => 'code-'.$state['version'].'-'.$kind, 'action' => 'code-update',
                    'at' => now()->toIso8601String(), 'user_id' => null,
                    'content' => $previous, 'changed' => array_keys($outdated),
                ]);
            }
        }
    }

    private function recordFingerprints(array &$state, array $fields, string $action): void
    {
        foreach ($fields as $key => $field) {
            $fingerprint = DefaultValue::fingerprint($field);
            $state['fingerprints']['draft'][$key] = $fingerprint;
            // A draft save must never revalidate an old published override.
            if ($action === 'publish' || !isset($state['fingerprints']['published'][$key])) {
                $state['fingerprints']['published'][$key] = $fingerprint;
            }
        }
    }

    /** Serialize transfers with every content writer; retain this lock file across imports. */
    public function transaction(callable $callback): mixed
    {
        $directory = config('page-editor.path');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create page content directory.');
        }
        $lock = fopen($directory.'/.store.lock', 'c');
        if (!$lock) throw new RuntimeException('Unable to lock CMS storage.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock CMS storage.');
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function locked(string $path, callable $read, callable $callback): array
    {
        return $this->transaction(fn () => $this->writeLocked($path, $read, $callback));
    }

    private function writeLocked(string $path, callable $read, callable $callback): array
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create page content directory.');
        }
        $lock = fopen(substr($path, 0, -5).'.lock', 'c');
        if (!$lock) throw new RuntimeException('Unable to lock page content.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock page content.');
            $result = $callback($read());
            $temporary = tempnam($directory, '.content-');
            try {
                if (file_put_contents($temporary, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) {
                    throw new RuntimeException('Unable to save page content.');
                }
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
