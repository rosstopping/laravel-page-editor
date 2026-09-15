<?php

namespace Digizu\PageEditor\Services;

use RuntimeException;

class PageContentStore
{
    public function read(string $page): array
    {
        return $this->locked($page, fn ($state) => $state);
    }

    public function change(string $page, int $version, string $action, array $content, int|string $userId, array $fields = []): array
    {
        return $this->locked($page, function ($state) use ($version, $action, $content, $userId, $fields) {
            abort_if($state['version'] !== $version, 409, 'Someone else saved this page. Reload before editing again.');
            $this->discardOutdated($state, $fields);
            $this->recordFingerprints($state, $fields, $action);
            $state['draft'] = $content;
            if ($action === 'publish') $state['published'] = $content;
            $state['version']++;
            array_unshift($state['history'], [
                'version' => $state['version'], 'action' => $action,
                'at' => now()->toIso8601String(), 'user_id' => $userId, 'content' => $content,
            ]);
            $state['history'] = array_slice($state['history'], 0, 50);
            return $state;
        }, true);
    }

    /** One transaction for all fields in a page, including shared components and SEO. */
    public function changeScoped(int $version, string $action, array $content, array $fields, array $published, int|string $userId): array
    {
        $state = $this->locked('_scoped', function ($state) use ($version, $action, $content, $fields, $published, $userId) {
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
        }, true);
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
            $entry['content'] = $select($entry['content']);
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

    private function locked(string $page, callable $callback, bool $write = false): array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $page), 404);
        $directory = config('page-editor.path');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create page content directory.');
        }
        $path = $directory.'/'.$page.'.json';
        $lock = fopen($directory.'/'.$page.'.lock', 'c');
        if (!$lock) throw new RuntimeException('Unable to lock page content.');
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Unable to lock page content.');
            $state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [
                'version' => 0, 'draft' => [], 'published' => [],
                'history' => [['version' => 0, 'action' => 'original', 'at' => null, 'user_id' => null, 'content' => []]],
            ];
            $result = $callback($state);
            if ($write) {
                $temporary = tempnam($directory, '.content-');
                try {
                    if (file_put_contents($temporary, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) {
                        throw new RuntimeException('Unable to save page content.');
                    }
                } finally {
                    if (is_file($temporary)) unlink($temporary);
                }
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
