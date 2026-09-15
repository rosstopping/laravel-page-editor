# Laravel Page Editor

A Laravel Composer package (`digizu/laravel-page-editor`) for on-page editing, draft/published overrides and revision history. Its auto-discovered provider owns the middleware, routes, components and permission gate. No Nova dependency, controller changes, layout wrapper, asset imports or consumer-side build is required.

## Installation

This package is currently in beta. Its API and behaviour may change before a stable release.

1. Install from Packagist:

   ```sh
   composer require digizu/laravel-page-editor:"^0.1.0@beta"
   ```

2. Publish configuration with `php artisan vendor:publish --tag=page-editor-config`.
3. Set `allowed_emails` (empty by default). `guard: null` uses Laravel's default authenticated session.
4. Wrap editable body text in Blade:

   ```blade
   <x-cms field="heading">Your default heading</x-cms>
   ```

Sign into your application's normal login, visit a public page and click **Edit page**, or add `?edit=1`. The package supplies no login screen.

The editor works on complete successful HTML responses containing a head and body. It discovers the existing title and description meta tag automatically, with empty defaults when missing. They appear in the dedicated **SEO & social** panel; those identifiers (`seo_title`, `seo_description`) are reserved. Metadata uses plain-text controls, is previewed in the document head, and is rendered into the server response after publishing. Keep body markup outside a CMS field unless it is supported inline formatting. Unmarked body text and structural HTML are not automatically made editable.

Requests matching `excluded_paths` bypass the editor (admin/API/editor/Livewire endpoints by default). Configure these paths for your application's administrative routes. JSON responses, redirects, streams, error responses and HTML fragments receive no UI injection. The processor modifies metadata and inserts assets/UI without reserializing the full page DOM.

## Changes to markup defaults

When saving, the editor records a SHA-256 fingerprint of each field’s markup default, separately for draft and published content. If the default later changes in code, old overrides no longer apply: both visitors and editors see the new default instead. No Git integration is required. This applies to page and shared fields, metadata, and image defaults.

Ordinary whitespace and indentation changes are ignored for text. Wording, supported formatting and explicit `<br>` changes are significant. Keep field defaults deterministic; request-dependent defaults can invalidate overrides when their rendered value changes.

The editor marks affected fields **Updated in code**. Rendering does not rewrite the content JSON. The next save or publish removes outdated overrides for that page’s rendered fields, preserving their previous snapshots in the normal revision history (latest 50 entries). A draft save also removes outdated published overrides but does not publish new draft edits. Other fields and pages are left untouched. Restoring a revision is an explicit way to reuse the old copy against the new default.

**Existing installations:** overrides saved before fingerprints were introduced remain valid until their next save establishes a baseline. Their original defaults cannot be reconstructed reliably. Save each existing edited page once before changing its defaults in code if you want those future code changes to take precedence. Deploy compiled-view/cache updates as usual so Laravel renders the new markup.

Until a save removes an outdated override, reverting code to its previous default makes that matching override valid again. After cleanup, it is available only through revision history.

## Recent additions

- **Undo / redo:** toolbar buttons and Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z (also Ctrl+Y) within editable content and editor controls. Text, metadata, image replacements and revision restores share a session history of up to 50 steps. Typing is grouped after a short pause. New edits discard the redo branch. Saving a draft does not clear undo history; reloading or publishing starts a fresh session. Undo changes the workspace, so save or publish to persist it.
- **Shared content:** the Shared badge identifies explicit named scopes and source files in `components`, `partials`, `layouts` or `includes` directories, including namespaced views. Ordinary page templates do not receive the badge merely because their values use source-based storage. Explicit `scope="page"` fields and metadata do not receive it either. This is a reusable-view classification, not a measured count of routes; use an explicit named scope for shared views outside those directories. Storage keys and cross-page value resolution are unchanged. No Blade paths appear in the editor.
- **Image library:** choose an image field, then **Choose from image library**. The authenticated library shows existing CMS uploads from the configured image disk/directory, newest first, 24 per page. Choosing an image preserves the field’s alt text and can be undone. It does not publish automatically or expose unrelated media folders. Listing currently scans the configured upload directory; a media index/search is a future improvement for very large libraries.

