# Laravel Page Editor

On-page editing for Laravel Blade views, with drafts, publishing, revision history, and editable text, links, images, and SEO metadata.

**Beta:** APIs and behaviour may change before the first stable release.

## Requirements

- Laravel 13 and a PHP version supported by your Laravel installation.
- PHP DOM extension (`ext-dom`).
- An existing session-based login using Laravel authentication.
- Writable, persistent storage for content and uploaded images.

## Installation

### 1. Install the package

```sh
composer require digizu/laravel-page-editor:"^0.1.0@beta"
```

The service provider, middleware, Blade components, and editor assets register automatically. No frontend build or manual asset imports are needed.

### 2. Publish the configuration

```sh
php artisan vendor:publish --tag=page-editor-config
```

Add editor email addresses to `config/page-editor.php`:

```php
'allowed_emails' => [
    'editor@example.com',
],
```

Nobody can edit until their email is listed. The package uses your application's login; it does not provide a login screen. To use a different authentication guard, set `PAGE_EDITOR_GUARD` in `.env`.

### 3. Clear cached configuration and views

```sh
php artisan config:clear
php artisan view:clear
```

Rebuild your configuration and view caches as usual during deployment. Clear or rebuild compiled views after package upgrades too.

### 4. Add editable content

```blade
<h1>
    <x-cms field="heading">Your default heading</x-cms>
</h1>
```

Sign in as an allowed user and open the page. Click **Edit page**, or append `?edit=1` to the URL.

Pages must use the `web` middleware group and return a complete HTML document with `<head>` and `<body>` elements. Paths in `excluded_paths` bypass the editor; add your administrative routes to this list.

## Blade components

### Text

```blade
<p>
    <x-cms field="intro">Welcome to <strong>our website</strong>.</x-cms>
</p>
```

The component renders a span. Keep layout elements, styling, and structural markup outside it. Supported inline formatting is bold, italic, underline, and line breaks (`strong`/`b`, `em`/`i`, `u`, `br`). Formatting attributes are stripped; unsupported tags display as text. Do not nest CMS components.

### Links

```blade
<x-cms type="link" field="contact_link" href="/contact" class="button">
    Contact us
</x-cms>
```

This renders an anchor with editable text and destination. Replace the existing `<a>` element and retain its attributes on the component. Link text is plain text; use `before` and `after` named slots for fixed icons or decorative markup.

Destinations support HTTP(S), root-relative paths, fragments, query strings, `mailto:`, and `tel:`. Do not bind `href` dynamically on the same element. Keep form submissions and JavaScript action buttons in application code.

### Images

```blade
<x-cms type="image" field="hero_image"
    src="/images/hero.jpg"
    alt="A description of the image"
    class="w-full"
    width="1200"
    height="800"
    loading="lazy"
/>
```

This renders an image and preserves its additional attributes. Editors can change its URL and alt text, upload a file, or select a previous upload. Do not combine it with `srcset` or `<picture>` sources that override the editable `src`.

For uploads on the default `public` disk, run:

```sh
php artisan storage:link
```

Upload settings live in `config/page-editor.php`:

```php
'images' => [
    'disk' => 'public',
    'directory' => 'cms-images',
    'max_kb' => 10240,
],
```

The disk must provide browser-accessible URLs. Uploads support JPEG, PNG, WebP, and GIF, up to 10 MB by default and 12,000 pixels per dimension. Uploaded files are public immediately; changing the page's image still requires publishing. Unused uploads are retained.

### SEO metadata

Keep your normal `<title>`, meta description, and Open Graph tags in the document head. The editor discovers them automatically in **SEO & social**. Do not put CMS components inside the head or use `seo_title`, `seo_description`, or `og_*` as body field names.

## Field names and sharing

Field names must start with a letter, contain only letters, numbers, and underscores, and be at most 100 characters long. Use stable names; in loops, use stable record identifiers rather than list positions. Repeated instances of a field must have matching defaults.

By default, fields belong to their source Blade file. Every page rendering that file shares its values, including shared components and partials. Different files can use the same field names independently.

Use an explicit scope when needed:

