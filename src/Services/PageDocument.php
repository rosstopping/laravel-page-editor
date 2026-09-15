<?php

namespace Digizu\PageEditor\Services;

class PageDocument
{
    public function process(string $html, PageEditorContext $context): string
    {
        if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $head, PREG_OFFSET_CAPTURE) || !preg_match('/<\/body\s*>/i', $html)) return $html;
        $original = $head[1][0];
        $ogCounts = [];
        $metadata = preg_replace_callback('~<!--.*?-->(*SKIP)(*F)|<(script|style)\b[^>]*>.*?</\1>(*SKIP)(*F)|<title\b[^>]*>(.*?)</title>|<meta\b[^>]*>~is', function ($match) use ($context, &$ogCounts) {
            $tag = $match[0];
            if (preg_match('/^(<title\b[^>]*>)(.*?)(<\/title>)/is', $tag, $title)) {
                $default = html_entity_decode(strip_tags($title[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return $title[1].e($context->text('seo_title', $context->fields['seo_title']['default'] ?? $default, 'plain')).$title[3];
            }
            // Parse this single tag only; do not reserialize the page DOM.
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$tag);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $meta = $document->getElementsByTagName('meta')->item(0);
            if (!$meta) return $tag;
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $meta->setAttribute('content', $context->text('seo_description', $context->fields['seo_description']['default'] ?? $meta->getAttribute('content'), 'plain'));
                return $document->saveHTML($meta);
            }
            $attribute = $meta->hasAttribute('property') ? 'property' : 'name';
            $property = strtolower($meta->getAttribute($attribute));
            if (!str_starts_with($property, 'og:')) return $tag;
            $index = $ogCounts[$property] ?? 0;
            $ogCounts[$property] = $index + 1;
            $key = preg_replace('/[^a-z0-9_]/', '_', $property).($index ? '_'.($index + 1) : '');
            $meta->setAttribute('content', $context->text($key, $meta->getAttribute('content'), 'plain'));
            $context->fields[$key]['meta'] = ['attribute' => $attribute, 'name' => $property, 'index' => $index];
            $context->fields[$key]['label'] = $property.($index ? ' ('.($index + 1).')' : '');
            return $document->saveHTML($meta);
        }, $original);
        if (!isset($context->fields['seo_title'])) $metadata .= '<title>'.e($context->text('seo_title', '', 'plain')).'</title>';
        if (!isset($context->fields['seo_description'])) $metadata .= '<meta name="description" content="'.e($context->text('seo_description', '', 'plain')).'">';
        foreach (['title', 'description', 'image', 'url', 'type', 'site_name'] as $name) {
            $key = 'og_'.$name;
            if (isset($context->fields[$key])) continue;
            $value = $context->text($key, '', 'plain');
            $context->fields[$key]['meta'] = ['attribute' => 'property', 'name' => 'og:'.$name, 'index' => 0];
            $context->fields[$key]['label'] = 'og:'.$name;
            if ($value !== '') $metadata .= '<meta property="og:'.$name.'" content="'.e($value).'">';
        }
        $html = substr_replace($html, $metadata, $head[1][1], strlen($original));
        // Base field formatting is needed for public content too.
        $css = '<link rel="stylesheet" href="'.e(route('page-editor.asset', ['asset' => 'editor.css', 'v' => filemtime(__DIR__.'/../../dist/editor.css')])).'" data-cms-asset>';
        $html = preg_replace('/<\/head\s*>/i', $css.'</head>', $html, 1);
        if (!$context->canEdit) return $html;
        $ui = view('page-editor::runtime', ['editor' => $context, 'assetVersion' => hash_file('sha256', __DIR__.'/../../dist/editor.js')])->render();
        return preg_replace_callback('/<\/body\s*>/i', fn () => $ui.'</body>', $html, 1);
    }
}
