<?php

namespace Digizu\PageEditor\Services;

class DefaultValue
{
    public static function fingerprint(array $field): string
    {
        $format = $field['format'] ?? 'rich';
        $value = $field['default'];
        if ($format === 'link') {
            $link = json_decode($value, true);
            $link['text'] = trim(preg_replace('/[ \t\r\n\f]+/u', ' ', $link['text']));
            $value = json_encode($link, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif ($format !== 'image') {
            if ($format === 'rich') $value = FormattedText::normalize($value);
            // Ignore indentation and ordinary HTML whitespace; retain formatting and explicit breaks.
            $value = trim(preg_replace('/[ \t\r\n\f]+/u', ' ', $value));
        }
        return hash('sha256', $format."\0".$value);
    }

    public static function outdated(array $state, string $kind, string $key, array $field): bool
    {
        return array_key_exists($key, $state[$kind]) && isset($state['fingerprints'][$kind][$key])
            && $state['fingerprints'][$kind][$key] !== self::fingerprint($field);
    }
}