See [ROADMAP.md](ROADMAP.md) for the remaining editor ideas.

## Assets and Alpine

Prebuilt assets live in `dist/` and are served by allowlisted `GET /_editor/assets/{asset}` routes. The middleware inserts the CSS into the head and, in authenticated editing mode, the runtime/template at the end of the body. Visitors receive no editor JavaScript or draft data.

The loader waits for the page's load event so normal deferred/module scripts have finished. If `window.Alpine` exists it registers the editor and initialises only the newly inserted editor tree, without restarting Alpine on the host page. Otherwise it loads the package's bundled Alpine 3 ESM fallback, with no CDN request. Applications that keep an Alpine instance private inside a module should expose it as `window.Alpine` if they want it reused. Deliberately loading another Alpine instance after the load event is not supported.

There are no shared Alpine scopes around page content. Inline fields are bound by the package runtime across the document and synchronised with the toolbar. The CSS uses prefixed `cms-` selectors, self-hosted Inter typography, neutral tool surfaces and blue actions; it has no Tailwind dependency. Existing site scripts and Alpine scopes remain independent.

## Editing

**Layout preference:** use **Layout** to choose Left sidebar or Bottom panel. Bottom is the initial default. At `md` (768px) and above, Left reserves a 352–432px column beside the website, with a 320–400px editor and gutters. The page reflows into the remaining width and keeps normal window scrolling. Viewport-fixed elements are adjusted into that space; fixed descendants with transformed containing blocks retain their local positioning. Left stays open, including in Preview, and has no minimise or show/hide control. Exit leaves CMS mode. Bottom retains its existing minimise behaviour.

Below 768px, Bottom is always the active and selected layout; Left is disabled. The browser’s stored Left preference is retained for wider screens. Preferences use `page-editor:layout`; obsolete Right values fall back to Bottom. Layout selection does not save or alter content, and still works for the session if browser storage is blocked. Escape closes the chooser before its normal exit action.

The dock keeps editor tools separate from save/publish actions. Status reports the number of unsaved fields and distinguishes uploads, draft saves and publishing. The content list shows matching/total fields, compact shared/code-update badges, and a mobile detail view with a back control. Image selection uses a full-width upload library; image URL and alt text remain separately labelled. Metadata uses readable names such as “Social title”. Minimise and library close return keyboard focus to their opening controls. The panel is height-bounded with independently scrollable content so its actions remain reachable on small screens.

Type directly into highlighted text or use the tools panel; both stay in sync. Bold, italic and underline work through the formatting buttons and Ctrl/Cmd+B, I or U. Pasting is plain text. Save draft preserves the live version and stays in the editor; Publish makes changes visible and returns to the published page with a success notification. History loads a snapshot for review, with an explicit save/publish required. The latest 50 revisions record action, timestamp and user identifier.

Preview hides editing highlights. Exit or Escape returns to the published page; Escape is ignored during saving and text composition. Unsaved changes trigger the browser's navigation warning. Success notifications survive reloads using tab-local session storage (30-second handoff window, then six seconds of display). Saving uses fetch. A successful publish then navigates to the published page; failures leave the editor open.

Use stable identifiers unique within their source Blade file; identical repeated fields may share a name only if their defaults match. Keys use letters, numbers and underscores. Defaults remain in markup; changing an unoverridden default changes the rendered copy immediately. Publishing a value identical to its default removes the override.

## Automatic source scopes

`<x-cms field="heading">Default heading</x-cms>` belongs to the Blade file
containing the tag. A compiler hook records the application-relative source
path; deployment directory names are not part of the identity. Shared components
and included partials therefore share their text across every page that renders
them. Two different files can both use `field="heading"` without colliding.
Slots belong to the file where their CMS tag is written, not the component
receiving the slot. Do not pass the internal `source` attribute yourself.

Exceptions are explicit:

