# Custom Fields Studio (CFS)

An internal WordPress plugin providing ACF Pro-style custom fields — field groups,
location rules, repeater and flexible content fields, options pages, and a
template API — built for agency use across client sites.

This is an original implementation, not a copy of ACF Pro's code. Concepts
(field groups, location rules, repeater/flexible content patterns) are common,
well-documented WordPress plugin patterns.

## Status

🚧 In active development — phase by phase.

## Phases

- [ ] **Phase 1** — Field Group CPT, admin list/edit screen, basic field types
      (text, textarea, select, true/false, WYSIWYG, image), location rules
      (post type only)
- [ ] **Phase 2** — Meta box rendering on post edit screen, save handler,
      template API (`cfs_get_field()`, `cfs_the_field()`)
- [ ] **Phase 3** — Repeater field
- [ ] **Phase 4** — Flexible Content field
- [ ] **Phase 5** — Options Pages
- [ ] **Phase 6** — Remaining field types (gallery, relationship, taxonomy, date picker)
- [ ] **Phase 7** — Field group builder UI polish (drag-drop ordering, settings panel)
- [ ] **Phase 8** — Testing & edge cases

## Storage approach

Post meta, ACF-style serialization — WP-native, compatible with existing
plugins/queries. See architecture notes in `/docs` (added as phases land).

## Branching

- `main` — stable, tested phases only
- `develop` — integration branch
- `feature/phase-N-description` — one branch per phase

## Requirements

- WordPress 6.x
- PHP 8.0+
