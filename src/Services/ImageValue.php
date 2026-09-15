<?php
namespace Digizu\PageEditor\Services;

use Illuminate\Validation\ValidationException;

class ImageValue
{
    public static function normalize(string $value, string $field = 'image'): string
    {
        $image = json_decode($value, true);
        if (is_array($image) && is_string($image['src'] ?? null)) $image['src'] = str_replace(' ', '%20', $image['src']);
        if (!is_array($image) || !is_string($image['src'] ?? null) || !is_string($image['alt'] ?? null)
            || mb_strlen($image['alt']) > 1000 || strlen($image['src']) > 4096 || !self::safeUrl($image['src'])) {
            throw ValidationException::withMessages([$field => 'Enter a valid HTTP(S) or root-relative image URL and alt text of at most 1,000 characters.']);
        }
        return json_encode(['src' => $image['src'], 'alt' => $image['alt']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        return (str_starts_with($url, '/') && !str_starts_with($url, '//'))
            || (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true));
    }
}
