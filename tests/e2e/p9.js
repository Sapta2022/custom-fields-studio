// Phase 9: build a group with the 14 new field types in the builder UI,
// fill a page through the real meta box, publish, reload, verify, front end.
const { launch, login } = require('./common');
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE = 'http://127.0.0.1:8099';
(async () => {
  const { b, p, errs } = await launch(4200);
  await login(p);
  p.on('dialog', d => d.accept());
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=cfs_field_group`);
  await p.fill('#title', 'P9 all new types');
  const rows = () => p.locator('#cfs-fields-rows > tr.cfs-field-row');
  const main = (i) => rows().nth(i).locator('> td > .cfs-field-row-main');
  const panel = (i) => rows().nth(i).locator('> td > .cfs-field-settings-panel');
  const types = [
    ['P9 Number','number',{min:'5',max:'10',step:'0.5',prepend:'£',append:'kg'}],
    ['P9 Range','range',{min:'0',max:'50',step:'5',default_value:'25'}],
    ['P9 Email','email',{placeholder:'you@x.com'}],
    ['P9 Url','url',{placeholder:'https://'}],
    ['P9 Password','password',{placeholder:'secret'}],
    ['P9 Checkbox','checkbox',{choices:'red : Red\nblue : Blue\ngreen : Green',default_value:'red',layout:'horizontal',return_format:'array'}],
    ['P9 Radio','radio',{choices:'a : Alpha\nb : Beta',allow_null:true,return_format:'label'}],
    ['P9 Buttons','button_group',{choices:'s : Small\nm : Medium\nl : Large',default_value:'m'}],
    ['P9 File','file',{mime_types:'pdf, txt',return_format:'array'}],
    ['P9 Color','color_picker',{default_value:'#336699'}],
    ['P9 Time','time_picker',{return_format:'H:i'}],
    ['P9 Datetime','date_time_picker',{return_format:'Y-m-d H:i'}],
    ['P9 Link','link',{}],
    ['P9 Embed','oembed',{}],
  ];
  for (let i = 0; i < types.length; i++) {
    const [label, type, settings] = types[i];
    if (i > 0) await p.locator('.cfs-fields-editor-top > p > .cfs-add-field').click();
    await main(i).locator('.cfs-field-label').fill(label);
    await main(i).locator('.cfs-field-type-select').selectOption(type);
    await main(i).locator('.cfs-toggle-field-settings').click();
    const visible = await panel(i).locator(`> .cfs-field-setting[data-type="${type}"]`).evaluateAll(a => a.filter(e => e.style.display !== 'none').length);
    t(`builder shows settings for ${type}`, visible > 0, visible);
    for (const [k, v] of Object.entries(settings)) {
      const el = panel(i).locator(`> .cfs-field-setting[data-type="${type}"] [name$="[${k}]"]`);
      if (v === true) await el.check();
      else if ((await el.evaluate(e => e.tagName)) === 'SELECT') await el.selectOption(v);
      else await el.fill(v);
    }
  }
  await p.locator('select[name^="cfs_location"][name$="[value]"]').first().selectOption('page');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const gid = new URL(p.url()).searchParams.get('post');
  t('group published', !!gid);
  // saved settings survive a reload of the builder
  await p.reload();
  await p.locator('.cfs-expand-all').click();
  const types_back = await p.locator('.cfs-field-type-select').evaluateAll(a => a.map(e => e.value));
  t('all 14 types persisted', JSON.stringify(types_back.slice(0, 14)) === JSON.stringify(types.map(x => x[1])), types_back);
  t('number min persisted', await panel(0).locator('[name$="[min]"]').first().inputValue() === '5');
  t('checkbox layout persisted', await panel(5).locator('[name$="[layout]"]').first().inputValue() === 'horizontal');
  t('file types persisted', await panel(8).locator('[name$="[mime_types]"]').first().inputValue() === 'pdf, txt');
  // open+Update with no change keeps the settings
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  await p.locator('.cfs-expand-all').click();
  t('Update with no change keeps radio allow_null', await panel(6).locator('[name$="[allow_null]"]').first().isChecked());

  // ---- a page using the group
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.fill('#title', 'P9 page');
  const nm = (n) => `[name="cfs[${n}]"]`;
  t('number: step/min/max attrs', await p.locator(nm('p9_number')).getAttribute('min') === '5' && await p.locator(nm('p9_number')).getAttribute('step') === '0.5');
  t('range default 25 and output', await p.locator(nm('p9_range')).inputValue() === '25' && (await p.locator('.cfs-range-out').first().innerText()) === '25');
  t('checkbox default ticked', await p.locator('input[name="cfs[p9_checkbox][]"][value="red"]').isChecked());
  t('button group default active', await p.locator('.cfs-button-group label.cfs-active').innerText().then(x => x.trim() === 'Medium'));
  t('colour default shown', await p.locator(nm('p9_color')).inputValue() === '#336699');
  t('password field is type=password', await p.locator(nm('p9_password')).getAttribute('type') === 'password');

  // browsers would block 99 (> max) client-side; skip that to prove the server clamps too
  await p.evaluate(() => { document.getElementById('post').noValidate = true; });
  await p.locator(nm('p9_number')).fill('99');
  await p.locator(nm('p9_range')).evaluate(e => { e.value = 40; e.dispatchEvent(new Event('input', { bubbles: true })); });
  t('range read-out follows slider', (await p.locator('.cfs-range-out').first().innerText()) === '40');
  await p.locator(nm('p9_email')).fill('qa@example.com');
  await p.locator(nm('p9_url')).fill('https://example.com/path');
  await p.locator(nm('p9_password')).fill('pa ss<b>w</b>');
  await p.locator('input[name="cfs[p9_checkbox][]"][value="blue"]').check();
  await p.locator('input[name="cfs[p9_checkbox][]"][value="red"]').uncheck();
  await p.locator('input[name="cfs[p9_radio]"][value="b"]').check();
  await p.locator('.cfs-button-group label', { hasText: 'Large' }).click();
  t('button group click moves active class', (await p.locator('.cfs-button-group label.cfs-active').innerText()).trim() === 'Large');
  await p.locator(nm('p9_color')).fill('#ff6600');
  t('colour text drives swatch', await p.locator('.cfs-color-pick').first().inputValue() === '#ff6600');
  await p.locator('.cfs-color-pick').first().evaluate(e => { e.value = '#00aa55'; e.dispatchEvent(new Event('input', { bubbles: true })); });
  t('colour swatch drives text', await p.locator(nm('p9_color')).inputValue() === '#00aa55');
  await p.locator(nm('p9_time')).fill('13:45:00');
  await p.locator(nm('p9_datetime')).fill('2026-11-07T10:15:00');
  await p.locator('input[name="cfs[p9_link][url]"]').fill('https://link.test/a');
  await p.locator('input[name="cfs[p9_link][title]"]').fill('Visit us');
  await p.locator('input[name="cfs[p9_link][target]"]').check();
  await p.locator(nm('p9_embed')).fill('https://example.com/not-embeddable');
  // file via the media modal: upload a txt through the uploader
  const fs = require('fs'); fs.writeFileSync('/tmp/p9-upload.txt', 'hello cfs');
  await p.locator('.cfs-file-select').click();
  await p.locator('.media-modal:visible input[type=file]').setInputFiles('/tmp/p9-upload.txt');
  await p.locator('.media-modal:visible .attachment.selected, .media-modal:visible .attachment.details').first().waitFor({ timeout: 20000 }).catch(() => {});
  await p.locator('.media-modal:visible .media-button-select').click();
  await p.waitForTimeout(500);
  t('file name shown after pick', (await p.locator('.cfs-file-name').innerText()).includes('p9-upload'), await p.locator('.cfs-file-name').innerText());
  await p.screenshot({ path: `${process.env.SHOTS || '.'}/p9-page.png`, fullPage: true });
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const pid = await p.locator('#post_ID').inputValue();
  t('page saved', !!pid);

  // ---- values after reload
  t('number clamped to max 10', await p.locator(nm('p9_number')).inputValue() === '10');
  t('range 40 stored', await p.locator(nm('p9_range')).inputValue() === '40');
  t('email stored', await p.locator(nm('p9_email')).inputValue() === 'qa@example.com');
  t('url stored', await p.locator(nm('p9_url')).inputValue() === 'https://example.com/path');
  t('password tags stripped', await p.locator(nm('p9_password')).inputValue() === 'pa ssw');
  t('checkbox: blue on, red off (default does not return)', await p.locator('input[name="cfs[p9_checkbox][]"][value="blue"]').isChecked() && !(await p.locator('input[name="cfs[p9_checkbox][]"][value="red"]').isChecked()));
  t('radio stored', await p.locator('input[name="cfs[p9_radio]"][value="b"]').isChecked());
  t('button group stored', (await p.locator('.cfs-button-group label.cfs-active').innerText()).trim() === 'Large');
  t('colour stored', await p.locator(nm('p9_color')).inputValue() === '#00aa55');
  t('time stored', await p.locator(nm('p9_time')).inputValue() === '13:45:00');
  t('datetime stored', ['2026-11-07T10:15','2026-11-07T10:15:00'].includes(await p.locator(nm('p9_datetime')).inputValue()));
  t('link stored', await p.locator('input[name="cfs[p9_link][url]"]').inputValue() === 'https://link.test/a' && await p.locator('input[name="cfs[p9_link][title]"]').inputValue() === 'Visit us' && await p.locator('input[name="cfs[p9_link][target]"]').isChecked());
  t('file stored', (await p.locator('.cfs-file-name').innerText()).includes('p9-upload'));

  // clear things and save again: clearing really clears
  await p.locator('.cfs-file-remove').click();
  await p.locator('input[name="cfs[p9_checkbox][]"]').evaluateAll(a => a.forEach(e => { e.checked = false; }));
  await p.locator(nm('p9_email')).fill('');
  await p.locator('input[name="cfs[p9_link][url]"]').fill('');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  t('cleared file stays cleared', !(await p.locator('.cfs-file-name').isVisible()));
  t('un-ticking all checkboxes saves nothing', (await p.locator('input[name="cfs[p9_checkbox][]"]:checked').count()) === 0);
  t('cleared email stays empty', await p.locator(nm('p9_email')).inputValue() === '');
  t('cleared link stays empty', await p.locator('input[name="cfs[p9_link][url]"]').inputValue() === '');

  // ---- front end via the shortcode
  await p.goto(`${BASE}/wp-admin/post.php?post=${pid}&action=edit`);
  await p.evaluate(() => { document.getElementById('post').noValidate = true; });
  await p.evaluate((v) => { const e = window.tinymce && tinymce.get('content'); if (e && !e.isHidden()) { e.setContent(v); e.save(); } else { document.getElementById('content').value = v; } }, ['p9_number','p9_range','p9_url','p9_radio','p9_buttons','p9_color','p9_time','p9_datetime'].map(n => `[cfs field="${n}"]`).join('\n') + '\n[cfs field="p9_checkbox"]');
  await p.locator('input[name="cfs[p9_url]"]').fill('https://example.com/path').catch(() => {});
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  await p.goto(`${BASE}/?page_id=${pid}`);
  const body = await p.content();
  t('front: number', /cfs-out-field[^>]*>10</.test(body));
  t('front: url link', body.includes('cfs-out-url'));
  t('front: radio label', body.includes('Beta'));
  t('front: colour swatch', body.includes('cfs-out-color') && body.includes('#00aa55'));
  t('front: time formatted H:i', body.includes('>13:45<'));
  t('front: datetime formatted', body.includes('2026-11-07 10:15'));
  t('front: no PHP warnings', !/Warning:|Notice:|Fatal error|Deprecated:/.test(body));
  console.log('errs', errs.filter(e => !/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
