# Security audit

Audited commit: `e1377331db18e4a708fb228d0787a58919cd54a4`

## Scope and method

Reviewed routes, authorization, session manifests, content validation, HTML and URL handling, uploads, JSON storage, reset behaviour, and editor JavaScript. Used an isolated temporary Laravel 13.31.0 container with the package's actual classes and existing framework dependencies. No host application was booted, no application environment file was loaded, and no production content was changed.

Three flaws were reproduced. Severity depends on the deployment conditions below. This is a source review with targeted component tests, not a complete production penetration test.

## 1. High, conditional: unverified email addresses grant editor access

**Location:** `src/PageEditorServiceProvider.php:30`, `routes/web.php`, `src/Http/Middleware/PreparePageEditor.php:16`.

The `edit-page-content` gate compares the authenticated user's email with the allowlist but does not check ownership of that email. It accepts a user implementing Laravel's `MustVerifyEmail` contract even when `hasVerifiedEmail()` returns false. Editor endpoints do not require verified email either.

**Attack conditions:** the application lets someone register an unused allowlisted email, or change their account email to an allowlisted address, before proving ownership. The attacker must know or guess an allowlisted address. This does not bypass password authentication for an existing account whose email cannot be reassigned.

An attacker can log into that unverified account, visit a public page to obtain a legitimate editor manifest, and use normal save/publish/upload operations. Adding `verified` only to an application's dashboard does not protect these public-page editing flows or the package endpoints.

**Evidence:** executing the package's registered gate with an allowlisted user produced `verified=false; gate=true`.

