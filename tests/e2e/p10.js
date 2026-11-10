// Phase 10: tabs, accordion, message, group, conditional logic, post object, user
const { launch, login } = require('./common');
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE = 'http://127.0.0.1:8099';
(async () => {
  const { b, p, errs } = await launch(4200);
  await login(p);
  p.on('dialog', d => d.accept());
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=cfstudio_field_group`);
  await p.fill('#title', 'P10 layout and logic');
  const rows = () => p.locator('#cfs-fields-rows > tr.cfs-field-row');
  const main = (i) => rows().nth(i).locator('> td > .cfs-field-row-main');
  const panel = (i) => rows().nth(i).locator('> td > .cfs-field-settings-panel');
  const defs = [
    ['Basics','tab'], ['Kind','select'], ['Extra','text'], ['Intro note','message'],
    ['Advanced','tab'], ['More','accordion'], ['Show link','true_false'], ['Link url','text'],
    ['Hero','group'], ['Featured page','post_object'], ['Owner','user'],
  ];
  for (let i = 0; i < defs.length; i++) {
    if (i > 0) await p.locator('.cfs-fields-editor-top > p > .cfs-add-field').click();
    await main(i).locator('.cfs-field-label').fill(defs[i][0]);
    await main(i).locator('.cfs-field-type-select').selectOption(defs[i][1]);
    await main(i).locator('.cfs-toggle-field-settings').click();
  }
  const set = (i, type, key) => panel(i).locator(`> .cfs-field-setting[data-type="${type}"] [name$="[${key}]"]`).first();
  await set(1,'select','choices').fill('a : Alpha\nb : Beta');
  await set(1,'select','allow_null').check();
  await set(3,'message','message').fill('Fill <b>both</b> tabs.');
  await set(5,'accordion','open').uncheck();
  await set(9,'post_object','return_format').selectOption('id');
  // group sub fields
  const sub = panel(8).locator('> .cfs-field-setting[data-type~="group"] .cfs-fields-editor').first();
  await sub.locator('> p > .cfs-add-field').click();
  await sub.locator('> table > tbody > tr.cfs-field-row').nth(0).locator('.cfs-field-label').first().fill('Heading');
  await sub.locator('> p > .cfs-add-field').click();
  await sub.locator('> table > tbody > tr.cfs-field-row').nth(1).locator('.cfs-field-label').first().fill('Qty');
  await sub.locator('> table > tbody > tr.cfs-field-row').nth(1).locator('.cfs-field-type-select').first().selectOption('number');
  // post object: pages only
  await panel(9).locator('> .cfs-field-setting[data-type="post_object"] input[type=checkbox][value="post"]').uncheck();
  await panel(9).locator('> .cfs-field-setting[data-type="post_object"] input[type=checkbox][value="page"]').check();
  // conditional logic: Extra shows when Kind == b ; Link url when Show link is on
  const cond = (i, field, op, value) => (async () => {
    await panel(i).locator('> .cfs-cond .cfs-cond-enabled').check();
    const rule = panel(i).locator('> .cfs-cond .cfs-cond-rule').first();
    t(`row ${i}: enabling logic adds a first rule`, await rule.count() === 1);
    await rule.locator('.cfs-cond-field').focus();
    await rule.locator('.cfs-cond-field').selectOption(field);
    await rule.locator('.cfs-cond-op').selectOption(op);
    if (value !== null) await rule.locator('.cfs-cond-value').fill(value);
  })();
  await cond(2, 'kind', '==', 'b');
  const opts = await panel(2).locator('> .cfs-cond .cfs-cond-field option').allInnerTexts();
  t('rule field list offers siblings but not the field itself', opts.includes('kind') && opts.includes('hero') && !opts.includes('extra'), opts);
  await cond(7, 'show_link', '==', '1');
  await panel(7).locator('> .cfs-cond .cfs-cond-add').click();
  t('Add rule adds a second row', await panel(7).locator('> .cfs-cond .cfs-cond-rule').count() === 2);
  await panel(7).locator('> .cfs-cond .cfs-cond-rule').nth(1).locator('.cfs-cond-remove').click();
  t('Remove rule removes it', await panel(7).locator('> .cfs-cond .cfs-cond-rule').count() === 1);
  await panel(2).locator('> .cfs-cond .cfs-cond-op').first().selectOption('empty');
  t('"is empty" hides the value box', !(await panel(2).locator('> .cfs-cond .cfs-cond-value').first().isVisible()));
  await panel(2).locator('> .cfs-cond .cfs-cond-op').first().selectOption('==');
  t('"is equal to" shows it again', await panel(2).locator('> .cfs-cond .cfs-cond-value').first().isVisible());

  await p.locator('select[name^="cfstudio_location"][name$="[value]"]').first().selectOption('page');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  await p.locator('.cfs-expand-all').click();
  t('types persisted', JSON.stringify(await p.locator('#cfs-fields-rows > tr.cfs-field-row > td > .cfs-field-row-main .cfs-field-type-select').evaluateAll(a => a.map(e => e.value))) === JSON.stringify(defs.map(d => d[1])));
  t('rule persisted: field/op/value', await panel(2).locator('> .cfs-cond .cfs-cond-field').first().inputValue() === 'kind' && await panel(2).locator('> .cfs-cond .cfs-cond-value').first().inputValue() === 'b');
  t('rule on other field persisted', await panel(7).locator('> .cfs-cond .cfs-cond-field').first().inputValue() === 'show_link');
  t('field without logic stays unchecked', !(await panel(1).locator('> .cfs-cond .cfs-cond-enabled').isChecked()));
  t('group sub fields persisted', await panel(8).locator('> .cfs-field-setting[data-type~="group"] > .cfs-fields-editor > table > tbody > tr.cfs-field-row').count() === 2);
  t('message text persisted', (await set(3,'message','message').inputValue()).includes('<b>both</b>'));
  // duplicate a field with logic keeps its own rule under its own prefix
  await main(2).locator('.cfs-duplicate-field').click();
  const names = await p.locator('.cfs-cond-field').evaluateAll(a => a.map(e => e.name));
  t('duplicated row has unique rule input names', new Set(names).size === names.length, names);
  await main(3).locator('.cfs-remove-field').click(); // remove the copy again (row 3 is the copy)
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);

  // ---- page
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.fill('#title', 'P10 page');
  const nm = (n) => `[name="cfs[${n}]"]`;
  t('two tab buttons built', await p.locator('.cfs-tab-button').count() === 2);
  t('first tab active, second hidden', await p.locator('.cfs-tab-panel').nth(0).isVisible() && !(await p.locator('.cfs-tab-panel').nth(1).isVisible()));
  t('tab markers removed after build', await p.locator('.cfs-tab-marker').count() === 0);
  t('message text shown', (await p.locator('.cfs-message').first().innerText()).includes('Fill both tabs.'));
  t('Extra hidden while Kind is empty', !(await p.locator(nm('extra')).isVisible()));
  await p.locator(nm('kind')).selectOption('a');
  await p.waitForTimeout(200);
  t('Extra still hidden for Kind=a', !(await p.locator(nm('extra')).isVisible()));
  await p.locator(nm('kind')).selectOption('b');
  await p.locator(nm('extra')).waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
  t('Extra shows for Kind=b', await p.locator(nm('extra')).isVisible());
  await p.locator(nm('extra')).fill('shown value');
  await p.locator('.cfs-tab-button').nth(1).click();
  t('second tab now visible, first hidden', await p.locator('.cfs-tab-panel').nth(1).isVisible() && !(await p.locator('.cfs-tab-panel').nth(0).isVisible()));
  t('accordion closed by default', !(await p.locator('.cfs-accordion-body').first().isVisible()));
  await p.locator('.cfs-accordion-head').first().click();
  t('accordion opens on click', await p.locator('.cfs-accordion-body').first().isVisible());
  t('fields after the accordion are inside it', await p.locator('.cfs-accordion-body').first().locator(nm('hero') .replace('hero','show_link')).count() === 1);
  t('Link url hidden while toggle off', !(await p.locator(nm('link_url')).isVisible()));
  await p.locator(nm('show_link')).check();
  await p.locator(nm('link_url')).waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
  t('Link url shows when toggle on', await p.locator(nm('link_url')).isVisible());
  await p.locator(nm('link_url')).fill('https://deep.test/x');
  await p.locator('[name="cfs[hero][heading]"]').fill('Hero heading');
  await p.evaluate(() => { document.getElementById('post').noValidate = true; });
  await p.locator('[name="cfs[hero][qty]"]').fill('42');
  // post object: single pick by search
  await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-search').fill('QA');
  await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-result').first().waitFor();
  await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-result').nth(0).click();
  await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-result').nth(1).click();
  t('single post object keeps only one choice', await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-selected li').count() === 1);
  const chosen = await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-selected li input').inputValue();
  await p.locator(nm('owner')).selectOption({ index: 1 });
  const owner = await p.locator(nm('owner')).inputValue();
  await p.locator('#content-tmce, #content-html').count();
  await p.screenshot({ path: `${process.env.SHOTS || '.'}/p10-page.png`, fullPage: true });
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const pid = await p.locator('#post_ID').inputValue();

  // ---- reload
  t('Kind persisted and Extra visible again', await p.locator(nm('kind')).inputValue() === 'b' && await p.locator(nm('extra')).isVisible() && await p.locator(nm('extra')).inputValue() === 'shown value');
  await p.locator('.cfs-tab-button').nth(1).click();
  await p.locator('.cfs-accordion-head').first().click();
  t('toggle persisted and Link url visible', await p.locator(nm('show_link')).isChecked() && await p.locator(nm('link_url')).isVisible() && await p.locator(nm('link_url')).inputValue() === 'https://deep.test/x');
  t('group values persisted', await p.locator('[name="cfs[hero][heading]"]').inputValue() === 'Hero heading' && await p.locator('[name="cfs[hero][qty]"]').inputValue() === '42');
  t('post object persisted', await p.locator('.cfs-relationship[data-max="1"] .cfs-rel-selected li input').inputValue() === chosen);
  t('user persisted', await p.locator(nm('owner')).inputValue() === owner);
  // turning logic source off hides again, value still saved
  await p.locator(nm('show_link')).uncheck();
  await p.locator(nm('link_url')).waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
  t('Link url hides again', !(await p.locator(nm('link_url')).isVisible()));
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  await p.locator('.cfs-tab-button').nth(1).click();
  await p.locator('.cfs-accordion-head').first().click();
  t('hidden field kept its value', await p.locator(nm('link_url')).inputValue() === 'https://deep.test/x');

  // ---- storage and front end
  await p.goto(`${BASE}/wp-admin/post.php?post=${pid}&action=edit`);
  await p.evaluate((v) => { const e = window.tinymce && tinymce.get('content'); if (e && !e.isHidden()) { e.setContent(v); e.save(); } else { document.getElementById('content').value = v; } }, '[cfs field="hero"]\n[cfs field="featured_page"]\n[cfs field="owner"]\n[cfs field="intro_note"]\n[cfs field="basics"]');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  await p.goto(`${BASE}/?page_id=${pid}`);
  const body = await p.content();
  t('front: group shows sub values', body.includes('cfs-out-group') && body.includes('Hero heading') && body.includes('>42<'));
  t('front: post object link', body.includes('cfs-out-post_object'));
  t('front: user name', body.includes('cfs-out-user'));
  t('front: layout fields print nothing', !body.includes('Fill both tabs') && !body.includes('cfs-tab-marker'));
  t('front: no PHP warnings', !/Warning:|Notice:|Fatal error|Deprecated:/.test(body));
  console.log('errs', errs.filter(e => !/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
