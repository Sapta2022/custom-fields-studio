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

- [x] **Phase 1** — Field Group CPT, admin list/edit screen, basic field types
      (text, textarea, select, true/false, WYSIWYG, image), location rules
      (post type only)
- [x] **Phase 2** — Meta box rendering on post edit screen, save handler,
      template API (`cfs_get_field()`, `cfs_the_field()`, `cfs_get_fields()`)
- [x] **Phase 3** — Repeater field (nested repeaters, min/max rows, drag-reorder,
      WYSIWYG/image sub fields, `cfs_have_rows()` loop API)
- [x] **Phase 4** — Flexible Content field (layouts, per-row layout storage, `cfs_get_row_layout()`)
- [ ] **Phase 5** — Options Pages
- [ ] **Phase 6** — Remaining field types (gallery, relationship, taxonomy, date picker)
- [ ] **Phase 7** — Field group builder UI polish (drag-drop ordering, settings panel)
- [ ] **Phase 8** — Testing & edge cases

## Storage approach

Post meta, ACF-style — WP-native, compatible with existing plugins/queries.
A repeater stores its row count under its own name and each cell under a
compound key, with a `_`-prefixed reference to the field key beside it:

```
team                 = 2
_team                = field_1a2b3c
team_0_name          = Bob
team_1_name          = Alice
team_1_links         = 2          (a nested repeater: its own row count)
team_1_links_0_url   = https://…
```

A flexible content field stores the layout name of each row as a list under its
own name; each row's cells use the same compound keys as a repeater:

```
sections             = [hero, text_block]     (layout per row)
_sections            = field_9f8e7d
sections_0_heading   = Welcome                (row 0 is a "hero")
sections_1_body      = <p>Hello</p>           (row 1 is a "text_block")
```

Reads and writes go through `CFS_Storage`, so the same field code will serve
Options Pages (`'option'` context) in a later phase.

## Template API

```php
// Simple fields
echo cfs_get_field( 'subtitle' );
cfs_the_field( 'subtitle' );

// Repeater (nested repeaters work the same way, inside the outer loop)
while ( cfs_have_rows( 'team' ) ) {
    cfs_the_row();
    echo esc_html( cfs_get_sub_field( 'name' ) );

    while ( cfs_have_rows( 'links' ) ) {
        cfs_the_row();
        echo esc_url( cfs_get_sub_field( 'url' ) );
    }
}

// Or take the rows as an array
$rows = cfs_get_field( 'team' );
```

```php
// Flexible content: branch on the row's layout
while ( cfs_have_rows( 'sections' ) ) {
    cfs_the_row();

    switch ( cfs_get_row_layout() ) {
        case 'hero':
            echo '<h1>' . esc_html( cfs_get_sub_field( 'heading' ) ) . '</h1>';
            break;
        case 'text_block':
            echo wp_kses_post( cfs_get_sub_field( 'body' ) );
            break;
    }
}
```

Or render without PHP, anywhere shortcodes work (pages, posts, widgets):

```
[cfs field="sections"]
[cfs field="team" post_id="21"]
```

The output is plain HTML with `cfs-out-*` classes for styling.

After leaving a loop early with `break`, call `cfs_reset_rows()`.

## Branching

- `main` — stable, tested phases only
- `develop` — integration branch
- `feature/phase-N-description` — one branch per phase

## Requirements

- WordPress 6.x
- PHP 8.0+
