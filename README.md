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
- [x] **Phase 5** — Options Pages (code + UI registry, `options_page` location rule, `'option'` context)
- [x] **Phase 6** — Remaining field types (gallery, relationship, taxonomy, date picker)
- [x] **Phase 7** — Field group builder UI polish (drag-drop ordering, settings panel)
- [x] **Phase 8** — Testing & edge cases
- [x] **Phase 9** — More field types: number, range, email, URL, password, checkbox, radio, button group, file, color picker, time picker, date-time picker, link, oEmbed
- [x] **Phase 10** — Layout fields (group, tab, accordion, message), conditional logic, post object / page link / user fields
- [x] **Phase 11** — JSON import/export + local JSON sync, REST API exposure, extra location rules, clone field
- [x] **Phase 12** — Custom Gutenberg blocks, Google Map, WPML / Polylang

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

### Testing

See [`tests/README.md`](tests/README.md): the *CFS QA Suite* companion plugin seeds 3 field groups, 3 demo
pages and 2 options pages covering every field type/option, and runs ~235 checks per phase; Playwright
end-to-end tests drive the same content in a browser.

### Phase 11: JSON, REST, location rules, clone

**More location rules.** Besides post type and options page: a specific post, post status, page template,
post category, post taxonomy term, the current user's role, the role of the user being edited (field groups on
**user profile screens**) and a taxonomy (field groups on **term edit screens**). Rules are OR-groups of AND-rules.
Values live in user meta / term meta; read them with `cfs_get_field( 'x', 'user_5' )` / `'term_3'` (a `WP_User`
or `WP_Term` object works too). A group that was on screen when you press Publish still saves, even if publishing
(or a new page template) makes its rule stop matching.

**Clone field.** Reuse the fields of other field groups: display *group* (one named group of the cloned fields)
or *seamless* (fields appear in place, optionally prefixed with the clone's name). Clones are resolved when
groups load, so templates, shortcodes and the REST API see ordinary fields. Clones can be nested; groups that
clone each other are cut off safely.

**JSON import / export** (*Field Groups → Tools*). Export any set of groups to a `.json` file and import it on
another site; groups carry a stable key, so importing again updates instead of duplicating, and everything goes
through the same sanitizers as the builder.

**Local JSON.** Create a writable `cfs-json` folder in your theme (or point the `cfs/json_dir` filter at one) and
every saved field group is also written there as `{key}.json`, so field groups can live in git. Files that are new
or newer than the database copy are offered on the Tools page as *Sync changes*.

**REST API.** Tick *Show in REST API* on a group to expose it:

- a `cfs` field on posts, pages, other REST post types, users and terms (read and write; write needs the usual
  edit capability; `?cfs_format=1` on a read returns formatted values instead of raw ones)
- `GET`/`POST /wp-json/cfs/v1/options` (reading is public like the front end; writing needs `manage_options`)
- `GET /wp-json/cfs/v1/groups` — field schema of the exposed groups (needs `edit_posts`)

### Phase 12: blocks, Google Map, multilingual

**Custom Gutenberg blocks.** Register a block, then give it fields with a field group whose location rule is
*Block == your block*:

```php
cfs_register_block( array(
    'name'            => 'testimonial',          // becomes cfs/testimonial
    'title'           => 'Testimonial',
    'icon'            => 'format-quote',
    'render_template' => get_theme_file_path( 'blocks/testimonial.php' ),
) );
```

or drop `cfs-blocks/{slug}/block.json` + `render.php` into the theme. In the template, `cfs_get_field( 'quote' )`,
`cfs_have_rows()` and the other template functions read the block's own values. In the editor the block shows a live
preview; the toolbar button switches to the field form. Values are stored in the block's attributes (inside the post
content), not in post meta, and are re-sanitised by the field types every time the block renders. WYSIWYG fields show
as plain textareas inside blocks.

**Google Map field.** Address, latitude, longitude and zoom. Works with typed coordinates; add a Google Maps API key
(*Field Groups → Tools*, or the `cfs/google_maps_api_key` filter) for a searchable, draggable map in the editor. The
`[cfs]` shortcode renders a keyless Google embed.

**WPML / Polylang.** *Tools → Store Options Page values separately for each language* keeps `options_fr_…` values per
language (the default language keeps the plain keys). Each field has a *Translation* setting (translate / copy /
ignore); with Polylang, *copy* fields — including repeater and group sub values — are copied to translations, and
*ignore* fields never are. For WPML, use WPML's own custom-field translation settings.

### Phase 6 field types

- **Date Picker** — stored as `Ymd`; `return_format` is a PHP date format.
- **Gallery** — array of image IDs; returns arrays/URLs/IDs; optional max.
- **Relationship** — searchable post picker (AJAX, nonce + capability checked); returns post objects or IDs.
- **Taxonomy** — checkbox / multi select / radio / select of terms; optionally assigns terms to the post.

All four work inside Repeater, Flexible Content and Options Pages, and render with the `[cfs]` shortcode.

### Options Pages

Site-wide values (phone number, social links, footer blocks…). Create a page
under **Field Groups → Options Pages**, or register one from code:

```php
add_action( 'init', function () {
    cfs_add_options_page( array(
        'page_title' => 'Site Options',
        'menu_slug'  => 'site-options',
    ) );

    // Under an existing menu (Settings here):
    cfs_add_options_sub_page( array(
        'page_title'  => 'Footer',
        'menu_slug'   => 'site-footer',
        'parent_slug' => 'options-general.php',
    ) );
} );
```

Then create a field group with the location **Options Page is equal to Site
Options** and read the values by passing `'option'` where a post ID goes:

```php
echo esc_html( cfs_get_field( 'phone', 'option' ) );

while ( cfs_have_rows( 'social_links', 'option' ) ) {
    cfs_the_row();
    echo esc_url( cfs_get_sub_field( 'url' ) );
}
```

or in content: `[cfs field="phone" post_id="option"]`. Values live in
`wp_options` as `options_{name}` (not autoloaded). All options pages share one
namespace, so keep field names unique across them. A page's capability
(default `manage_options`) controls who can open and save it.

After leaving a loop early with `break`, call `cfs_reset_rows()`.

## Branching

- `main` — stable, tested phases only
- `develop` — integration branch
- `feature/phase-N-description` — one branch per phase

## Requirements

- WordPress 6.x
- PHP 8.0+
