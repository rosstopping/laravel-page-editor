# Page editor roadmap

Keep the editor portable, lightweight and independent of Nova. Markup remains the default; storage contains overrides. These are future ideas, not enabled features.

## Delivered

- [x] Whole-site CMS export/import from the editor, including uploaded images and portable image URLs.

- [x] Session undo/redo across text, formatting, metadata, image changes and revision restores.
- [x] Shared content badges and an explanation of where published changes apply.
- [x] Reusable image library for existing CMS uploads with thumbnails and pagination.

- [x] Editable links with label/destination controls, URL validation, live preview and preserved button styling.

- [x] User-selectable persistent left sidebar beside the website, or minimisable bottom panel; remembered preference with bottom enforced below md.

## Next candidates

- [ ] **Review before publishing:** show changed fields with before/after text and image previews, including shared-content warnings.
- [ ] **Friendly field labels:** optional `label` on `<x-cms>` with automatic readable labels remaining the default.
- [ ] **Image focal points:** select the part of an image to keep visible across responsive crops without changing page layout.
- [ ] **Mobile preview:** preview edits at common viewport sizes without leaving the draft workspace.

## Later refinements

- [ ] Searchable media names and an indexed listing for larger libraries, avoiding full storage scans.
- [ ] Clear image usage information before offering media deletion; preserve files referenced by published content and revisions.

Prioritise the next item based on actual editing needs; avoid adding a database requirement or consumer asset build solely for these features.