**Fix:** authorize a stable, explicitly provisioned user identity or role. If email remains the authorization mechanism, require verified ownership in the central gate and enforce an explicit policy for user models without verification support. Test both public-page editor access and mutation endpoints with an unverified account. Laravel provides the [MustVerifyEmail contract](https://api.laravel.com/docs/13.x/Illuminate/Contracts/Auth/MustVerifyEmail.html).

## 2. Medium: public page reads create unlimited persistent lock files

**Location:** `src/Services/PageContentStore.php:119`, `src/Services/PageEditorContext.php:39`, `src/Http/Middleware/PreparePageEditor.php:23`.

Every content read opens a per-page lock file with `fopen(..., 'c')`, creating it if absent. Files remain after the read. This happens for unauthenticated requests because the document processor automatically registers metadata, including on pages with no CMS components.

**Attack conditions:** the host application serves processable HTML on an unbounded set of distinct paths, such as a catch-all route. Arbitrary URLs returning an ordinary 404 before document processing are not automatically affected. Query-string changes alone do not create different page keys.

An unauthenticated client can accumulate filesystem entries without saving any content. At sufficient volume this can exhaust inodes or filesystem metadata capacity and disrupt the application. The package's editor-endpoint rate limits do not apply to these public GET requests.

**Evidence:** passing 100 distinct public requests with successful HTML responses through the actual middleware produced **101 persistent lock files and zero JSON files**: one lock per path, plus the shared-store lock. No exhaustion attack was performed.

**Fix:** make read-only access avoid creating per-URL files. Use atomic file snapshots for reads where appropriate, or a bounded lock scheme coordinated with writers. Do not simply unlink active lock files: that can undermine mutual exclusion between concurrent processes.

**Remediation in the working tree:** reads now load atomic JSON snapshots without taking locks or creating storage. Repeating the 100-public-request check produced zero lock files and zero JSON files. Six regression tests passed, covering missing storage, stale writes, malformed JSON, path validation, reads during a held writer lock, and concurrent publications. Write locks remain in place; existing lock files have not been deleted.

## 3. Medium: `/_scoped` collides with the internal shared content store

**Location:** `src/Services/PageEditorContext.php:20`, `src/Services/PageEditorContext.php:41`, `src/Services/PageEditorContext.php:79`, `src/Services/PageContentStore.php:14`.

Simple URL paths become storage names directly. The public path `/_scoped` therefore selects `_scoped.json`, which is also the internal site-wide store. The default excluded paths do not reserve this name.

**Attack conditions:** the application serves a processable page at `/_scoped`, directly or via a catch-all route, and an allowlisted editor opens it. A metadata-only page, or one using only page-scoped fields, can select the legacy save path. A rendered manifest is still required; posting that identifier alone is insufficient.

In that case, the bootstrap exposes unrelated shared content through its unfiltered published state and revision history. Publishing this page replaces the shared store's published map with only that page's submitted values, removing other published overrides. This breaks the intended restriction to fields actually rendered on the page. History may allow recovery; this is not necessarily irreversible data loss.

**Evidence:** seeded a separate shared field with a published value and a private draft. A `/_scoped` page produced a manifest with `scoped=false` and exposed the unrelated draft in history. A valid request passed through the actual publish controller returned HTTP 200 and removed the separate field from the shared published map.

**Fix:** separate internal storage and page storage namespaces, reserve internal identifiers, and migrate existing page keys safely. Filter all bootstrap state, including legacy published values and history, to the current manifest's fields. Add regression coverage for reserved names and page/internal-key collisions.

**Remediation in the working tree:** page records now use a separate `pages/` namespace, accessed independently from `readScoped()`. URL identities are consistently hashed. The compatibility fallback rejects the internal shared-store name and the context rejects literal legacy hash aliases. Page state/history and save responses are filtered to rendered fields, while saves preserve other fields. Old session manifests require a reload. The original collision check now shows no unrelated draft disclosure and the shared publication survives the page save. Regression coverage includes legacy migration, scoped identity compatibility, old-manifest rejection, and local reset across both layouts.

## Other deployment and dependency concerns

### No tenant or hostname isolation

Page identities use the URL path alone. Source and named scopes also omit tenant identity, and the image library lists the configured shared upload directory. Two hosts serving `/about` from the same storage configuration use the same page identity; the isolated check confirmed this.

This is a confidentiality and integrity concern if the package is installed in a multi-tenant application expecting isolated content. It is not a cross-site exploit between separate installations with independent storage. Partition content storage, image storage, and editor authorization by a trusted tenant identifier before using it in that setting.

### esbuild development dependency advisory

`package.json` pins esbuild `0.18.20`, which falls within the affected range of [GHSA-67mh-4wv8-2f99](https://github.com/evanw/esbuild/security/advisories/GHSA-67mh-4wv8-2f99). The advisory concerns websites reading responses from esbuild's development server; the fix starts at `0.25.0`.

The checked-in build script uses `build()`, not the vulnerable server API. This is dependency maintenance work, not a demonstrated vulnerability in the deployed PHP package or its generated JavaScript. Upgrade to a currently supported patched version and verify the build. No complete application dependency audit was possible because the standalone package has no resolved Composer dependency lockfile.

### Upload capacity and library cost

Uploads have per-file limits and rate limiting, but no total storage quota or automatic cleanup. Each library request scans and sorts the whole configured directory before returning 24 entries. A compromised editor account or a large library can consume disk space and server resources. Consider quotas and indexed pagination. This requires editor authorization and is lower priority than the findings above.

## Protections and checks

- Mutations check the editor gate and use the `web` middleware group; CSRF protection depends on the host retaining Laravel's normal middleware configuration.
- Save and image operations require a session-bound, expiring manifest. Saves constrain submitted fields to that manifest.
- Filesystem page names and public asset names are restricted; no request-controlled traversal was found in those paths.
- Bootstrap JSON uses the `JSON_HEX_*` flags; text, metadata, and attributes are escaped or reconstructed through restricted formatting.
- Ten server-side HTML attack payloads produced no executable elements or attributes in parsed output. Ten unsafe URL payloads were rejected by both URL validators. These are targeted checks, not exhaustive sanitizer verification.
- No server-side fetching of editor-supplied image URLs was found.
- Upload validation restricts type, size, and dimensions; filenames are generated by Laravel.
- All 23 existing JavaScript tests passed.

The existing PHP feature suite depends on a host application test bootstrap and was not run. The isolated checks exercised real package middleware, document processing, gate, context, store, and publish controller; they did not exercise a full HTTP kernel, browser, or production infrastructure. CSRF and upload handling were reviewed but not end-to-end tested. No confirmed stored XSS, SQL injection, remote code execution, or unauthenticated content-write bypass was found within this scope.

## Priority

Fix email-based authorization first where public registration or email changes are available. Fix read-created lock files and the reserved-name collision before a stable release. Keep this audit private until fixes and any required release communication are prepared.