```blade
{{-- Keep this field different on each URL, even inside a shared component. --}}
<x-cms scope="page" field="heading">Page-specific heading</x-cms>

{{-- A stable shared scope that survives moving/renaming its Blade file. --}}
<x-cms scope="newsletter" field="heading">Shared heading</x-cms>
```

Title, description and Open Graph metadata remain URL-specific automatically.
Drafting a shared field makes the draft available to authorised editors on other
pages; publishing it updates visitors on every page using that field. Saving a
page includes all its registered fields, including shared component drafts.

Moving a source file, renaming a field or changing an explicit scope requires
migrating the associated stored keys (`SourceScope::key(scope, field)`). For a
file scope the scope string is `view:` plus the application-relative path;
explicit shared scopes use `named:`. Preserve draft and published values
separately and keep the original files/history as a backup. Legacy per-page
values are adopted on the next save; migrate a shared component's old override
before expecting it to appear on pages that never had that override.

After installing/upgrading the compiler hook, run `php artisan view:clear`
(or rebuild your view cache). Use file-backed views for automatic scope identity;
use `scope="page"` or an explicit named scope for runtime-generated Blade.

## Storage and security

`storage/app/page-content` stores per-page draft/published overrides and history. Persist this writable directory across deployments and include it in backups. New scoped edits are stored together in `_scoped.json` so a page save updates its shared components and page metadata atomically. Keys hash the scope plus field name. Original per-page JSON and revision history remain readable as a fallback until fields are adopted by scoped storage. Adopted fields do not fall back again when an override is removed. Query parameters do not create separate content versions.

Writes use file locks, atomic rename and optimistic version checks. Scoped storage uses one global revision: a save elsewhere can require an already-open editor to reload. Only fields rendered on the current page and relevant history are returned to its editor. The shared store retains the most recent 50 site-wide revisions; legacy histories remain in their original files. This file store targets single-server deployments; multi-server applications need shared storage with reliable locks or a database implementation. Invalid JSON fails visibly instead of overwriting content.

The web-session endpoint requires CSRF, an allowlisted email, throttling and a session-bound manifest of fields actually rendered by the server. Manifests expire after two hours; reload to refresh. The last 20 editor loads are retained per session. Drafts are never emitted to visitors. Editable HTML responses use private/no-store caching.

Formatted body values use the `__cms_html__:` marker; legacy plain text remains escaped. Saving/rendering reconstruct only strong/em/u/br tags without attributes. Title and meta-description overrides are escaped plain text. There is no arbitrary HTML, script or structural editing; image uploads are handled separately as described below.

## License

The package is released under the [MIT License](LICENSE). The bundled Inter font retains its [SIL Open Font License](dist/Inter-LICENSE.txt).

## Package development

- `src/`: provider, document processor, context, JSON store, formatter, middleware and controller.
- `routes/`: write and static-asset routes.
- `config/`: guard, empty default allowlist, excluded paths and storage directory.
- `resources/views/`: text component, inert toolbar template and runtime bootstrap.
- `resources/js/`, `resources/css/`: maintained asset sources.
- `dist/`: prebuilt assets shipped to consumers.
- `tests/`: PHP integration and JavaScript tests.

Only package developers need Node. Install its exact dev dependencies and run `npm run build` in this directory after source changes; include updated `dist/` files in releases. The application uses a Composer path repository during development. Views may be customised with `vendor:publish --tag=page-editor-views`; published overrides must be kept compatible with future package changes.

