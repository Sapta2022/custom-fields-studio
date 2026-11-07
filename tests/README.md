# Testing Custom Fields Studio

Two layers, both driven by the **CFS QA Suite** companion plugin in `tests/cfs-qa-suite/`.

## 1. In-WordPress QA suite (works on any site, no tools needed)

1. Copy `tests/cfs-qa-suite/` into `wp-content/plugins/` (or upload `cfs-qa-suite.zip`) and activate it
   next to Custom Fields Studio.
2. **Tools -> CFS QA Suite -> Run all tests.**

It (re)creates, tagged so they can be removed exactly:

* **3 field groups** that between them use every field type and option -
  *QA Basic Fields* (pages + posts), *QA Repeater & Flexible* (pages), *QA Options Fields* (two options pages);
* **3 demo pages** - page 1 full values, page 2 rich repeater/flexible content, page 3 edge cases
  (limits, invalid input, special characters). Each page body shows every field through the `[cfs]` shortcode;
* **2 options pages** - *QA Options* (top level) and *QA Options (Settings)* (sub page);
* images, related posts, categories and tags to point the fields at.

Then it runs ~235 checks grouped by phase (P1-P7, EDGE) and module, and shows PASS/FAIL per check.
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
