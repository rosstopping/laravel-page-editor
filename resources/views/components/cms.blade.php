@props(['field', 'scope' => null, 'source' => null, 'type' => 'text', 'src' => '', 'alt' => '', 'href' => ''])
@php
    $editor = request()->attributes->get('pageEditor');
    $default = \Digizu\PageEditor\Services\FormattedText::fromHtml(trim((string) $slot));
    if ($type === 'image') $default = \Digizu\PageEditor\Services\ImageValue::normalize(json_encode(['src' => $src, 'alt' => $alt]));
    if ($type === 'link') $default = \Digizu\PageEditor\Services\LinkValue::normalize(json_encode(['href' => $href, 'text' => trim(html_entity_decode(strip_tags((string) $slot), ENT_QUOTES | ENT_HTML5, 'UTF-8'))]));
    $resolvedScope = $scope === 'page' ? null : ($scope !== null ? 'named:'.$scope : ($source ? 'view:'.$source : null));
    $key = $resolvedScope ? \Digizu\PageEditor\Services\SourceScope::key($resolvedScope, $field) : $field;
    $value = $editor ? $editor->text($field, $default, in_array($type, ['image', 'link'], true) ? $type : 'rich', $resolvedScope) : $default;
    $editing = $editor?->editing ?? false;
@endphp
@if ($type === 'image')
    @php
        $image = json_decode(\Digizu\PageEditor\Services\ImageValue::normalize($value), true);
    @endphp
    <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" {{ $attributes }} @if ($editing) data-cms-image="{{ $key }}" @endif>
@elseif ($type === 'link')
    @php
        $link = json_decode(\Digizu\PageEditor\Services\LinkValue::normalize($value), true);
        if ($attributes->get('target') === '_blank') $attributes = $attributes->except('rel')->merge(['rel' => trim($attributes->get('rel', '').' noopener noreferrer')]);
    @endphp
    <a href="{{ $link['href'] }}" {{ $attributes }} @if ($editing) data-cms-link="{{ $key }}" @endif>{{ $before ?? '' }}<span @if ($editing) data-cms-link-label @endif>{{ $link['text'] }}</span>{{ $after ?? '' }}</a>
@else
<span class="cms-text" @if ($editing) data-editable-field="{{ $key }}" @endif>{!! \Digizu\PageEditor\Services\FormattedText::render($value) !!}</span>

@endif
