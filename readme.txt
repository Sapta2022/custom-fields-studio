=== Custom Fields Studio ===
Contributors: sapta2022
Tags: custom fields, repeater, flexible content, blocks, options page
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.13.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom fields for posts, pages, users, terms, options pages and Gutenberg blocks, with repeater, flexible content, clone and conditional logic.

== Description ==

Custom Fields Studio lets you build field groups in the WordPress admin and show them wherever you need them: on posts and pages, on user profiles, on taxonomy terms, on options pages and inside your own Gutenberg blocks.

**Field types (35)**

Text, textarea, WYSIWYG, number, range, email, URL, password, select, checkbox, radio, button group, true/false, image, file, gallery, date, time, date-time, colour, link, oEmbed, Google Map, post object, page link, user, relationship, taxonomy, message, tab, accordion, group, clone, repeater and flexible content.

**Features**

* Field group builder with drag-and-drop ordering and per-field settings
* Location rules: post type, post ID, status, page template, category, taxonomy, user role, options page and block, combined with AND / OR
* Conditional logic: show or hide a field based on other fields
* Repeater and flexible content, including nested ones
* Clone fields to reuse a group inside another
* Options pages, user profile fields and term fields
* Custom Gutenberg blocks driven by your field groups (`cfstudio_register_block()`)
* JSON export and import, plus optional local JSON sync to a theme folder
* REST API: values on posts, users and terms, and `/cfstudio/v1/options`
* Template functions (`cfstudio_get_field()`, `cfstudio_have_rows()`, `cfstudio_the_row()`, `cfstudio_get_sub_field()`) and the `[cfs]` shortcode
* Optional per-language options for Polylang and WPML sites
* Google Map field works with typed coordinates, and adds a searchable map when you add a Google Maps API key

== Installation ==

1. Upload the plugin to `/wp-content/plugins/custom-fields-studio/`, or install it from the Plugins screen.
2. Activate it through the Plugins screen.
3. Go to **Field Groups > Add New**, add fields and choose where they should appear.
4. In your theme, print values with `cfstudio_get_field( 'field_name' )` or the `[cfs field="field_name"]` shortcode.

== Frequently Asked Questions ==

= How do I show a field on the front end? =

Use `cfstudio_get_field( 'name' )` in a template, or `[cfs field="name"]` in content. For options pages use `[cfs field="name" post_id="option"]`; for users `post_id="user_1"`; for terms `post_id="term_5"`.

= How do I add a custom block? =

Register it with `cfstudio_register_block()` in your theme or a small plugin, then create a field group whose location rule is "Block is equal to" your block.

= Does the Google Map field need an API key? =

No. Without a key you type the address, latitude and longitude. With a Google Maps API key (Field Groups > Tools) the editor shows a searchable, draggable map.

= Is it multisite compatible? =

Multisite has not been tested.

= Where is my data stored? =

Values are saved as post, user, term or option meta in your own database. Nothing is sent to external servers. The optional Google Map editor widget loads Google's scripts only if you add an API key.

== Changelog ==

= 0.13.1 =
* Fix: block names saved in field-group location rules are now migrated too.

= 0.13.0 =
* Internal rename of the code prefix from cfs to cfstudio (functions, hooks, options, post type, REST namespace `cfstudio/v1`, block namespace `cfstudio/`). Existing data is migrated automatically on first load.
* Theme developers: template functions are now cfstudio_get_field(), cfstudio_have_rows(), cfstudio_the_row(), etc.

= 0.12.4 =
* Fix: fields inside custom blocks (repeater rows, media pickers, relationship) now work when the block editor runs in an iframe.

= 0.12.3 =
* Design: checkboxes and radios use the plugin accent colour inside plugin screens.

= 0.12.0 – 0.12.2 =
* Custom Gutenberg blocks, Google Map field, per-language options, refreshed admin design.

= 0.11.0 =
* JSON import/export and local JSON sync, REST API, extra location rules, clone field, user and term fields.

== Upgrade Notice ==

= 0.13.1 =
Fixes custom blocks losing their field group after the 0.13.0 prefix rename.

= 0.13.0 =
Template functions were renamed from cfs_* to cfstudio_*. Update your theme code after upgrading; saved data is migrated automatically.

= 0.12.4 =
Fixes repeater and media fields inside custom blocks on current WordPress versions.
