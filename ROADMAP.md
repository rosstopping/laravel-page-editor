# Page editor roadmap

Keep the editor portable, lightweight and independent of Nova. Markup remains the default; storage contains overrides. These are future ideas, not enabled features.

## Delivered

- [x] Changes tab with unpublished and published-vs-code comparisons, word highlights, image previews, and optional on-page outlines.

- [x] Whole-site CMS export/import from the editor, including uploaded images and portable image URLs.

- [x] Session undo/redo across text, formatting, metadata, image changes and revision restores.
- [x] Shared content badges and an explanation of where published changes apply.
- [x] Reusable image library for existing CMS uploads with thumbnails and pagination.

- [x] Editable links with label/destination controls, URL validation, live preview and preserved button styling.

- [x] User-selectable persistent left sidebar beside the website, or minimisable bottom panel; remembered preference with bottom enforced below md.

## Next candidates

- [ ] **Friendly field labels:** optional `label` on `<x-cms>` with automatic readable labels remaining the default.
- [ ] **Image focal points:** select the part of an image to keep visible across responsive crops without changing page layout.
- [ ] **Mobile preview:** preview edits at common viewport sizes without leaving the draft workspace.

## Later refinements

- [ ] **Sync published CMS overrides back to Blade:** add a local-only “Generate Blade patch” action to the CMS overrides view. Select published changes, locate components by source file, scope and field name, and review the proposed code diff before applying it. Verify the new defaults before clearing corresponding overrides. Handle straightforward text, link and image defaults deterministically; flag dynamic expressions, loops and page-specific values sharing a template for manual review. Consider optional AI assistance for ambiguous cases later.
- [ ] Searchable media names and an indexed listing for larger libraries, avoiding full storage scans.
- [ ] Clear image usage information before offering media deletion; preserve files referenced by published content and revisions.

Prioritise the next item based on actual editing needs; avoid adding a database requirement or consumer asset build solely for these features.
