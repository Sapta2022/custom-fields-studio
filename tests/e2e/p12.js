// Phase 12: custom block in the real block editor, Google Map field, builder + Tools settings.
const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
const SP = __dirname;
const WP_ROOT = process.env.CFS_WP_ROOT;
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
const setup = (cmd) => execFileSync('php', [SP + '/p12setup.php', WP_ROOT, cmd], { encoding: 'utf8' });
const setOpt = (k, v) => execFileSync('php', ['-r', `define('WP_USE_THEMES',false);require '${WP_ROOT}/wp-load.php';update_option('${k}',${v});`]);
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE = 'http://127.0.0.1:8099';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  setup('teardown');
  const fx = JSON.parse(setup('setup'));
  setOpt('cfstudio_qa_block_editor', '1');
  const { b, p, errs } = await launch(3800);
  await login(p);
  p.on('dialog', d => d.accept());

  // ------------------------------------------------------------ block editor
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.waitForFunction(() => window.wp && wp.data && wp.data.select('core/editor') && wp.blocks.getBlockType('cfstudio/p12e-hero'), null, { timeout: 30000 });
  await p.evaluate(() => { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); wp.data.dispatch('core/preferences').set('core/edit-post', 'fullscreenMode', false); }).catch(() => {});
  const bt = await p.evaluate(() => { const x = wp.blocks.getBlockType('cfstudio/p12e-hero'); return { title: x.title, category: x.category, form: !!x.attributes.form, save: x.save() }; });
  t('Block is registered in the editor with its title, category and form attribute', bt.title === 'P12e Hero' && bt.category === 'text' && bt.form && bt.save === null, bt);
  await p.evaluate(() => { wp.data.dispatch('core/editor').editPost({ title: 'P12e Block Page' }); wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('cfstudio/p12e-hero')); });
  await p.waitForSelector('.cfs-block-form input[name="cfs[heading]"]', { timeout: 20000 });
  t('A new block opens straight into its field form (all 3 fields, repeater included)', await p.locator('.cfs-block-form [name="cfs[badge]"]').count() >= 1 && await p.locator('.cfs-block-form .cfs-repeater').count() === 1);
  await p.fill('.cfs-block-form input[name="cfs[heading]"]', 'Hello <img src=x onerror=alert(1)> World');
  await p.check('.cfs-block-form input[type=checkbox][name="cfs[badge]"]');
  await p.locator('.cfs-block-form .cfs-repeater-add').click();
  await p.locator('.cfs-block-form [name$="[item]"]').first().fill('First item');
  await p.locator('.cfs-block-form .cfs-repeater-add').click();
  await p.locator('.cfs-block-form [name$="[item]"]').nth(1).fill('Second item');
  const getForm = () => p.evaluate(() => (wp.data.select('core/block-editor').getBlocks()[0] || { attributes: {} }).attributes.form);
  await p.waitForFunction(() => { const bl = wp.data.select('core/block-editor').getBlocks()[0]; return bl && bl.attributes.form && bl.attributes.form.includes('Second'); }, null, { timeout: 15000 });
  const form1 = await getForm();
  t('Typing in the form updates the block attribute (form-encoded, debounced)', /cfs%5Bheading%5D=/.test(form1) && form1.includes('First') && form1.includes('Second') && /badge/.test(form1), form1);

  await p.locator('button[aria-label="Preview"]').click();
  await p.waitForSelector('.cfs-block .p12e-heading', { timeout: 20000 });
  t('Preview (server-side render) shows the heading, badge and both items', (await p.locator('.cfs-block .p12e-heading').innerText()).includes('Hello') && await p.locator('.cfs-block .p12e-badge').count() === 1 && await p.locator('.cfs-block .p12e-items li').count() === 2);
  t('Preview strips the hostile markup', await p.locator('.cfs-block img[onerror]').count() === 0 && !(await p.locator('.cfs-block .p12e-heading').innerHTML()).includes('onerror'));

  await p.evaluate(() => wp.data.dispatch('core/editor').editPost({ status: 'publish' }));
  await p.evaluate(() => wp.data.dispatch('core/editor').savePost());
  await p.waitForFunction(() => !!wp.data.select('core/editor').getCurrentPostId() && wp.data.select('core/editor').getCurrentPost().status === 'publish' && !wp.data.select('core/editor').isSavingPost(), null, { timeout: 30000 });
  const pid = await p.evaluate(() => wp.data.select('core/editor').getCurrentPostId());
  const link = await p.evaluate(() => wp.data.select('core/editor').getPermalink());
  const content = JSON.parse(php('content', pid));
  t('Saved post content holds the block comment with the form attribute', content.includes('<!-- wp:cfstudio/p12e-hero') && content.includes('"form":"cfs'), content.slice(0, 200));
  t('Nothing is written to post meta (values live in the block)', JSON.parse(php('pmeta', pid, 'heading')) === '' && JSON.parse(php('pmeta', pid, 'items')) === '');

  const fp = await (await b.newContext()).newPage();
  await fp.goto(link);
  t('Front end renders the block with its values', await fp.locator('.cfs-block.wp-block-cfstudio-p12e-hero').count() === 1 && (await fp.locator('.p12e-heading').innerText()).includes('Hello') && await fp.locator('.p12e-badge').count() === 1 && (await fp.locator('.p12e-items li').allInnerTexts()).join('|') === 'First item|Second item');
  t('Front end: hostile markup in the heading is gone', !(await fp.content()).includes('onerror=alert'));

  await p.goto(`${BASE}/wp-admin/post.php?post=${pid}&action=edit`);
  await p.waitForFunction(() => wp.data.select('core/block-editor').getBlocks().length > 0, null, { timeout: 30000 });
  const valid = await p.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].isValid);
  t('Reopening the post: the block is valid (no "unexpected content" recovery)', valid === true);
  await p.waitForSelector('.cfs-block .p12e-heading', { timeout: 20000 });
  t('...and shows the server-side preview straight away', (await p.locator('.cfs-block .p12e-heading').innerText()).includes('Hello'));
  await p.locator('.cfs-block-editor').first().click();
  await p.locator('button[aria-label="Edit fields"]').click();
  await p.waitForSelector('.cfs-block-form input[name="cfs[heading]"]');
  t('Edit fields: the form is pre-filled from the saved block (text, checkbox, repeater rows)', (await p.inputValue('.cfs-block-form input[name="cfs[heading]"]')).includes('Hello') && await p.isChecked('.cfs-block-form input[type=checkbox][name="cfs[badge]"]') && await p.locator('.cfs-block-form [name$="[item]"]').count() === 2 && await p.locator('.cfs-block-form [name$="[item]"]').nth(1).inputValue() === 'Second item');
  await p.locator('.cfs-block-form .cfs-repeater-remove').first().click();
  await p.waitForFunction(() => { const f = wp.data.select('core/block-editor').getBlocks()[0].attributes.form; return f && !f.includes('First'); }, null, { timeout: 15000 });
  t('Removing a repeater row updates the block', !(await getForm()).includes('First'));
  await p.evaluate(() => wp.data.dispatch('core/editor').savePost());
  await p.waitForFunction(() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isEditedPostDirty(), null, { timeout: 30000 });
  await fp.goto(link);
  t('Front end after editing: one item left', (await fp.locator('.p12e-items li').allInnerTexts()).join('|') === 'Second item');

  // -------------------------------------------- a block without a field group
  const hint = await p.evaluate(async () => { const r = await wp.apiFetch({ path: '/cfstudio/v1/block-form', method: 'POST', data: { block: 'cfstudio/p12e-hero', form: '' } }); return r.groups; });
  t('REST form endpoint works from the editor (nonce) and finds the group', hint === 1);

  // ------------------------------------------------------------- Google Map (classic editor)
  setOpt('cfstudio_qa_block_editor', '0');
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.fill('#title', 'P12e Map Page');
  t('Map field renders address, lat, lng, zoom and a Clear link (no API key: no canvas)', await p.locator('.cfs-gmap .cfs-gmap-address').count() === 1 && await p.locator('.cfs-gmap .cfs-gmap-lat').count() === 1 && await p.locator('.cfs-gmap .cfs-gmap-lng').count() === 1 && await p.locator('.cfs-gmap .cfs-gmap-zoom').count() === 1 && !(await p.locator('.cfs-gmap-canvas').isVisible()));
  await p.fill('.cfs-gmap .cfs-gmap-address', 'Victoria Memorial, Kolkata');
  await p.fill('.cfs-gmap .cfs-gmap-lat', '22.5448');
  await p.fill('.cfs-gmap .cfs-gmap-lng', '88.3426');
  await p.fill('.cfs-gmap .cfs-gmap-zoom', '16');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const mid = new URL(p.url()).searchParams.get('post');
  const mv = JSON.parse(php('pmeta', mid, 'p12e_venue'));
  t('Map value saved as address / lat / lng / zoom', mv.address === 'Victoria Memorial, Kolkata' && mv.lat === 22.5448 && mv.lng === 88.3426 && mv.zoom === 16, mv);
  t('Map inputs reload with the saved values', await p.inputValue('.cfs-gmap .cfs-gmap-lat') === '22.5448' && await p.inputValue('.cfs-gmap .cfs-gmap-address') === 'Victoria Memorial, Kolkata');
  await p.fill('.cfs-gmap .cfs-gmap-lat', '123');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  t('An out-of-range latitude empties the map value instead of saving junk', JSON.stringify(JSON.parse(php('pmeta', mid, 'p12e_venue'))) === '[]' || JSON.parse(php('pmeta', mid, 'p12e_venue')) === '', JSON.parse(php('pmeta', mid, 'p12e_venue')));
  await p.fill('.cfs-gmap .cfs-gmap-address', 'Somewhere');
  await p.fill('.cfs-gmap .cfs-gmap-lat', '10');
  await p.fill('.cfs-gmap .cfs-gmap-lng', '20');
  await p.locator('.cfs-gmap-clear').click();
  t('Clear empties address, latitude and longitude', await p.inputValue('.cfs-gmap .cfs-gmap-address') === '' && await p.inputValue('.cfs-gmap .cfs-gmap-lat') === '' && await p.inputValue('.cfs-gmap .cfs-gmap-lng') === '');
  const fp2 = await (await b.newContext()).newPage();
  await p.fill('.cfs-gmap .cfs-gmap-lat', '22.5448'); await p.fill('.cfs-gmap .cfs-gmap-lng', '88.3426'); await p.fill('.cfs-gmap .cfs-gmap-address', 'VM');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);

  // ------------------------------------------------------------------ builder
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=cfstudio_field_group`);
  await p.fill('#title', 'P12 UI Map Group');
  const row = p.locator('#cfs-fields-rows > tr.cfs-field-row').first();
  await row.locator('> td > .cfs-field-row-main .cfs-field-label').fill('Where');
  await row.locator('> td > .cfs-field-row-main .cfs-field-type-select').selectOption('google_map');
  await row.locator('> td > .cfs-field-row-main .cfs-toggle-field-settings').click();
  const panel = row.locator('> td > .cfs-field-settings-panel');
  t('Google Map settings are visible, other types hidden', await panel.locator('> .cfs-field-setting[data-type="google_map"]').first().isVisible() && !(await panel.locator('> .cfs-field-setting[data-type="text"]').first().isVisible()));
  await panel.locator('> .cfs-field-setting[data-type="google_map"] [name$="[center_lat]"]').fill('28.6139');
  await panel.locator('> .cfs-field-setting[data-type="google_map"] [name$="[center_lng]"]').fill('77.2090');
  await panel.locator('> .cfs-field-setting[data-type="google_map"] [name$="[zoom]"]').fill('8');
  await panel.locator('> .cfs-field-setting[data-type="google_map"] [name$="[height]"]').fill('420');
  t('Translation select offers translate / copy / ignore, default translate', (await panel.locator('.cfs-translate-setting select option').evaluateAll(o => o.map(x => x.value))).join() === 'translate,copy,ignore' && await panel.locator('.cfs-translate-setting select').inputValue() === 'translate');
  await panel.locator('.cfs-translate-setting select').selectOption('copy');
  const lt = await p.locator('.cfs-location-rule').first().locator('.cfs-location-param').locator('option').evaluateAll(o => o.map(x => x.value));
  t('Location builder offers the Block param with the registered block', lt.includes('block'));
  await p.locator('.cfs-location-rule').first().locator('.cfs-location-param').selectOption('block');
  const bv = await p.locator('.cfs-location-rule').first().locator('.cfs-location-value option').evaluateAll(o => o.map(x => x.value));
  t('Block values list the block registered in PHP', bv.includes('cfstudio/p12e-hero'), bv);
  await p.locator('.cfs-location-rule').first().locator('.cfs-location-value').selectOption('cfstudio/p12e-hero');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const gid = new URL(p.url()).searchParams.get('post');
  const fld = JSON.parse(php('group', gid))[0];
  t('Builder saved the map settings and translate=copy', fld.type === 'google_map' && fld.center_lat === 28.6139 && fld.center_lng === 77.209 && fld.zoom === 8 && fld.height === 420 && fld.translate === 'copy', fld);
  const before = php('group', gid);
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  t('Open + Update through the builder changes nothing', before === php('group', gid));
  const loc = JSON.parse(php('gmeta', gid, '_cfstudio_location'));
  t('Block location rule saved', loc[0][0].param === 'block' && loc[0][0].value === 'cfstudio/p12e-hero', loc);

  // ----------------------------------------------------------- Tools settings
  await p.goto(`${BASE}/wp-admin/edit.php?post_type=cfstudio_field_group&page=cfs-tools`);
  await p.fill('#cfs-gmaps-key', 'AIzaSy-TEST_key<script>');
  await p.check('#cfs-translate-options');
  await Promise.all([p.waitForNavigation(), p.click('#cfs-settings-form button[type=submit]')]);
  t('Settings saved: API key stored without markup, per-language options on', JSON.parse(php('opt', 'cfstudio_google_maps_api_key')) === 'AIzaSy-TEST_key' && Number(JSON.parse(php('opt', 'cfstudio_translate_options'))) === 1 && (await p.locator('#cfs-tools-msg').textContent()).includes('Settings saved'));
  t('Settings page shows the saved values', await p.inputValue('#cfs-gmaps-key') === 'AIzaSy-TEST_key' && await p.isChecked('#cfs-translate-options'));
  // With a key the map widget attaches a canvas and requests the Maps script (network may be blocked; the request is what we check).
  const requested = [];
  p.on('request', r => { if (r.url().includes('maps.googleapis.com/maps/api/js')) requested.push(r.url()); });
  await p.route('**/maps.googleapis.com/**', route => route.abort());
  await p.goto(`${BASE}/wp-admin/post.php?post=${mid}&action=edit`);
  await p.locator('.cfs-gmap').first().hover();
  await sleep(1500);
  t('With a key the editor requests the Google Maps API (key + places library)', requested.length === 1 && requested[0].includes('key=AIzaSy-TEST_key') && requested[0].includes('libraries=places'), requested);
  t('Typed coordinates still work when the Maps API cannot load', await p.inputValue('.cfs-gmap .cfs-gmap-lat') === '22.5448');

  console.log('errs', errs.filter(e => !/Failed to load resource|googleapis|ERR_FAILED|net::/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
  setup('teardown');
})().catch(e=>{console.error(e);try{setup('teardown');}catch(_){ } process.exit(1);});