Current PHP constraints are Laravel 13, PHP 8.3+ (subject to Laravel's requirements) and ext-dom. Broader Laravel compatibility and an independent Testbench matrix remain release preparation. PHP tests currently use the host application's test bootstrap:

```sh
php artisan test packages/digizu/laravel-page-editor/tests/Feature
node --test packages/digizu/laravel-page-editor/tests/js/*.test.js
```

Checks cover permissions, metadata, automatic assets, draft isolation, formatting safety, version conflicts, notifications and both Alpine-loader paths. A synthetic-user HTTP check verifies the real reservation page. Full browser interaction/visual checks remain pending.

The SEO & social panel also discovers all existing `og:*` meta properties, including repeated images and structured properties such as `og:image:alt`. Common missing fields (title, description, image, URL, type and site name) are available with empty defaults; new tags are emitted once populated. Image editing here means entering an image URL, not uploading a file. All metadata shares the normal draft/publish workflow.

## Prompt for an AI coding agent: convert an existing page

Copy the prompt below, replacing the page URL/view and field prefix. The package must already be installed and configured.

```text
Convert [PAGE URL / BLADE VIEW] to use digizu/laravel-page-editor.
Use descriptive names for new fields; a prefix is optional because each Blade file has its own scope. Read this package's
README and inspect the current route, layout, partials and components first.

Preserve the existing wording, design, links, accessibility, form behaviour
and JavaScript interactions. This is an editing integration, not a redesign.

1. Inventory the page's headings, paragraphs, button/link labels, captions,
   badges and other authored copy. Wrap each logical text fragment like this:
   <x-cms field="page_prefix_intro_heading">Existing default text</x-cms>
   Keep defaults in Blade. Do not create a copy configuration, seed JSON,
   add controller variables, introduce a scope wrapper or import editor assets.
   The package handles the request context and assets automatically.

2. Use stable, descriptive field names: start with a letter, use only letters,
   numbers and underscores, and stay within 100 characters. Do not rename
   existing fields or overwrite stored overrides. Use stable record keys for
   loops, not positions that change when sorted. Repeated copies may share a
   field only when their defaults match exactly.

3. Keep layout elements and styled spans OUTSIDE text CMS components.
   Inside it, only strong/b, em/i, u and br formatting is supported; attributes
   are not preserved. Do not nest CMS components or wrap whole sections.
   For editable link destinations, replace the anchor itself with:
   <x-cms type="link" field="page_prefix_cta" href="/existing-path" class="existing-classes">Existing label</x-cms>
   Preserve its classes, target, rel and other attributes. Use before/after named
   slots for fixed decorative icons. Do not put this inside an existing anchor
   or wrap a JavaScript action/form submit button as a link.
   Preserve spaces between fragments outside the component. Keep the default
   on one line unless a literal line break is intended.

   Example:
   <h2 class="existing-heading">
     <x-cms field="page_prefix_heading">Existing heading</x-cms>
     <span class="existing-gradient"><x-cms field="page_prefix_heading_accent">highlight</x-cms></span>
   </h2>

4. Use x-cms directly in shared components too; do not add editable flags or
   duplicate conditional markup. Overrides follow the source Blade file automatically. Inventory
   database-managed content separately: do not silently replace its source of
   truth with page overrides. Report anything that needs a separate migration.
   Do not wrap prices/calculations, form values, JavaScript expressions,
   HTML attributes, decorative icons or text baked into images with x-cms.
   Preserve database HTML such as FAQ links and paragraph structure.

5. Leave title, meta description and Open Graph tags in their normal markup.
   The package discovers them automatically in the SEO & social editor.
   Do not insert x-cms spans inside the head or reserve body fields named
   seo_title, seo_description or og_*.

6. Check that editable text can receive clicks, particularly inside cards
   with full-size button overlays. Any stacking/pointer adjustment should
   apply only while editing. Keep non-editing navigation and interactions.

7. Verify the page renders for visitors and authorised editors, field names
   are valid, repeated defaults agree, and the default copy/formatting still
   matches. Check links, responsive layout and interactive components when
   browser tools are available. Run relevant Blade/build/tests, and verify
   draft isolation and publish behaviour using temporary storage/test users;
   do not publish changes to real content as a test. Report checks performed
   and any remaining uneditable content or limitations.
```

## Editable images

```blade
<x-cms type="image" field="hero_image"
    src="/images/hero.jpg" alt="Guests enjoying a yacht party"
    class="h-full w-full object-cover" loading="lazy" />
```

The component renders an `img`, forwards classes/dimensions/other image attributes,
and uses the same automatic source scope as text. The markup supplies the original
image and alt text. Do not supply `srcset` or a surrounding `picture` source that
would override its editable `src`. Video sources and posters are not image fields.

In edit mode, click an image or select it in **Content to edit** (also useful for
background images behind overlays). Replace its URL, upload a replacement, edit
alt text, or reset to the markup default. Empty alt text marks a decorative image.
Draft, publish and revision history include images. Repeated instances with the
same field/defaults update together, including gallery thumbnails.

Uploads use `images.disk` (`public`), `images.directory` (`cms-images`) and
`images.max_kb` (10240) in configuration. For Laravel's local public disk, run
`php artisan storage:link` so uploaded files can be served. The disk must provide
browser-accessible URLs. No application asset build is required.

The upload endpoint requires an authenticated allowed email, CSRF and an unexpired
manifest authorising the image field. JPEG, PNG, WebP and GIF files are accepted;
SVG and executable files are excluded. Dimensions are limited to 12,000 pixels
per side. Laravel generates filenames. URLs accept HTTP(S) and root-relative
paths; the server does not fetch remote URLs. No cropping or focal-point tools
are included yet.

JSON stores image URL/alt values, not binary files. Uploading alone does not save
or publish a page, but the uploaded file itself has a public URL. Previous and
unused uploads are retained for history; automatic cleanup is not implemented.
Back up the configured image disk alongside the CMS JSON directory.

When converting pages with an AI agent, replace their ordinary images with the
component above, preserving styling, loading, dimensions and Alpine attributes.
Use stable semantic field names for loop entries. Keep gallery images and their
thumbnails on the same field with identical defaults. Leave video and responsive
picture/srcset handling unchanged until explicitly integrated.

## Content browser

The editor uses a searchable content list with text previews, image thumbnails
and All / Text / Images filters. Labels omit source filenames; field names
remain stable internally. SEO and revision history have their own panels. On
mobile the list and selected editor use separate views with a Back to content
control. Find on page scrolls to the selected visible instance. Inter is bundled
under its included SIL Open Font License; no external font request is made.

## Local development reset

With `APP_ENV=local`, **Reset CMS** appears beside Show/Hide tools. After confirmation,
it removes all CMS JSON overrides/history and the configured CMS image-upload
folder, clears CMS-prefixed browser local/session storage, and returns to the
published page using markup defaults. Other application files and browser keys
are retained. Empty lock files remain to preserve lock handles.

The endpoint also checks the environment server-side, requires an authorised
editor and CSRF, and refuses remote disks or a disk-root image directory. This
is irreversible; other open editors should reload after a reset. The reset is
not run as part of installation or upgrading the package.


## Editable links

```blade
<x-cms type="link" field="reviews_cta" href="/reviews" class="your-button-classes">
    Read all reviews
</x-cms>
```

This renders the anchor itself, so replace the existing `<a>` rather than nesting it. All existing classes and attributes are retained. Link text is plain text; decorative markup may use `<x-slot:before>` or `<x-slot:after>`. New-tab anchors retain their target and receive `noopener noreferrer`. Do not also bind `href` dynamically with JavaScript on the same anchor.

In edit mode, clicking the link selects its **Link text** and **Destination** controls. The **Links** filter finds these fields. Changes preview on the page, support undo/redo and reset, and follow the normal draft/publish workflow. Preview mode restores navigation. Invalid destinations are not applied to the live preview and are rejected on save by the server.

Destinations support HTTP(S), root-relative paths (`/reviews`), fragments (`#guests`), query strings (`?sort=latest`), `mailto:` email addresses and `tel:` phone numbers. Executable schemes, protocol-relative URLs, backslashes and control characters are rejected. Keep action buttons and booking form submissions in application code.

Shared scopes and changed-markup precedence work as for other fields. If converting a text field with a tracked default into a link, that type change counts as a new code default. Older untracked text overrides retain their label and use the new markup destination. Restoring a text-only revision keeps its label and pairs it with the current markup destination.

The homepage’s **Read all reviews** link is enabled as an initial integration. Other links can be converted individually using the example above.
