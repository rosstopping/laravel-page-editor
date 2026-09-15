@if ($editor->editing)
    <script type="application/json" id="cms-bootstrap">{!! json_encode([
        'state' => $editor->bootstrap(), 'fields' => $editor->fields,
        'endpoint' => route('page-editor.save', $editor->page), 'csrf' => csrf_token(),
        'exitUrl' => $editor->request->url(),
        'alpine' => route('page-editor.asset', ['asset' => 'alpine.js']),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <template id="cms-toolbar-template"><div x-data="cmsPageEditor"><x-page-editor :exit-url="$editor->request->url()" /></div></template>
    <script defer src="{{ route('page-editor.asset', ['asset' => 'editor.js', 'v' => $assetVersion]) }}" data-cms-asset></script>
@else
    @if ($notice = $editor->request->session()->pull('page-editor.published.'.$editor->page))
        <div class="cms-tool cms-toast" id="cms-published-notice" data-endpoint="{{ route('page-editor.save', $editor->page) }}">
            <div class="cms-toast__copy"><p class="cms-tool__title">Page editor</p><p role="status">{{ $notice }}</p></div>
            <button type="button" class="cms-tool__icon" aria-label="Dismiss notification">×</button>
        </div>
        <script defer src="{{ route('page-editor.asset', ['asset' => 'editor.js', 'v' => $assetVersion]) }}" data-cms-asset></script>
    @endif
    <div class="cms-idle-spacer" aria-hidden="true"></div>
    <aside class="cms-tool cms-dock" aria-label="Page editor"><div class="cms-bar">
        <div class="cms-bar__identity"><p class="cms-tool__title">Page editor</p><p class="cms-bar__status">Viewing published page</p></div>
        <a href="{{ $editor->request->url() }}?edit=1" class="cms-tool__button cms-tool__button--primary">Edit page</a>
    </div></aside>
@endif
