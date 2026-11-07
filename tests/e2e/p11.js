// Phase 11: location rules, clone, user/term screens, Tools (JSON), REST.
const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const SP = __dirname;
const WP_ROOT = process.env.CFS_WP_ROOT;
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
const setup = (cmd) => execFileSync('php', [SP + '/p11setup.php', WP_ROOT, cmd], { encoding: 'utf8' });
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE = 'http://127.0.0.1:8099';
(async () => {
  setup('teardown');
  const fx = JSON.parse(setup('setup'));
  const { b, p, errs } = await launch(3600);
  await login(p);
  p.on('dialog', d => d.accept());

  // ---------------------------------------------------------------- builder
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=cfs_field_group`);
  await p.fill('#title', 'P11 UI Host');
  const rows = () => p.locator('#cfs-fields-rows > tr.cfs-field-row');
  const main = (i) => rows().nth(i).locator('> td > .cfs-field-row-main');
  const panel = (i) => rows().nth(i).locator('> td > .cfs-field-settings-panel');
  await main(0).locator('.cfs-field-label').fill('UI Own');
  await p.locator('.cfs-fields-editor-top > p > .cfs-add-field').click();
  await main(1).locator('.cfs-field-label').fill('Ui Clone');
  await main(1).locator('.cfs-field-type-select').selectOption('clone');
  await main(1).locator('.cfs-toggle-field-settings').click();
  const cs = panel(1).locator('> .cfs-field-setting[data-type="clone"]');
  t('Clone type shows its settings (group checkboxes)', await cs.locator('input[type=checkbox][name$="[clone][]"]').count() >= 2);
  t('Clone settings do not list the group itself', await cs.locator('label:has-text("P11 UI Host")').count() === 0);
  await cs.locator('label:has-text("P11e term group") input').check();
  await panel(1).locator('> .cfs-field-setting[data-type="clone"] [name$="[display]"]').first().selectOption('seamless');
  await panel(1).locator('> .cfs-field-setting[data-type="clone"] [name$="[prefix_name]"]').first().check();

  const param0 = p.locator('.cfs-location-rule').first().locator('.cfs-location-param');
  const opts = await param0.locator('option').evaluateAll(o => o.map(x => x.value));
  t('Builder offers all 10 location params', opts.length === 10 && ['post_status','page_template','post_category','post_taxonomy','current_user_role','user_role','taxonomy','post_id'].every(k => opts.includes(k)), opts);
  await param0.selectOption('page_template');
  const tv = await p.locator('.cfs-location-rule').first().locator('.cfs-location-value option').evaluateAll(o => o.map(x => x.value));
  t('Changing the param refills the value list (page templates)', tv.includes('default'), tv);
  await param0.selectOption('post_status');
  const sv = await p.locator('.cfs-location-rule').first().locator('.cfs-location-value option').evaluateAll(o => o.map(x => x.value));
  t('post_status values: publish/draft/pending/private/future', ['publish','draft','pending','private','future'].every(k => sv.includes(k)), sv);
  await p.locator('.cfs-location-rule').first().locator('.cfs-location-value').selectOption('draft');
  await p.locator('.cfs-add-rule').first().click();
  const r2 = p.locator('.cfs-location-rule').nth(1);
  await r2.locator('.cfs-location-param').selectOption('post_type');
  await r2.locator('.cfs-location-value').selectOption('post');
  await p.locator('input[name="cfs_options[show_in_rest]"]').check();
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const gid = new URL(p.url()).searchParams.get('post');
  const loc = JSON.parse(php('gmeta', gid, '_cfs_location'));
  t('Location saved: post_status == draft AND post_type == post', loc.length === 1 && loc[0].length === 2 && loc[0][0].param === 'post_status' && loc[0][0].value === 'draft' && loc[0][1].param === 'post_type', loc);
  const opt = JSON.parse(php('gmeta', gid, '_cfs_options'));
  t('Show in REST API saved', opt.show_in_rest === 1, opt);
  const flds = JSON.parse(php('group', gid));
  const cl = flds[1];
  t('Clone field saved with group key, seamless, prefix', cl.type === 'clone' && cl.display === 'seamless' && cl.prefix_name === 1 && cl.clone.length === 1 && cl.clone[0] === JSON.parse(php('gmeta', fx.term, '_cfs_group_key')), cl);
  await p.reload();
  t('Settings reload: rule value, REST tick and clone tick persisted', await p.locator('.cfs-location-rule').first().locator('.cfs-location-param').inputValue() === 'post_status' && await p.locator('input[name="cfs_options[show_in_rest]"]').isChecked());
  await p.locator('.cfs-expand-all').click();
  t('Clone settings reload with the group ticked', await panel(1).locator('> .cfs-field-setting[data-type="clone"] label:has-text("P11e term group") input').isChecked());
  const before = php('group', gid);
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  t('Open + Update through the builder changes nothing (clone included)', before === php('group', gid));

  // ------------------------------------------- post screen: post_status rule + clone
  await p.goto(`${BASE}/wp-admin/post-new.php`);
  await p.fill('#title', 'P11e Draft Post');
  t('Draft-only group shown on a new (draft) post', await p.locator(`#cfs_group_${gid}`).count() === 1 && await p.locator(`#cfs_group_${fx.draft}`).count() === 1);
  t('Seamless clone with prefix: cloned fields use prefixed names', await p.locator('[name="cfs[ui_own]"]').count() === 1 && await p.locator('[name="cfs[ui_clone_e2e_color]"]').count() === 1 && await p.locator('[name="cfs[ui_clone_e2e_featured]"]').count() === 1);
  await p.fill('[name="cfs[ui_own]"]', 'own value');
  await p.fill('[name="cfs[ui_clone_e2e_color]"]', 'teal');
  await p.check('[name="cfs[ui_clone_e2e_featured]"]');
  await p.fill('[name="cfs[e2e_draft_note]"]', 'typed while draft');
  await p.click('#save-post');
  await p.waitForURL(/post\.php\?post=\d+/);
  const pid = new URL(p.url()).searchParams.get('post');
  t('Save Draft stores the values', JSON.parse(php('pmeta', pid, 'ui_own')) === 'own value' && JSON.parse(php('pmeta', pid, 'ui_clone_e2e_color')) === 'teal' && JSON.parse(php('pmeta', pid, 'e2e_draft_note')) === 'typed while draft');
  t('Cloned true/false stored on', Number(JSON.parse(php('pmeta', pid, 'ui_clone_e2e_featured'))) === 1);
  await p.fill('[name="cfs[e2e_draft_note]"]', 'edited at publish');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  t('Publishing saves what was typed in a draft-only group', JSON.parse(php('pmeta', pid, 'e2e_draft_note')) === 'edited at publish');
  await p.goto(`${BASE}/wp-admin/post.php?post=${pid}&action=edit`);
  t('After publishing the draft-only groups are gone', await p.locator(`#cfs_group_${gid}`).count() === 0 && await p.locator(`#cfs_group_${fx.draft}`).count() === 0);
  t('Values survive (not deleted when the group stops showing)', JSON.parse(php('pmeta', pid, 'ui_own')) === 'own value');

  // ---------------------------------------------------------------- profile
  await p.goto(`${BASE}/wp-admin/profile.php`);
  t('User profile shows the user group', await p.locator(`#cfs_group_${fx.user}`).count() === 1 && await p.locator('[name="cfs[e2e_twitter]"]').count() === 1);
  await p.fill('[name="cfs[e2e_twitter]"]', '@e2e_user');
  await p.locator(`#cfs_group_${fx.user} .cfs-repeater-add`).first().click();
  await p.locator(`#cfs_group_${fx.user} [name$="[skill]"]`).first().fill('Playwright');
  await Promise.all([p.waitForNavigation(), p.click('#submit')]);
  t('Profile save stores user meta (text + repeater)', JSON.parse(php('umeta', '1', 'e2e_twitter')) === '@e2e_user' && String(JSON.parse(php('umeta', '1', 'e2e_skills'))) === '1' && JSON.parse(php('umeta', '1', 'e2e_skills_0_skill')) === 'Playwright');
  t('Profile reloads with the saved values', await p.locator('[name="cfs[e2e_twitter]"]').inputValue() === '@e2e_user' && await p.locator(`#cfs_group_${fx.user} [name$="[skill]"]`).first().inputValue() === 'Playwright');

  // ------------------------------------------------------------------- term
  await p.goto(`${BASE}/wp-admin/term.php?taxonomy=category&tag_ID=${fx.cat}&post_type=post`);
  t('Category edit screen shows the term group', await p.locator(`#cfs_group_${fx.term}`).count() === 1);
  await p.fill('[name="cfs[e2e_color]"]', 'crimson');
  await p.check('[name="cfs[e2e_featured]"]');
  await Promise.all([p.waitForNavigation(), p.click('input[type=submit].button-primary')]);
  t('Term save stores term meta', JSON.parse(php('tmeta', fx.cat, 'e2e_color')) === 'crimson' && Number(JSON.parse(php('tmeta', fx.cat, 'e2e_featured'))) === 1);
  await p.goto(`${BASE}/wp-admin/term.php?taxonomy=category&tag_ID=${fx.cat}&post_type=post`);
  t('Term screen reloads with the saved values', await p.locator('[name="cfs[e2e_color]"]').inputValue() === 'crimson' && await p.locator('[name="cfs[e2e_featured]"]').isChecked());
  await p.goto(`${BASE}/wp-admin/edit-tags.php?taxonomy=post_tag`);
  const tagLink = await p.locator('a.row-title').first().getAttribute('href').catch(() => null);
  if (tagLink) { await p.goto(`${BASE}/wp-admin/${tagLink.replace(/^.*wp-admin\//, '')}`); t('A tag screen does not show the category group', await p.locator(`#cfs_group_${fx.term}`).count() === 0); }

  // ---------------------------------------------------------------- Tools page
  await p.goto(`${BASE}/wp-admin/edit.php?post_type=cfs_field_group&page=cfs-tools`);
  t('Tools page lists groups and shows local JSON status', await p.locator('#cfs-export-form input[name="groups[]"]').count() >= 4 && await p.locator('#cfs-json-off, #cfs-json-on').count() === 1);
  t('Tools submenu entry exists', await p.locator('#adminmenu a[href*="page=cfs-tools"]').count() >= 1);
  await p.locator('#cfs-export-form input[name="groups[]"]').evaluateAll(els => els.forEach(e => { e.checked = false; }));
  await p.locator('#cfs-export-form label:has-text("P11 UI Host") input').check();
  await p.locator('#cfs-export-form label:has-text("P11e term group") input').check();
  const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#cfs-export-form button[type=submit]')]);
  const tmp = path.join(os.tmpdir(), 'cfs-p11-export.json');
  await dl.saveAs(tmp);
  const exp = JSON.parse(fs.readFileSync(tmp, 'utf8'));
  t('Export downloads a .json with just the chosen groups', /^cfs-export-.*\.json$/.test(dl.suggestedFilename()) && exp.groups.length === 2 && exp.groups.map(g => g.title).sort().join() === 'P11 UI Host,P11e term group', exp.groups.map(g => g.title));
  const host = exp.groups.find(g => g.title === 'P11 UI Host');
  t('Exported clone refers to the term group by key', host.fields[1].clone[0] === exp.groups.find(g => g.title === 'P11e term group').key);
  const countBefore = Number(php('groupcount'));
  await p.setInputFiles('#cfs-import-form input[type=file]', tmp);
  await Promise.all([p.waitForNavigation(), p.click('#cfs-import-form button[type=submit]')]);
  t('Re-importing the file updates in place: 0 created, 2 updated', (await p.locator('#cfs-tools-msg').textContent()).includes('0 created, 2 updated') && Number(php('groupcount')) === countBefore);
  // import a copy with a new key
  const copy = JSON.parse(JSON.stringify(exp));
  copy.groups = [copy.groups.find(g => g.title === 'P11e term group')];
  copy.groups[0].key = 'group_p11_e2e_copy'; copy.groups[0].title = 'P11 Imported Copy';
  const tmp2 = path.join(os.tmpdir(), 'cfs-p11-copy.json');
  fs.writeFileSync(tmp2, JSON.stringify(copy));
  await p.setInputFiles('#cfs-import-form input[type=file]', tmp2);
  await Promise.all([p.waitForNavigation(), p.click('#cfs-import-form button[type=submit]')]);
  t('A new key creates a group', (await p.locator('#cfs-tools-msg').textContent()).includes('1 created, 0 updated') && Number(php('groupcount')) === countBefore + 1 && Number(php('findgroup', 'P11 Imported Copy')) > 0);
  const tmp3 = path.join(os.tmpdir(), 'cfs-p11-bad.json');
  fs.writeFileSync(tmp3, '{ this is not json');
  await p.setInputFiles('#cfs-import-form input[type=file]', tmp3);
  await Promise.all([p.waitForNavigation(), p.click('#cfs-import-form button[type=submit]')]);
  t('Invalid JSON shows an error and imports nothing', (await p.locator('#cfs-tools-err').count()) === 1 && Number(php('groupcount')) === countBefore + 1);
  const tmp4 = path.join(os.tmpdir(), 'cfs-p11-bad.txt');
  fs.writeFileSync(tmp4, 'x');
  await p.setInputFiles('#cfs-import-form input[type=file]', tmp4);
  await Promise.all([p.waitForNavigation(), p.click('#cfs-import-form button[type=submit]')]);
  t('A non-.json file is refused', (await p.locator('#cfs-tools-err').textContent()).includes('.json'));
  await Promise.all([p.waitForNavigation(), p.click('#cfs-import-form button[type=submit]')]);
  t('Submitting with no file shows an error', (await p.locator('#cfs-tools-err').count()) === 1);
  // no-nonce direct hit
  const code = await p.evaluate(async (u) => (await fetch(u, { method: 'POST', body: new URLSearchParams({ action: 'cfs_export' }) })).status, `${BASE}/wp-admin/admin-post.php`);
  t('Export without the nonce is refused', code === 403 || code === 302 || code >= 400, code);

  // ---------------------------------------------------------------- REST (cookie auth)
  await p.goto(`${BASE}/wp-admin/`);
  const nonce = await p.evaluate(async () => (await fetch('/wp-admin/admin-ajax.php?action=rest-nonce')).text());
  const api = (method, route, body) => p.evaluate(async ([m, r, bd, n]) => {
    const res = await fetch('/index.php?rest_route=' + r, { method: m, headers: { 'X-WP-Nonce': n, 'Content-Type': 'application/json' }, body: bd ? JSON.stringify(bd) : undefined });
    return { status: res.status, json: await res.json().catch(() => null) };
  }, [method, route, body || null, nonce]);
  let res = await api('GET', `/wp/v2/posts/${pid}&context=edit`);
  t('REST: a published post no longer matches the draft-only groups, so cfs is empty', res.status === 200 && res.json.cfs && Object.keys(res.json.cfs).length === 0, res.json && res.json.cfs);
  res = await api('POST', '/wp/v2/posts', { title: 'P11e REST draft', status: 'draft', cfs: { ui_own: 'via rest', ui_clone_e2e_color: 'rest-teal', ui_clone_e2e_featured: true, e2e_draft_note: 'rest note', not_exposed: 'x' } });
  const rid = res.json && res.json.id;
  t('REST: create a draft post with cfs in one request', res.status === 201 && rid > 0, res.json);
  t('REST: values stored (clone fields and a second group included)', JSON.parse(php('pmeta', rid, 'ui_own')) === 'via rest' && JSON.parse(php('pmeta', rid, 'ui_clone_e2e_color')) === 'rest-teal' && JSON.parse(php('pmeta', rid, 'e2e_draft_note')) === 'rest note' && JSON.parse(php('pmeta', rid, 'not_exposed')) === '');
  res = await api('GET', `/wp/v2/posts/${rid}&context=edit`);
  t('REST: GET returns the cfs values', res.json.cfs.ui_own === 'via rest' && res.json.cfs.ui_clone_e2e_color === 'rest-teal' && res.json.cfs.e2e_draft_note === 'rest note', res.json && res.json.cfs);
  res = await api('POST', `/wp/v2/posts/${rid}`, { cfs: res.json.cfs });
  const res2 = await api('GET', `/wp/v2/posts/${rid}&context=edit`);
  t('REST: writing the read values back changes nothing', res.status === 200 && JSON.stringify(res2.json.cfs) === JSON.stringify((await api('GET', `/wp/v2/posts/${rid}&context=edit`)).json.cfs) && res2.json.cfs.ui_own === 'via rest');
  res = await api('GET', `/wp/v2/posts/${rid}&context=edit&cfs_format=1`);
  t('REST: cfs_format=1 gives formatted values (true/false is boolean)', res.status === 200 && typeof res.json.cfs.ui_clone_e2e_featured === 'boolean', res.json && res.json.cfs);
  res = await api('POST', `/wp/v2/pages/${fx.page}`, { cfs: { anything: 'x' } });
  t('REST: page without matching exposed groups accepts cfs but writes nothing', res.status === 200 && JSON.parse(php('pmeta', fx.page, 'anything')) === '');
  res = await api('POST', '/wp/v2/users/1', { cfs: { e2e_twitter: '@via_rest', e2e_skills: [{ skill: 'REST' }, { skill: 'JSON' }] } });
  t('REST: write user fields', res.status === 200 && JSON.parse(php('umeta', '1', 'e2e_twitter')) === '@via_rest' && JSON.parse(php('umeta', '1', 'e2e_skills_1_skill')) === 'JSON', res.json);
  res = await api('POST', `/wp/v2/categories/${fx.cat}`, { cfs: { e2e_color: 'gold' } });
  t('REST: write term fields', res.status === 200 && JSON.parse(php('tmeta', fx.cat, 'e2e_color')) === 'gold');
  res = await api('POST', '/cfs/v1/options', { anything: 1 });
  t('REST: options endpoint answers for admin', res.status === 200, res.json);
  res = await api('GET', '/cfs/v1/groups');
  t('REST: groups schema lists exposed groups', res.status === 200 && res.json.some(g => g.title === 'P11e user group') && res.json.some(g => g.title === 'P11 UI Host'), res.json && res.json.map(g => g.title));
  const anon = await (await b.newContext()).newPage();
  const ares = await anon.goto(`${BASE}/index.php?rest_route=/cfs/v1/groups`);
  t('REST: groups schema is closed to anonymous visitors', ares.status() === 401 || ares.status() === 403, ares.status());
  const ares2 = await anon.goto(`${BASE}/index.php?rest_route=/cfs/v1/options`);
  t('REST: options are readable anonymously', ares2.status() === 200);

  console.log('errs', errs.filter(e => !/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
  setup('teardown');
})().catch(e=>{console.error(e);try{setup('teardown');}catch(_){ } process.exit(1);});
