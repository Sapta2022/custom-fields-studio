# Testing Fieldloom Custom Fields

Two layers, both driven by the **CFS QA Suite** companion plugin in `tests/cfs-qa-suite/`.

## 1. In-WordPress QA suite (works on any site, no tools needed)

1. Copy `tests/cfs-qa-suite/` into `wp-content/plugins/` (or upload `cfs-qa-suite.zip`) and activate it
   next to Fieldloom Custom Fields.
2. **Tools -> CFS QA Suite -> Run all tests.**

It (re)creates, tagged so they can be removed exactly:

* **3 field groups** that between them use every field type and option -
  *QA Basic Fields* (pages + posts), *QA Repeater & Flexible* (pages), *QA Options Fields* (two options pages);
* **3 demo pages** - page 1 full values, page 2 rich repeater/flexible content, page 3 edge cases
  (limits, invalid input, special characters). Each page body shows every field through the `[cfstudio]` shortcode;
* **2 options pages** - *QA Options* (top level) and *QA Options (Settings)* (sub page);
* images, related posts, categories and tags to point the fields at.

Then it runs ~470 checks grouped by phase (P1-P7, P9, P10, EDGE) and module, and shows PASS/FAIL per check.
*Remove all QA content* deletes everything it created.

## 2. Browser end-to-end tests (Playwright)

`tests/e2e/*.js` drive a real browser against the seeded content: builder round trip of every setting,
building a group from scratch, editing every field type on a page, repeater/flexible add/remove/reorder/nesting,
min/max limits, options pages (both), roles, and the front-end pages (logged out).

```
export CFS_WP_ROOT=/path/to/wordpress   # for the helper that reads saved meta
export WP_USER=admin WP_PASS=...        # test site only
node tests/e2e/qa-builder.js            # etc.
```

`tests/e2e/p9.js` builds a group with the 14 Phase 9 field types in the builder UI, fills a real page, saves, reloads, clears values and checks front-end output.
`tests/e2e/p10.js` builds tabs, accordion, message, group, post object, user and conditional rules in the builder, then exercises them on a real page. `tests/e2e/p10b.js` (with `mkcond.php`) checks that conditional logic is scoped per repeater row and per group.
`tests/e2e/p11.js` (with `p11setup.php`) builds a group with a clone field and non-default location rules in the builder, then exercises post-status rules, the user profile and term screens, the Tools import/export page and the REST API.
`tests/e2e/p12.js` (with `p12setup.php`, plus `p12-mu-block.php` and `p12-templates-hero.php` copied into `wp-content/mu-plugins/` — the template goes in `mu-plugins/p12-templates/hero.php`) drives the real block editor: inserts a custom block, fills its form, previews, saves, checks the front end and re-opens it. It also covers the Google Map field, the builder settings and the Tools settings.
