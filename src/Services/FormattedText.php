<?php

namespace Digizu\PageEditor\Services;

class FormattedText
{
    public const PREFIX = '__cms_html__:';

    public static function render(string $value): string
    {
        return str_starts_with($value, self::PREFIX)
            ? self::clean(substr($value, strlen(self::PREFIX)))
            : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function fromHtml(string $html): string
    {
        $safe = self::clean($html);
        return preg_match('/<(strong|em|u|br)>/', $safe)
            ? self::PREFIX.$safe
            : html_entity_decode($safe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function normalize(string $value): string
    {
        return str_starts_with($value, self::PREFIX) ? self::fromHtml(substr($value, strlen(self::PREFIX))) : $value;
    }

    private static function clean(string $html): string
    {
        // Reconstruct a tiny allowlist; no supplied attributes or other tags survive.
        return implode('', array_map(function ($part) {
            if (preg_match('/^<\s*(\/?)\s*(strong|b|em|i|u|br)\b[^>]*>$/i', $part, $match)) {
                $tag = match (strtolower($match[2])) { 'b' => 'strong', 'i' => 'em', default => strtolower($match[2]) };
                return $tag === 'br' ? '<br>' : '<'.$match[1].$tag.'>';
            }
            return htmlspecialchars(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY)));
    }
}
