<?php

namespace Digizu\PageEditor\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class ContentTransfer
{
    public function __construct(private PageContentStore $store) {}

    public function export(): string
    {
        return $this->store->transaction(fn () => $this->buildArchive());
    }

    private function documents(): array
    {
        $root = rtrim(config('page-editor.path'), '/');
        if (is_link($root.'/pages')) throw new RuntimeException('CMS content cannot contain symbolic links.');
        $documents = [];
        foreach (array_merge(glob($root.'/*.json'), glob($root.'/pages/*.json')) as $path) {
            $name = substr($path, strlen($root) + 1);
            $this->validateName($name);
            if (is_link($path)) throw new RuntimeException('CMS content cannot contain symbolic links.');
            $json = file_get_contents($path);
            if ($json === false) throw new RuntimeException('Unable to read CMS content.');
            $documents[$name] = $this->validateDocument(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        }
        return $documents;
    }

    private function imageDirectory(): string
    {
        $directory = trim(config('page-editor.images.directory', 'cms-images'), '/');
        if (!preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~', $directory)) {
            throw new RuntimeException('Transfers require a dedicated CMS image directory.');
        }
        return $directory;
    }

    private function buildArchive(): string
    {
        $this->requireZip();
        $path = tempnam(sys_get_temp_dir(), 'cms-export-');
        if ($path === false) throw new RuntimeException('Unable to create export.');
        $zip = new ZipArchive;
        $temporaryImages = [];
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create export.');
            $opened = true;
            $documents = $this->documents();
            if (count($documents) > 10000) $this->invalid('There are too many CMS documents to export.');
            $disk = Storage::disk(config('page-editor.images.disk', 'public'));
            $images = [];
            $bytes = 0;
            foreach ($disk->allFiles($this->imageDirectory()) as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
                $size = $disk->size($file);
                if ($size > config('page-editor.images.max_kb', 10240) * 1024) $this->invalid('An image exceeds the configured upload limit.');
                $bytes += $size;
                $this->checkLimits(count($images) + count($documents) + 1, $bytes);
                $data = $disk->get($file);
                if (!is_string($data)) throw new RuntimeException('Unable to read CMS image.');
                $entry = 'images/'.count($images).'.'.$extension;
                $temporary = tempnam(sys_get_temp_dir(), 'cms-image-');
                if ($temporary === false) throw new RuntimeException('Unable to stage CMS image.');
                $temporaryImages[] = $temporary;
                if (file_put_contents($temporary, $data) === false || !$zip->addFile($temporary, $entry)) throw new RuntimeException('Unable to export CMS image.');
                $images[$entry] = $disk->url($file);
            }
            $manifest = json_encode([
                'format' => 'digizu-page-editor', 'version' => 1,
                'created_at' => now()->toIso8601String(),
                'documents' => $documents, 'images' => $images,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($manifest) > 16 * 1024 * 1024) $this->invalid('The CMS manifest exceeds the 16 MB limit.');
            $this->checkLimits(count($images) + 1, $bytes + strlen($manifest));
            if (!$zip->addFromString('manifest.json', $manifest) || !$zip->close()) throw new RuntimeException('Unable to finish export.');
            $opened = false;
            return $path;
        } catch (\Throwable $e) {
            if ($opened) {
                try { $zip->close(); } catch (\Throwable) {}
            }
            unlink($path);
            throw $e;
        } finally {
            foreach ($temporaryImages as $temporary) unlink($temporary);
        }
    }

    /** Validate everything before writing. Existing uploads are retained for older links. */
    public function import(string $archive): void
    {
        $this->requireZip();
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) $this->invalid('Choose a valid CMS export ZIP.');
        try {
            $names = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!$entry || isset($names[$entry['name']]) || !preg_match('~\A(?:manifest\.json|images/[0-9]+\.(?:jpg|jpeg|png|gif|webp))\z~', $entry['name'])) {
                    $this->invalid('The archive contains unexpected or duplicate files.');
                }
                if ($entry['name'] === 'manifest.json' && $entry['size'] > 16 * 1024 * 1024) $this->invalid('The CMS manifest exceeds the 16 MB limit.');
                if ($entry['name'] !== 'manifest.json' && $entry['size'] > config('page-editor.images.max_kb', 10240) * 1024) $this->invalid('An image exceeds the configured upload limit.');
                $bytes += $entry['size'];
                $names[$entry['name']] = true;
                $this->checkLimits($i + 1, $bytes);
            }
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) $this->invalid('The archive is missing its manifest.');
            try { $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $this->invalid('The archive manifest is invalid.'); }
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'digizu-page-editor' || ($manifest['version'] ?? null) !== 1
                || !is_array($manifest['documents'] ?? null) || !is_array($manifest['images'] ?? null)) {
                $this->invalid('Unsupported CMS export format.');
            }
            if (count($manifest['documents']) > 10000) $this->invalid('The archive contains too many CMS documents.');
            foreach ($manifest['documents'] as $name => $document) {
                $this->validateName($name);
                $this->validateDocument($document);
            }
            $images = [];
            foreach ($manifest['images'] as $name => $url) {
                if (!is_string($name) || !str_starts_with($name, 'images/') || !isset($names[$name]) || !is_string($url) || !ImageValue::safeUrl($url)) {
                    $this->invalid('Invalid image reference in the archive.');
                }
                $data = $zip->getFromName($name);
                $info = is_string($data) ? @getimagesizefromstring($data) : false;
                $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
                    'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', default => '',
                };
                if (!$info || $info['mime'] !== $mime || $info[0] > 12000 || $info[1] > 12000) $this->invalid('The archive contains an invalid image.');
                $images[] = $name;
            }
            if (count($names) !== count($images) + 1) $this->invalid('The archive contains unlisted files.');
            $this->store->transaction(function () use ($manifest, $images, $zip) {
                $previous = $this->documents();
                $disk = Storage::disk(config('page-editor.images.disk', 'public'));
                $directory = $this->imageDirectory();
                $created = [];
                $written = [];
                try {
                    $urls = [];
                    foreach ($images as $name) {
                        $data = $zip->getFromName($name);
                        if ($data === false) throw new RuntimeException('Unable to read archived image.');
                        // Fresh names avoid overwriting files used by the destination's live pages.
                        $target = $directory.'/'.bin2hex(random_bytes(20)).'.'.pathinfo($name, PATHINFO_EXTENSION);
                        $created[] = $target;
                        if (!$disk->put($target, $data, 'public')) throw new RuntimeException('Unable to import CMS image.');
                        $urls[$manifest['images'][$name]] = $disk->url($target);
                    }
                    $documents = $manifest['documents'];
                    // Tombstones retain versions and prevent legacy fallback resurrecting removed pages.
                    foreach (array_unique(array_merge(array_keys($previous), array_keys($documents), ['_scoped.json'])) as $name) {
                        $document = $documents[$name] ?? ['version' => 0, 'draft' => [], 'published' => [], 'history' => []];
                        $document['version'] = max($document['version'], $previous[$name]['version'] ?? 0) + 1;
                        $document = $this->remap($document, $urls);
                        $written[] = $name;
                        $this->writeDocument($name, $document);
                    }
                } catch (\Throwable $e) {
                    foreach (array_reverse($written) as $name) {
                        if (isset($previous[$name])) $this->writeDocument($name, $previous[$name]);
                        else @unlink(config('page-editor.path').'/'.$name);
                    }
                    foreach ($created as $target) $disk->delete($target);
                    throw $e;
                }
            });
        } finally {
            $zip->close();
        }
    }

    private function remap(mixed $value, array $urls): mixed
    {
        if (is_array($value)) {
            foreach ($value as &$item) $item = $this->remap($item, $urls);
            return $value;
        }
        if (!is_string($value)) return $value;
        if (isset($urls[$value])) return $urls[$value];
        $image = json_decode($value, true);
        if (is_array($image) && is_string($image['src'] ?? null) && isset($urls[$image['src']])) {
            $image['src'] = $urls[$image['src']];
            return json_encode($image, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return $value;
    }

    private function writeDocument(string $name, array $document): void
    {
        $root = rtrim(config('page-editor.path'), '/');
        $path = $root.'/'.$name;
        if (is_link($root.'/pages') || is_link($path)) throw new RuntimeException('CMS content cannot contain symbolic links.');
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create CMS directory.');
        $temporary = tempnam(dirname($path), '.import-');
        if ($temporary === false) throw new RuntimeException('Unable to stage CMS content.');
        try {
            if (file_put_contents($temporary, json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to import CMS content.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function validateName(mixed $name): void
    {
        if (!is_string($name) || !preg_match('~\A(?:[a-zA-Z0-9_-]{1,100}|pages/[a-f0-9]{64})\.json\z~', $name)) $this->invalid('Invalid CMS document path.');
    }

    private function validateDocument(mixed $document): array
    {
        if (!is_array($document) || !is_int($document['version'] ?? null) || $document['version'] < 0 || $document['version'] >= PHP_INT_MAX
            || !is_array($document['history'] ?? null)) $this->invalid('Invalid CMS document.');
        foreach (['draft', 'published'] as $kind) $this->validateValues($document[$kind] ?? null);
        foreach ($document['history'] as $entry) {
            if (!is_array($entry) || !is_string($entry['action'] ?? null) || !(is_string($entry['version'] ?? null) || is_int($entry['version'] ?? null))
                || !(is_null($entry['at'] ?? null) || is_string($entry['at']))) $this->invalid('Invalid revision history.');
            $this->validateValues($entry['content'] ?? null);
            if (isset($entry['changed'])) $this->validateValues($entry['changed']);
        }
        if (isset($document['fingerprints'])) {
            if (!is_array($document['fingerprints'])) $this->invalid('Invalid fingerprints.');
            foreach ($document['fingerprints'] as $values) $this->validateValues($values);
        }
        if (isset($document['managed'])) {
            if (!is_array($document['managed'])) $this->invalid('Invalid managed fields.');
            foreach ($document['managed'] as $value) if (!is_bool($value)) $this->invalid('Invalid managed field.');
        }
        return $document;
    }

    private function validateValues(mixed $values): void
    {
        if (!is_array($values)) $this->invalid('Invalid CMS content values.');
        foreach ($values as $value) if (!is_string($value)) $this->invalid('Invalid CMS content value.');
    }

    private function checkLimits(int $files, int $bytes): void
    {
        if ($files > 10000 || $bytes > config('page-editor.transfer.max_uncompressed_kb', 512000) * 1024) {
            $this->invalid('The CMS archive exceeds the transfer limits.');
        }
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) $this->invalid('CMS transfers require the PHP ZIP extension.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
