<?php

namespace Digizu\PageEditor\Services;

use Illuminate\Validation\ValidationException;

class LinkValue
{
    public static function normalize(string $value, string $field = 'link'): string
    {
        $link = json_decode($value, true);
        if (!is_array($link) || !is_string($link['href'] ?? null) || !is_string($link['text'] ?? null)
            || trim($link['text']) === '' || mb_strlen($link['text']) > 2000
            || strlen($link['href']) > 4096 || !self::safeUrl($link['href'])) {
            throw ValidationException::withMessages([$field => 'Enter link text and a valid destination: https://, /page, #section, ?query, mailto: or tel:.']);
        }
        return json_encode(['href' => $link['href'], 'text' => $link['text']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function safeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        if (ImageValue::safeUrl($url)) return true;
        if (str_starts_with($url, '#') || str_starts_with($url, '?')) return true;
        if (str_starts_with(strtolower($url), 'mailto:')) {
            return !preg_match('/%0[ad]/i', $url) && (bool) filter_var(explode('?', substr($url, 7), 2)[0], FILTER_VALIDATE_EMAIL);
        }
        return (bool) preg_match('/^tel:\+?[0-9][0-9().-]*$/i', $url);
    }
}
