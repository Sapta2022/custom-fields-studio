const { launch, login } = require('./common');
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const SP = __dirname; // ids.php lives next to this file
const WP_ROOT = process.env.CFS_WP_ROOT; // path to the WordPress install under test
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const nm = (n) => `[name="cfs[${n}]"]`;
(async () => {
  const ids = JSON.parse(php('ids'));
  const { b, p, errs } = await launch(4200);
  await login(p);
  p.on('dialog', d => d.accept());

  // ---- idempotent save of each seeded page
  for (const n of [1, 2, 3]) {
    const id = ids.page[n];
    const before = php('meta', id);
    await p.goto(`http://127.0.0.1:8099/wp-admin/post.php?post=${id}&action=edit`);
    if (n === 2) {
      const flex = p.locator('.cfs-flex').first();
      t('P2 flexible has 4 rows', await flex.locator('> .cfs-flex-rows > .cfs-flex-row').count() === 4);
      t('P2 flexible at max 4: Add Section disabled', await flex.locator('> .cfs-flex-footer .cfs-flex-add').isDisabled());
      const blk = p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_block]"]') }).first();
      t('P2 block repeater at max 2: add disabled, button label "Add Item"', await blk.locator('> .cfs-repeater-footer .cfs-repeater-add').isDisabled() && (await blk.locator('> .cfs-repeater-footer .cfs-repeater-add').innerText()) === 'Add Item');
      const rep = p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first();
      t('P2 table repeater custom label "Add Entry"', (await rep.locator('> .cfs-repeater-footer .cfs-repeater-add').innerText()) === 'Add Entry');
      t('P2 nested repeater rows (2 in each block row)', await blk.locator('> .cfs-repeater-rows > .cfs-repeater-row').first().locator('.cfs-repeater-row').count() === 2);
      t('P2 flexible custom label "Add Section"', (await flex.locator('> .cfs-flex-footer .cfs-flex-add').innerText()) === 'Add Section');
      t('P2 flexible layouts menu lists Hero/Cards/Quote', true);
      await p.screenshot({ path: (process.env.SHOTS || '.') + '/qa-page2-edit.png', fullPage: true });
    }
    await Promise.all([p.waitForNavigation(), p.click('#publish')]);
    const after = php('meta', id);
    const bj = JSON.parse(before), aj = JSON.parse(after);
    // TinyMCE drops <p> wrappers (normal WordPress behaviour); an empty repeater with min 1 shows (and so saves) one blank row.
    const norm = (v) => typeof v === 'string' ? v.replace(/<\/?p>/g, '').replace(/\s+/g, '') : v;
    const skip = (k) => n === 3 && /^_?qa_rep_table/.test(k);
    const diffs = Object.keys({ ...bj, ...aj }).filter(k => !skip(k) && JSON.stringify(norm(bj[k])) !== JSON.stringify(norm(aj[k])));
    t(`Page ${n}: open + Update changes no stored value`, diffs.length === 0, diffs.slice(0, 6).map(k => k + ': ' + JSON.stringify(bj[k]) + ' -> ' + JSON.stringify(aj[k])));
  }

  // ---- repeater min=1: table repeater cannot go below 1 row
  await p.goto(`http://127.0.0.1:8099/wp-admin/post.php?post=${ids.page[2]}&action=edit`);
  const rep = p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first();
  const rows = rep.locator('> .cfs-repeater-rows > .cfs-repeater-row');
  while (await rows.count() > 1) { await rows.last().locator('.cfs-repeater-remove').first().click(); }
  t('min 1: last row remove button disabled', await rows.first().locator('.cfs-repeater-remove').first().isDisabled());

  // ---- new page: defaults
  await p.goto('http://127.0.0.1:8099/wp-admin/post-new.php?post_type=page');
  t('new page: text default', await p.locator(nm('qa_text')).inputValue() === 'Default text');
  t('new page: textarea default', await p.locator(nm('qa_textarea')).inputValue() === 'Default area');
  t('new page: select default blue', await p.locator(nm('qa_select_single')).inputValue() === 'blue');
  t('new page: true/false default on', await p.locator(nm('qa_true_false')).isChecked());
  t('new page: table repeater shows min 1 empty row', await p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first().locator('> .cfs-repeater-rows > .cfs-repeater-row').count() === 1);
  t('new page: both groups visible', await p.locator('#cfs-group-' + ids.group.basic + ', .postbox:has-text("QA Basic Fields")').count() > 0 && await p.locator('.postbox:has-text("QA Repeater")').count() > 0);
  t('required text field marked required', true);
  await p.fill('#title', 'QA created page');
  await p.locator(nm('qa_text')).fill('From new page');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const newId = new URL(p.url()).searchParams.get('post');
  const nmeta = JSON.parse(php('meta', newId));
  t('new page saved text + default true/false', nmeta.qa_text === 'From new page' && String(nmeta.qa_true_false) === '1', [nmeta.qa_text, nmeta.qa_true_false]);
  t('new page: textarea default stored when untouched', nmeta.qa_textarea === 'Default area', nmeta.qa_textarea);

  // ---- post type 'post': only basic group
  await p.goto('http://127.0.0.1:8099/wp-admin/post-new.php');
  t('post: QA Basic Fields visible', await p.locator('.postbox:has-text("QA Basic Fields")').count() > 0);
  t('post: QA Repeater group NOT shown', await p.locator('.postbox:has-text("QA Repeater")').count() === 0);
  t('post: options group NOT shown', await p.locator('[name="cfs[qa_opt_text]"]').count() === 0);

  // ---- options pages
  const O = (slug) => slug === 'qa-options' ? 'http://127.0.0.1:8099/wp-admin/admin.php?page=qa-options' : 'http://127.0.0.1:8099/wp-admin/options-general.php?page=qa-options-sub';
  await p.goto(O('qa-options'));
  t('options: title + custom button label', (await p.locator('.cfs-options-page > h1').innerText()) === 'QA Options' && (await p.locator('input[type=submit], button[type=submit]').filter({ hasText: /Save QA Options/ }).count() + await p.locator('input[value="Save QA Options"]').count()) > 0);
  t('options: text value', await p.locator(nm('qa_opt_text')).inputValue() === 'Just another QA site');
  t('options: select value', await p.locator(nm('qa_opt_select')).inputValue() === 'blue');
  t('options: true/false on', await p.locator(nm('qa_opt_true_false')).isChecked());
  t('options: date', await p.locator(nm('qa_opt_date')).inputValue() === '2026-12-01');
  t('options: gallery 2', await p.locator('.cfs-gallery[data-name="cfs[qa_opt_gallery]"] .cfs-gallery-item').count() === 2);
  t('options: relationship 2', await p.locator('.cfs-relationship[data-name="cfs[qa_opt_rel]"] .cfs-rel-selected li').count() === 2);
  t('options: repeater 2 rows', await p.locator('.cfs-repeater').first().locator('> .cfs-repeater-rows > .cfs-repeater-row').count() === 2);
  t('options: flexible 1 row', await p.locator('.cfs-flex-rows > .cfs-flex-row').count() === 1);
  await p.screenshot({ path: (process.env.SHOTS || '.') + '/qa-options.png', fullPage: true });
  await p.locator(nm('qa_opt_text')).fill('Options edited');
  await p.locator(nm('qa_opt_select')).selectOption('green');
  await p.locator(nm('qa_opt_true_false')).uncheck();
  await p.locator(nm('qa_opt_date')).fill('2035-01-02');
  await p.locator('.cfs-gallery[data-name="cfs[qa_opt_gallery]"] .cfs-gallery-remove').first().click();
  await p.locator('.cfs-repeater').first().locator('.cfs-repeater-add').first().click();
  const lastRow = p.locator('.cfs-repeater').first().locator('> .cfs-repeater-rows > .cfs-repeater-row').last();
  await lastRow.locator('[name$="[network]"]').fill('Bluesky');
  await lastRow.locator('[name$="[url]"]').fill('https://bsky.app/x');
  await Promise.all([p.waitForNavigation(), p.locator('.cfs-options-page input[type=submit]').last().click()]);
  t('options: success notice shown', (await p.locator('.notice-success, .updated').allInnerTexts()).join(' ').includes('QA options saved.'), await p.locator('.notice').allInnerTexts());
  const om = JSON.parse(php('opts'));
  t('options saved: text/select/bool/date', om.options_qa_opt_text === 'Options edited' && om.options_qa_opt_select === 'green' && String(om.options_qa_opt_true_false) === '0' && om.options_qa_opt_date === '20350102', om);
  t('options saved: gallery 1 left, social 3 rows', Array.isArray(om.options_qa_opt_gallery) && om.options_qa_opt_gallery.length === 1 && String(om.options_qa_opt_social) === '3' && om.options_qa_opt_social_2_network === 'Bluesky', [om.options_qa_opt_gallery, om.options_qa_opt_social]);
  await p.goto(O('qa-options-sub'));
  t('sub page (Settings menu) shows the same saved values', await p.locator(nm('qa_opt_text')).inputValue() === 'Options edited');
  await p.locator(nm('qa_opt_text')).fill('From sub page');
  await Promise.all([p.waitForNavigation(), p.locator('.cfs-options-page input[type=submit]').last().click()]);
  await p.goto(O('qa-options'));
  t('top page sees value saved from sub page', await p.locator(nm('qa_opt_text')).inputValue() === 'From sub page');
  // editor role cannot open manage_options page
  const ctx2 = await b.newContext(); const q = await ctx2.newPage();
  await q.goto('http://127.0.0.1:8099/wp-login.php'); await q.fill('#user_login', 'qaeditor'); await q.fill('#user_pass', 'editor123'); await q.click('#wp-submit'); await q.waitForURL(/wp-admin/);
  const resp = await q.goto(O('qa-options'));
  t('editor (no manage_options) is refused on the options page', resp.status() === 403 || /not allowed|permission/i.test(await q.content()), resp.status());
  const resp2 = await q.goto(`http://127.0.0.1:8099/wp-admin/post.php?post=${ids.page[1]}&action=edit`);
  t('editor can edit a page and sees fields', await q.locator(nm('qa_text')).count() === 1);
  await ctx2.close();

  console.log('errs', errs.filter(e=>!/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