```blade
{{-- Separate values for each URL, even when using the same template. --}}
<x-cms scope="page" field="heading">Page heading</x-cms>

{{-- Share a value across files using a stable name. --}}
<x-cms scope="newsletter" field="heading">Newsletter heading</x-cms>
```

SEO metadata is always URL-specific. Query parameters do not create separate content versions. For runtime-generated Blade, set a page or named scope explicitly.

Renaming a field, moving its source file, or changing its scope changes its storage identity. Existing edits need migration if you want to retain them. A named scope avoids tying the identity to a file path.

## Drafts and publishing

- **Save draft** saves edits without changing the published page.
- **Publish** makes edits visible to visitors.
- **History** restores a revision into the editor; save or publish to apply it.
- Shared fields publish across every page using them. Saving a page includes its shared fields.

Visitors never receive draft content. The store retains the latest 50 site-wide revisions. If another editor saves while your page is open, a version conflict may require reloading.

### Changing defaults in code

Defaults stay in Blade. Changing a field's default in code takes precedence over previously saved edits to that field, including published edits. Keep defaults deterministic; avoid request-dependent values or wrapping database-managed content that should remain controlled by your application.

Publishing a value identical to its default removes the override.

## Deployment

- Persist and back up `storage/app/page-content`, or the directory configured in `page-editor.path`.
- Persist and back up the configured image disk. No database migration is required.
- The JSON content store targets single-server deployments. Multiple servers require shared storage with reliable file locking.
- Editable responses use `Cache-Control: private, no-store`; ensure any page cache or CDN respects this.
- If your application bundles Alpine, expose it as `window.Alpine` to reuse it. Otherwise the editor loads its bundled copy automatically.

In `APP_ENV=local`, **Reset CMS** permanently deletes all CMS content, history, and files in the configured CMS image directory. Use a dedicated upload directory.

### Moving CMS content between environments

Open **Edit page → Transfer** on the source site and click **Export CMS**. Save any unsaved edits first. On the destination site, open the same section, choose the downloaded ZIP, and click **Import CMS**. This works in either direction, including imports into production, for users on the editor allowlist.

The export includes the entire site's saved drafts, published overrides, revision history, default fingerprints, legacy page files, and JPEG/PNG/WebP/GIF uploads from the configured CMS image directory. It does not include Blade templates, application code, configuration, or externally hosted images. Keep exports private: they include unpublished content and revision author IDs.

**Import replaces CMS content across the destination site; published values go live immediately.** This is a whole-site snapshot, not a field-by-field merge. Export the destination first if you may need to restore its content. Existing destination uploads are retained; imported images get fresh filenames and their URLs are updated in content and history to use the destination disk. Other URLs, including links and external images, remain unchanged.

Use matching application code, field names, scopes, and Blade paths on both environments. Default changes in code still take precedence over imported overrides. Revision author IDs are preserved without mapping users between databases. The importing browser leaves edit mode after success; other open editors must reload before saving.

Transfers require PHP's `ext-zip` extension and a dedicated CMS image directory. Defaults in `page-editor.transfer` allow a 100 MB upload and 500 MB of uncompressed archive data, with a maximum of 10,000 ZIP entries and a 16 MB manifest. Individual images must fit `page-editor.images.max_kb` and the normal image dimension limits. PHP's `upload_max_filesize`, `post_max_size`, web server request limits, and request timeouts also apply. Larger libraries may need these limits adjusted.

Transfers share a storage lock with CMS saves, uploads, and resets. Each content document is replaced atomically, and failed writes attempt to restore the previous documents and remove newly imported images. Keep normal storage backups for process interruptions or storage failures. Visitors can continue reading during transfers; a request spanning multiple documents may observe the transition while they are replaced.

### Upgrading the content store

Page files now use hashed names under `page-content/pages/`, separate from shared content. Existing page files are read through a compatibility fallback and migrated on their next page save; the originals are retained. Back up the entire content directory.

Deploy the update to all instances together and restart long-running workers so old and new storage writers do not run at the same time. Reload open editors before saving. Legacy files using reserved internal names are not imported as page content.

## License

[MIT](LICENSE). The bundled Inter font uses the [SIL Open Font License](dist/Inter-LICENSE.txt).
