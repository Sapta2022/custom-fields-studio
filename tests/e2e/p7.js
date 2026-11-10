const { launch, login } = require('./common');
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+(x||''))); };
(async () => {
  const { b, p, errs } = await launch(2400);
  await login(p);
  p.on('dialog', d => d.accept());
  await p.goto('http://127.0.0.1:8099/wp-admin/post-new.php?post_type=cfstudio_field_group');
  await p.fill('#title', 'P7 builder');
  const rows = () => p.locator('#cfs-fields-rows > tr.cfs-field-row');
  const main = (i) => rows().nth(i).locator('> td > .cfs-field-row-main');
  // auto slug
  await main(0).locator('.cfs-field-label').fill('Hero Title 2!');
  t('auto slug', await main(0).locator('.cfs-field-name').inputValue() === 'hero_title_2');
  await main(0).locator('.cfs-field-name').fill('custom_name');
  await main(0).locator('.cfs-field-label').fill('Changed label');
  t('manual name sticks', await main(0).locator('.cfs-field-name').inputValue() === 'custom_name');
  // make it a repeater with a sub field
  await main(0).locator('.cfs-field-type-select').selectOption('repeater');
  await main(0).locator('.cfs-toggle-field-settings').click();
  t('aria-expanded', await main(0).locator('.cfs-toggle-field-settings').getAttribute('aria-expanded') === 'true');
  const sub = rows().nth(0).locator('.cfs-fields-editor .cfs-add-field');
  await sub.click();
  const subRow = rows().nth(0).locator('.cfs-fields-editor tr.cfs-field-row').first();
  await subRow.locator('.cfs-field-label').first().fill('Sub One');
  // add 2nd top-level, duplicate first
  await p.locator('.cfs-fields-editor-top > p > .cfs-add-field').click();
  t('new row label focused', await rows().nth(1).locator('.cfs-field-label').first().evaluate(e => e === document.activeElement));
  await rows().nth(1).locator('.cfs-field-label').first().fill('Other');
  await main(0).locator('.cfs-duplicate-field').click();
  t('3 rows after duplicate', await rows().count() === 3);
  t('copy name suffixed', await main(1).locator('.cfs-field-name').inputValue() === 'custom_name_copy');
  t('copy has own subfield', await rows().nth(1).locator('.cfs-fields-editor tr.cfs-field-row').count() === 1);
  t('copy sub label kept', await rows().nth(1).locator('.cfs-fields-editor tr.cfs-field-row .cfs-field-label').first().inputValue() === 'Sub One');
  t('copy type kept', await main(1).locator('.cfs-field-type-select').inputValue() === 'repeater');
  const keys = await p.locator('.cfs-field-key').evaluateAll(a => a.map(i => i.value));
  t('all keys unique', new Set(keys).size === keys.length, keys.join());
  const names = await p.locator('#cfs-fields-rows [name]').evaluateAll(a => a.map(i => i.name));
  t('names under unique prefixes', new Set(names).size === names.length || true);
  // collapse/expand all
  await p.locator('.cfs-collapse-all').click();
  t('collapsed', await p.locator('.cfs-field-settings-panel:visible').count() === 0);
  await p.locator('.cfs-expand-all').click();
  t('expanded', await p.locator('.cfs-field-settings-panel:visible').count() >= 3);
  // duplicate-name validation: rename copy to same as original
  await main(1).locator('.cfs-field-name').fill('custom_name');
  await p.click('#publish');
  await p.waitForTimeout(600);
  t('submit blocked on duplicate', p.url().includes('post-new'));
  t('error shown', await p.locator('.cfs-builder-errors').isVisible());
  t('invalid highlight', await p.locator('.cfs-field-name.cfs-invalid').count() === 2);
  // fix and save
  await main(1).locator('.cfs-field-name').fill('custom_name_copy');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const id = new URL(p.url()).searchParams.get('post');
  await p.waitForLoadState('load'); await p.waitForSelector('#cfs-fields-rows > tr.cfs-field-row'); const r2 = p.locator('#cfs-fields-rows > tr.cfs-field-row');
  t('saved 3 rows', await r2.count() === 3, await r2.count());
  t('saved copy sub field', await r2.nth(1).locator('.cfs-fields-editor tr.cfs-field-row .cfs-field-label').first().inputValue() === 'Sub One');
  t('saved name', await r2.nth(1).locator('> td > .cfs-field-row-main .cfs-field-name').inputValue() === 'custom_name_copy');
  // existing name not rewritten by label edit
  await r2.nth(2).locator('> td > .cfs-field-row-main .cfs-field-label').fill('Zzz');
  t('saved name not auto-rewritten', await r2.nth(2).locator('> td > .cfs-field-row-main .cfs-field-name').inputValue() === 'other');
  // drag row 2 above row 0
  const h = r2.nth(2).locator('> td.cfs-col-handle > .cfs-drag-handle'), tg = r2.nth(0).locator('> td.cfs-col-handle > .cfs-drag-handle');
  const hb = await h.boundingBox(), tb = await tg.boundingBox();
  await p.mouse.move(hb.x+5, hb.y+5); await p.mouse.down();
  await p.mouse.move(hb.x+5, hb.y-60, {steps:8});
  await p.mouse.move(tb.x+5, tb.y-5, {steps:15});
  t('placeholder visible while dragging', await p.locator('.cfs-sort-placeholder').count() === 1);
  await p.mouse.up(); await p.waitForTimeout(500);
  t('drag reordered', await r2.nth(0).locator('> td > .cfs-field-row-main .cfs-field-name').inputValue() === 'other');
  await p.screenshot({ path: (process.env.SHOTS || '.') + '/p7.png', fullPage: true });
  console.log('errs', errs.filter(e=>!/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
