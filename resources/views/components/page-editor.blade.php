@props(['exitUrl'])
<div x-cloak x-show="message || error" class="cms-tool cms-toast" :class="error ? 'cms-toast--error' : ''">
    <div class="cms-toast__copy">
        <p class="cms-tool__title" x-text="error ? 'Something needs attention' : 'Page editor'"></p>
        <p role="status" aria-atomic="true" x-text="message"></p>
        <p role="alert" aria-atomic="true" x-text="error"></p>
    </div>
    <button type="button" @click="dismissNotification()" aria-label="Dismiss notification" class="cms-tool__icon">×</button>
</div>
<aside class="cms-tool cms-dock" :data-layout="dockLayout" aria-label="Page editor" x-ref="dock" x-cloak>
    <div id="cms-editor-panel" x-show="dockLayout === 'left' || (!preview && expanded)">
        <p class="cms-shared-notice cms-code-notice" x-show="Object.values(fields).some(field => field.updated_in_code)" role="status"><strong>Updated in code</strong> Some defaults changed in the page markup. Older CMS edits are ignored; saving removes those overrides and keeps them in revision history.</p>
        <div class="cms-window-heading">
        <div class="cms-tabs" role="group" aria-label="Editor sections">
            <button type="button" @click="panel = 'content'" :aria-pressed="panel === 'content'">Page content</button>
            <button type="button" @click="panel = 'metadata'" :aria-pressed="panel === 'metadata'">SEO &amp; social</button>
            <button type="button" @click="panel = 'changes'" :aria-pressed="panel === 'changes'">Changes</button>
            <button type="button" @click="panel = 'history'" :aria-pressed="panel === 'history'">Revision history</button>
            <button type="button" @click="panel = 'transfer'" :aria-pressed="panel === 'transfer'">Transfer</button>
        </div>
        <button type="button" x-show="dockLayout !== 'left'" class="cms-tool__icon cms-minimise" @click="minimise()" aria-label="Minimise editor" title="Minimise editor" aria-controls="cms-editor-panel" :aria-expanded="expanded"><span aria-hidden="true">−</span></button>
        </div>
        <section x-show="panel === 'changes'" class="cms-changes" aria-label="Content changes">
            <div class="cms-changes__intro">
                <h2 class="cms-tool__title">Review changes</h2>
                <div class="cms-filters" role="group" aria-label="Compare content">
                    <button type="button" @click="changeMode = 'unpublished'" :aria-pressed="changeMode === 'unpublished'">Unpublished changes</button>
                    <button type="button" @click="changeMode = 'overrides'" :aria-pressed="changeMode === 'overrides'">CMS overrides</button>
                </div>
                <p class="cms-tool__hint" x-text="changeMode === 'unpublished' ? 'Compare your current edits with the published page. Includes saved drafts and unsaved edits on this page, including shared fields.' : 'Compare published content with the defaults in Blade for this page, including shared fields. Unpublished edits are excluded.'"></p>
                <label class="cms-changes__toggle"><input type="checkbox" name="cms-highlight-changes" x-model="highlightChanges"> Highlight changed fields on the page</label>
                <p class="cms-tool__hint" x-show="highlightChanges">Dashed outlines mark changed fields in edit mode. SEO changes appear in this list.</p>
                <p class="cms-tool__hint" role="status" x-text="changedFields.length + (changedFields.length === 1 ? ' changed field' : ' changed fields')"></p>
                <p class="cms-diff-legend"><span class="cms-diff-removed">Removed</span> <span class="cms-diff-added">Added</span></p>
            </div>
            <p class="cms-changes__empty" x-show="changedFields.length === 0" x-text="changeMode === 'unpublished' ? 'No unpublished changes. Your current content matches the published page.' : 'No published overrides. Published content matches the Blade defaults.'"></p>
            <ul class="cms-changes__list" role="list">
                <template x-for="change in changeEntries" :key="change.key">
                    <li class="cms-change">
                        <div class="cms-change__heading">
                            <div><h3 class="cms-tool__title" x-text="change.label"></h3><span class="cms-shared-badge" x-show="change.field.shared">Shared content</span></div>
                            <button type="button" class="cms-tool__button" @click="reviewField(change.key)" :disabled="busy" x-text="isMetadata(change.key) ? 'Review SEO' : 'Find on page'"></button>
                        </div>
                        <p class="cms-tool__hint" x-show="change.field.shared">This field is shared with other pages.</p>
                        <div class="cms-change__columns cms-change__labels" aria-hidden="true"><p x-text="changeMode === 'unpublished' ? 'Published' : 'Blade default'"></p><p x-text="changeMode === 'unpublished' ? 'Current edits' : 'Published override'"></p></div>
                        <template x-if="change.images">
                            <div class="cms-change__columns cms-change__images">
                                <template x-for="side in ['before', 'after']" :key="side">
                                    <figure><figcaption class="cms-visually-hidden cms-change__side-label" x-text="side === 'before' ? 'Before' : 'After'"></figcaption><img x-show="safePreviewImage(change.images[side].src)" :src="safePreviewImage(change.images[side].src) || null" :alt="change.images[side].alt" loading="lazy"></figure>
                                </template>
                            </div>
                        </template>
                        <template x-for="row in change.rows" :key="row.label">
                            <div class="cms-change__row">
                                <p class="cms-tool__hint" x-text="row.label + (row.changed ? '' : ' (unchanged)')"></p>
                                <div class="cms-change__columns">
                                    <div><span class="cms-visually-hidden cms-change__side-label">Before: </span><p class="cms-change__value" x-html="row.before" x-show="!row.emptyBefore"></p><p class="cms-tool__hint" x-show="row.emptyBefore">Empty</p></div>
                                    <div><span class="cms-visually-hidden cms-change__side-label">After: </span><p class="cms-change__value" x-html="row.after" x-show="!row.emptyAfter"></p><p class="cms-tool__hint" x-show="row.emptyAfter">Empty</p></div>
                                </div>
                            </div>
                        </template>
                        <template x-if="change.rich">
                            <details class="cms-change__format" :open="change.formattingOnly">
                                <summary x-text="change.formattingOnly ? 'Formatting changed — compare appearance' : 'Compare formatted appearance'"></summary>
                                <div class="cms-change__columns"><div><span class="cms-visually-hidden cms-change__side-label">Before: </span><p class="cms-change__value" x-html="change.rich.before"></p></div><div><span class="cms-visually-hidden cms-change__side-label">After: </span><p class="cms-change__value" x-html="change.rich.after"></p></div></div>
                            </details>
                        </template>
                    </li>
                </template>
            </ul>
        </section>
        <section x-show="panel === 'transfer'" class="cms-transfer" aria-label="Transfer CMS content" :aria-busy="busy">
            <h2 class="cms-tool__title">Move content between environments</h2>
            <p class="cms-tool__hint">Export all saved CMS content, drafts, revision history and uploaded images across the site. Save your draft first to include unsaved edits.</p>
            <div><button type="button" class="cms-tool__button" @click="exportCms(@js(route('page-editor.export')))" :disabled="busy || dirty" x-text="busy &amp;&amp; operation === 'export' ? 'Exporting…' : 'Export CMS'">Export CMS</button></div>
            <p class="cms-tool__hint"><strong>Import replaces CMS content across this site.</strong> Published content goes live immediately. Export a backup first. Existing image uploads are retained.</p>
            <label for="cms-import">CMS export ZIP</label>
            <input id="cms-import" name="cms-import" type="file" accept=".zip,application/zip" x-ref="importArchive" :disabled="busy" @change="importArchive = $event.target.files[0] || null">
            <p class="cms-tool__hint">Use an export from the same application with matching Blade templates. External image URLs stay unchanged.</p>
            <div><button type="button" class="cms-tool__button" @click="importCms(@js(route('page-editor.import')))" :disabled="busy || !importArchive" x-text="busy &amp;&amp; operation === 'import' ? 'Importing…' : 'Import CMS'">Import CMS</button></div>
        </section>
        <div x-show="panel === 'metadata'" class="cms-metadata">
            <p class="cms-tool__hint cms-metadata__intro">Set how this page appears in search results and when shared. Save a draft to keep changes private, or publish to make them live.</p>
            <template x-for="([key, field]) in metadataFields" :key="key">
                <div>
                    <label :for="'cms-meta-' + key" x-text="metadataLabel(key)"></label>
                    <textarea :id="'cms-meta-' + key" :name="key" x-model="content[key]" :disabled="busy" :rows="key.includes('description') ? 3 : 1" maxlength="2000"></textarea>
                </div>
            </template>
        </div>
        <div x-show="panel === 'content'" class="cms-panel" :data-detail="Boolean(selected)">
        <div class="cms-panel__field">
            <div class="cms-browser-heading"><p class="cms-tool__title">On this page</p><p class="cms-count" x-text="filteredFields.length + ' / ' + contentFields.length"></p></div>
            <input class="cms-search" type="search" id="cms-search" x-ref="contentSearch" name="cms-search" aria-label="Search page content" placeholder="Find text or an image…" x-model.debounce.150ms="search">
            <div class="cms-filters" role="group" aria-label="Content type">
                <button type="button" @click="contentFilter = 'all'" :aria-pressed="contentFilter === 'all'">All</button>
                <button type="button" @click="contentFilter = 'text'" :aria-pressed="contentFilter === 'text'">Text</button>
                <button type="button" @click="contentFilter = 'image'" :aria-pressed="contentFilter === 'image'">Images</button>
                <button type="button" @click="contentFilter = 'link'" :aria-pressed="contentFilter === 'link'">Links</button>
            </div>
            <ul class="cms-content-list" x-ref="contentList" role="list" aria-label="Page content">
                <template x-for="([key, field]) in filteredFields" :key="key">
                    <li><button type="button" class="cms-content-item" :data-field="key" :aria-pressed="selected === key" @click="selectField(key)">
                        <template x-if="field.format === 'image'"><img :src="imageValue(key).src" alt="" loading="lazy"></template>
                        <span class="cms-content-item__copy"><span class="cms-content-item__label" x-text="fieldLabel(key)"></span><span class="cms-content-badges" x-show="field.shared || field.updated_in_code"><span class="cms-shared-badge" x-show="field.shared">Shared</span><span class="cms-shared-badge" x-show="field.updated_in_code">Updated in code</span></span><span class="cms-content-item__preview" x-text="fieldPreview(key)"></span></span>
                    </button></li>
                </template>
            </ul>
            <p class="cms-tool__hint" x-show="filteredFields.length === 0" role="status">No matches. Try another search or filter.</p>
        </div>
        <div class="cms-panel__copy">
            <div class="cms-detail-heading">
                <button type="button" class="cms-tool__button cms-mobile-back" @click="backToContent()">Back to content</button>
                <div><p class="cms-detail-kind" x-text="selected ? (fields[selected].format === 'image' ? 'Image' : (fields[selected].format === 'link' ? 'Link' : 'Text')) : 'Page content'"></p>
                <h2 class="cms-detail-title" x-ref="detailHeading" tabindex="-1" x-text="selected ? fieldLabel(selected) : 'Choose content to edit'"></h2></div>
                <button type="button" x-show="selected" class="cms-tool__button" @click="locateField()">Find on page</button>
            </div>
            <div class="cms-shared-notice" x-show="selected &amp;&amp; fields[selected]?.shared"><strong>Shared content</strong> Publishing updates every page that uses this content.</div>
            <div class="cms-format" x-show="selected &amp;&amp; fields[selected].format === 'rich'" role="group" aria-label="Text formatting">
                <button type="button" @mousedown.prevent @click="format('bold')" :disabled="busy || !selected || !savedRange || activeField !== selected" aria-label="Bold" title="Bold (Ctrl/Cmd+B)"><strong>B</strong></button>
                <button type="button" @mousedown.prevent @click="format('italic')" :disabled="busy || !selected || !savedRange || activeField !== selected" aria-label="Italic" title="Italic (Ctrl/Cmd+I)"><em>I</em></button>
                <button type="button" @mousedown.prevent @click="format('underline')" :disabled="busy || !selected || !savedRange || activeField !== selected" aria-label="Underline" title="Underline (Ctrl/Cmd+U)"><u>U</u></button>
            </div>
            <template x-if="selected &amp;&amp; fields[selected].format === 'rich'"><div id="editor-copy" class="cms-rich-input" role="textbox" aria-multiline="true" :aria-label="fieldLabel(selected)" :contenteditable="busy ? 'false' : 'true'"
                x-effect="syncInline($el, selected)" @input="updateInline($el, selected)"
                @focus="rememberSelection($el, selected)" @mouseup="rememberSelection($el, selected)" @keyup="rememberSelection($el, selected)"
                @keydown="formatShortcut($event, $el, selected)" @paste.prevent="insertInline($el, selected, $event.clipboardData.getData('text/plain'))" @drop.prevent></div></template>
            <template x-if="selected &amp;&amp; fields[selected].format === 'plain'"><textarea id="editor-copy" name="editor-copy" x-model="content[selected]" :aria-label="fieldLabel(selected)" :disabled="busy" rows="3" maxlength="2000"></textarea></template>
            <template x-if="selected &amp;&amp; fields[selected].format === 'link'">
                <div class="cms-link-panel">
                    <label>Link text<input type="text" name="cms-link-text" :value="linkValue().text" @input="updateLink('text', $event.target.value)" maxlength="2000" :disabled="busy" aria-describedby="cms-link-help"></label>
                    <label>Destination<input type="text" name="cms-link-href" :value="linkValue().href" @input="updateLink('href', $event.target.value)" maxlength="4096" :disabled="busy" :aria-invalid="!linkValue().href || Boolean(linkError())" aria-describedby="cms-link-help cms-link-error" spellcheck="false" autocapitalize="off"></label>
                    <p id="cms-link-help" class="cms-tool__hint">Use a full website address, a path like /reviews, a #section, or a mailto: or tel: link. Styling and opening behaviour stay as defined on the page.</p>
                    <p id="cms-link-error" class="cms-link-error" x-show="linkError()" x-text="linkError()" role="status"></p>
                    <button type="button" class="cms-tool__button" @click="replaceContent({ ...content, [selected]: defaults[selected] })" :disabled="busy">Reset to original link</button>
                    <p class="cms-tool__hint">In edit mode, clicking a link selects it. Use Preview to follow links.</p>
                </div>
            </template>
            <template x-if="selected &amp;&amp; fields[selected].format === 'image'">
                <div class="cms-image-panel">
                    <img :src="imageValue().src" alt="Selected image preview" class="cms-image-preview">
                    <label class="cms-image-url">Image URL<input type="url" name="cms-image-url" :value="imageValue().src" @input="updateImage('src', $event.target.value)" :disabled="busy"></label>
                    <div class="cms-image-actions">
                    <label class="cms-upload-control">Upload replacement<input type="file" name="cms-image-upload" accept="image/jpeg,image/png,image/webp,image/gif" @change="uploadImage($event.target.files[0]); $event.target.value = ''" :disabled="busy"></label>
                    <button type="button" x-ref="libraryToggle" class="cms-tool__button" @click="libraryOpen ? closeLibrary() : openLibrary()" :disabled="busy" :aria-expanded="libraryOpen">Choose from image library</button>
                    </div>
                    <section x-show="libraryOpen" class="cms-library" aria-label="Image library" :aria-busy="libraryLoading">
                        <div class="cms-library-heading"><h3 class="cms-tool__title" x-ref="libraryHeading" tabindex="-1">CMS uploads</h3><button type="button" class="cms-tool__button" @click="closeLibrary()">Close library</button></div>
                        <p class="cms-tool__hint">Reuse an upload from any page. Your current alt text is kept.</p>
                        <p x-show="libraryLoading" role="status">Loading images…</p>
                        <p x-show="libraryError" x-text="libraryError" role="alert"></p>
                        <button type="button" x-show="libraryError" class="cms-tool__button" @click="openLibrary(libraryPage)">Try again</button>
                        <p x-show="!libraryLoading &amp;&amp; !libraryError &amp;&amp; !libraryItems.length" role="status">No images yet. Upload an image to start your library.</p>
                        <div class="cms-library-grid" x-show="!libraryLoading &amp;&amp; !libraryError">
                            <template x-for="image in libraryItems" :key="image.src"><button type="button" class="cms-library-image" @click="chooseLibraryImage(image)" :disabled="busy" :aria-label="'Use image ' + image.name" :title="image.name" :aria-pressed="imageValue().src === image.src">
                                <img :src="image.src" alt="" loading="lazy"><span x-text="new Date(image.uploaded_at).toLocaleDateString()"></span>
                            </button></template>
                        </div>
                        <div class="cms-library-heading" x-show="!libraryLoading &amp;&amp; !libraryError &amp;&amp; (libraryPage > 1 || libraryMore)">
                            <button type="button" class="cms-tool__button" :disabled="libraryPage === 1" @click="openLibrary(libraryPage - 1)">Previous</button>
                            <span x-text="'Page ' + libraryPage"></span><button type="button" class="cms-tool__button" :disabled="!libraryMore" @click="openLibrary(libraryPage + 1)">Next</button>
                        </div>
                    </section>
                    <label class="cms-image-alt">Alt text<input type="text" name="cms-image-alt" :value="imageValue().alt" @input="updateImage('alt', $event.target.value)" maxlength="1000" :disabled="busy"></label>
                    <p class="cms-tool__hint">Describe the image for people using screen readers. Leave blank if the image is only decorative.</p>
                    <button type="button" class="cms-tool__button" @click="replaceContent({ ...content, [selected]: defaults[selected] })" :disabled="busy">Reset to original image</button>
                </div>
            </template>
            <p x-show="!selected" class="cms-panel__empty">Choose some content from the list, or click directly on your page. Your edits appear here as you make them.</p>
        </div>
        </div>
        <div x-show="panel === 'history'" class="cms-history-panel">
            <p class="cms-tool__title">Previous versions</p>
            <p class="cms-tool__hint">Restore a version to your draft workspace. Nothing goes live until you publish; Undo reverses a restore.</p>
            <ol class="cms-history-list" role="list">
                <template x-for="(revision, index) in history" :key="revision.version">
                    <li><div><p class="cms-tool__title" x-text="revision.action === 'original' ? 'Original content' : (revision.action === 'code-update' ? 'Before code update' : (revision.action === 'publish' ? 'Published version' : 'Saved draft'))"></p><p class="cms-tool__hint" x-text="revision.at ? new Date(revision.at).toLocaleString() : 'Defaults from your page'"></p></div><button type="button" class="cms-tool__button" :disabled="busy" @click="restore(index); panel = 'content'">Restore <span class="cms-visually-hidden" x-text="revision.action === 'original' ? 'original content' : 'version from ' + new Date(revision.at).toLocaleString()"></span></button></li>
                </template>
            </ol>
        </div>
    </div>
    <div class="cms-bar">
        <div class="cms-bar__identity">
            <p class="cms-tool__title">Page editor</p>
            <p class="cms-bar__status" :class="{'cms-bar__status--dirty': dirty, 'cms-bar__status--busy': busy}" role="status" x-text="statusLabel"></p>
        </div>
        <div class="cms-bar__actions">
            <div class="cms-bar__workspace" role="group" aria-label="Editor tools">
            <div class="cms-undo-actions" role="group" aria-label="Edit history">
                <button type="button" class="cms-tool__button cms-tool__button--quiet" @click="undo()" :disabled="busy || !canUndo" title="Undo (Ctrl/Cmd+Z)">Undo</button>
                <button type="button" class="cms-tool__button cms-tool__button--quiet" @click="redo()" :disabled="busy || !canRedo" title="Redo (Ctrl/Cmd+Shift+Z)">Redo</button>
            </div>
            <button type="button" x-ref="toolsToggle" x-show="dockLayout !== 'left'" @click="expanded = !expanded; preview = false" :aria-expanded="expanded && !preview" aria-controls="cms-editor-panel" class="cms-tool__button cms-tool__button--quiet" x-text="expanded && !preview ? 'Hide tools' : 'Show tools'"></button>
            @if (app()->environment('local'))
                <button type="button" class="cms-tool__button cms-tool__button--quiet" @click="resetCms(@js(route('page-editor.reset')))" :disabled="busy">Reset CMS</button>
            @endif
            <div class="cms-layout-picker" @click.outside="layoutMenuOpen = false" @keydown.escape.stop.prevent="closeLayoutMenu()">
                <button type="button" x-ref="layoutToggle" class="cms-tool__button cms-tool__button--quiet" @click="toggleLayoutMenu()" :aria-expanded="layoutMenuOpen" aria-controls="cms-layout-options">Layout</button>
                <div id="cms-layout-options" x-ref="layoutChoices" x-show="layoutMenuOpen" class="cms-layout-options" role="group" aria-label="Editor layout">
                    <p class="cms-tool__title">Editor layout</p>
                    <p class="cms-tool__hint">Choose where your tools sit.</p>
                    <button type="button" class="cms-layout-option" data-layout-option="left" :aria-pressed="selectedLayout === 'left'" :disabled="!layoutWide" @click="chooseLayout('left')">
                        <span class="cms-layout-diagram cms-layout-diagram--left" aria-hidden="true"></span>
                        <span><strong>Left sidebar</strong><span>Place tools beside the website.</span></span>
                    </button>
                    <button type="button" class="cms-layout-option" data-layout-option="bottom" :aria-pressed="selectedLayout === 'bottom'" @click="chooseLayout('bottom')">
                        <span class="cms-layout-diagram cms-layout-diagram--bottom" aria-hidden="true"></span>
                        <span><strong>Bottom panel</strong><span>More width for browsing and editing.</span></span>
                    </button>
                    <p class="cms-tool__hint" x-show="!layoutWide">On screens below 768px, Bottom panel is always selected.</p>
                    <p class="cms-tool__hint">Your choice is remembered in this browser. Left stays open; Bottom can be minimised.</p>
                </div>
            </div>
            <button type="button" @click="preview = !preview" :aria-pressed="preview" class="cms-tool__button cms-tool__button--quiet" x-text="preview ? 'Edit mode' : 'Preview'"></button>
            </div>
            <div class="cms-bar__publish" role="group" aria-label="Save and publish">
            <button type="button" @click="save('draft')" :disabled="busy" class="cms-tool__button" x-text="busy &amp;&amp; operation === 'draft' ? 'Saving…' : 'Save draft'">Save draft</button>
            <button type="button" @click="save('publish')" :disabled="busy" class="cms-tool__button cms-tool__button--primary" x-text="busy &amp;&amp; operation === 'publish' ? 'Publishing…' : 'Publish'">Publish</button>
            <a href="{{ $exitUrl }}" class="cms-tool__button cms-tool__button--quiet">Exit</a>
            </div>
        </div>
    </div>
</aside>
