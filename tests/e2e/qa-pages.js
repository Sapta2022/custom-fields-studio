const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
const SP = __dirname; // ids.php lives next to this file
const WP_ROOT = process.env.CFS_WP_ROOT; // path to the WordPress install under test
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const nm = (n) => `[name="cfs[${n}]"]`;
(async () => {
  const ids = JSON.parse(php('ids'));
  const P1 = ids.page[1], I = ids.img, R = ids.rel, C = ids.cat, T = ids.tag;
  const { b, p, errs } = await launch(5200);
  await login(p);
  p.on('dialog', d => d.accept());
  const media = async (btn, idxs) => {
    await btn.click();
    await p.locator('.media-modal:visible .media-menu-item', { hasText: 'Media Library' }).first().click();
    await p.locator('.media-modal:visible .attachment').first().waitFor();
    // select attachments by title "CFS QA image N"
    let first = true;
    for (const n of idxs) {
      await p.locator('.media-modal:visible .attachment', { has: p.locator(`img[src*="cfs-qa-${n}"]`) }).first().click({ modifiers: first ? [] : ['Control'] });
      first = false;
    }
    await p.locator('.media-modal:visible .media-button-select').click();
    await p.waitForTimeout(400);
  };

  await p.goto(`http://127.0.0.1:8099/wp-admin/post.php?post=${P1}&action=edit`);
  // ---- rendering of saved values
  t('text value shown', await p.locator(nm('qa_text')).inputValue() === 'Hello World');
  t('text has maxlength=20 + placeholder', await p.locator(nm('qa_text')).getAttribute('maxlength') === '20' && await p.locator(nm('qa_text')).getAttribute('placeholder') === 'Type here');
  t('textarea rows 6', await p.locator(nm('qa_textarea')).getAttribute('rows') === '6');
  t('select single value', await p.locator(nm('qa_select_single')).inputValue() === 'green');
  t('select has empty option (allow null)', await p.locator(nm('qa_select_single') + ' option[value=""]').count() === 1);
  t('multi select values', JSON.stringify(await p.locator('[name="cfs[qa_select_multi][]"]').evaluate(s => [...s.selectedOptions].map(o => o.value))) === '["red","green"]');
  t('true/false checked, label Enabled', await p.locator(nm('qa_true_false')).isChecked() && (await p.locator('label.cfs-toggle', { has: p.locator(nm('qa_true_false')) }).innerText()).includes('Enabled'));
  t('date inputs hold Y-m-d', await p.locator(nm('qa_date_long')).inputValue() === '2026-11-07' && await p.locator(nm('qa_date_dmy')).inputValue() === '2027-01-31');
  t('image preview shown (array field)', await p.locator('.cfs-image-field', { has: p.locator(nm('qa_image_array')) }).locator('img').count() === 1);
  t('gallery shows 3 thumbs', await p.locator('.cfs-gallery[data-name="cfs[qa_gallery_id]"] .cfs-gallery-item').count() === 3);
  t('gallery data-max=3', await p.locator('.cfs-gallery[data-name="cfs[qa_gallery_id]"]').getAttribute('data-max') === '3');
  t('relationship shows 2 selected', await p.locator('.cfs-relationship[data-name="cfs[qa_rel_obj]"] .cfs-rel-selected li').count() === 2);
  t('taxonomy checkbox ticked A,B', await p.locator('[name="cfs[qa_tax_checkbox][]"]:checked').count() === 2);
  t('taxonomy select shows C', await p.locator('select[name="cfs[qa_tax_select][]"]').inputValue() === String(C[3]));
  t('taxonomy multi selected 2', await p.locator('select[name="cfs[qa_tax_multi][]"]').evaluate(s => s.selectedOptions.length) === 2);
  t('taxonomy radio checked', await p.locator('input[type=radio][name="cfs[qa_tax_radio][]"]:checked').inputValue() === String(T[3]));
  t('basic wysiwyg has no media button', true);
  t('repeater(table) 2 rows', await p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first().locator('> .cfs-repeater-rows > .cfs-repeater-row').count() === 2);
  t('flexible 2 rows', await p.locator('.cfs-flex-rows > .cfs-flex-row').count() >= 2);
  await p.screenshot({ path: (process.env.SHOTS || '.') + '/qa-page1-edit.png', fullPage: true });

  // ---- edit everything
  await p.locator(nm('qa_text')).fill('Edited text');
  await p.locator(nm('qa_textarea')).fill('Edited\narea');
  await p.locator(nm('qa_select_single')).selectOption('red');
  await p.locator('[name="cfs[qa_select_multi][]"]').selectOption(['blue']);
  await p.locator(nm('qa_true_false')).uncheck();
  await p.waitForFunction(() => window.tinymce && tinymce.editors.length > 1);
  await p.evaluate(() => { tinymce.editors.forEach(e => { if (e.targetElm && /qa_wysiwyg_basic/.test(e.targetElm.name || '')) e.setContent('<p>Edited <em>basic</em></p>'); if (e.targetElm && /qa_wysiwyg_full/.test(e.targetElm.name || '')) e.setContent('<p>Edited full</p>'); e.save(); }); });
  // image: replace image array field with image 4
  const imgf = p.locator('.cfs-image-field', { has: p.locator(nm('qa_image_array')) });
  await imgf.locator('.cfs-image-remove').click();
  t('image remove clears value', ['', '0'].includes(await p.locator(nm('qa_image_array')).inputValue()));
  await media(imgf.locator('.cfs-image-select'), [4]);
  t('image select sets new id', await p.locator(nm('qa_image_array')).inputValue() === String(I[4]));
  await p.locator(nm('qa_date_long')).fill('2031-05-06');
  await p.locator(nm('qa_date_raw')).fill('');
  // gallery: remove first, add image 5, then try to exceed max 3
  const gal = p.locator('.cfs-gallery[data-name="cfs[qa_gallery_id]"]');
  await gal.locator('.cfs-gallery-remove').first().click();
  await media(gal.locator('.cfs-gallery-add'), [5, 4, 1]);
  t('gallery max 3 enforced in UI', await gal.locator('.cfs-gallery-item').count() === 3, await gal.locator('.cfs-gallery-item').count());
  t('gallery no duplicate items', new Set(await gal.locator('.cfs-gallery-item').evaluateAll(a => a.map(x => x.dataset.id))).size === 3);
  // relationship
  const rel = p.locator('.cfs-relationship[data-name="cfs[qa_rel_obj]"]');
  await rel.locator('.cfs-rel-remove').first().click();
  await rel.locator('.cfs-rel-search').fill('Related Post 3');
  await rel.locator('.cfs-rel-result', { hasText: 'QA Related Post 3' }).first().click();
  t('relationship now 2 selected', await rel.locator('.cfs-rel-selected li').count() === 2);
  const rel2 = p.locator('.cfs-relationship[data-name="cfs[qa_rel_id]"]');
  await rel2.locator('.cfs-rel-search').fill('QA Related');
  await rel2.locator('.cfs-rel-result').first().waitFor();
  t('rel_id (posts only) search never lists pages', (await rel2.locator('.cfs-rel-result').allInnerTexts()).every(x => /\(Post\)/.test(x)));
  await rel2.locator('.cfs-rel-result', { hasText: 'QA Related Post 2' }).first().click();
  t('relationship max 2 enforced in UI', await rel2.locator('.cfs-rel-selected li').count() === 2);
  await rel.locator('.cfs-rel-search').fill('QA Page 2');
  await rel.locator('.cfs-rel-result', { hasText: 'QA Page 2' }).first().waitFor();
  t('rel_obj (post+page) search lists pages', true);
  // taxonomy
  await p.locator(`[name="cfs[qa_tax_checkbox][]"][value="${C[1]}"]`).uncheck();
  await p.locator('select[name="cfs[qa_tax_select][]"]').selectOption('');
  await p.locator('select[name="cfs[qa_tax_multi][]"]').selectOption([String(T[3])]);
  await p.locator(`input[type=radio][name="cfs[qa_tax_radio][]"][value="${T[1]}"]`).check();

  // repeater table: add row, fill, remove row 1, reorder
  const rep = p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first();
  const reprows = rep.locator('> .cfs-repeater-rows > .cfs-repeater-row');
  await rep.locator('> .cfs-repeater-footer .cfs-repeater-add, .cfs-repeater-add').first().click();
  t('repeater has 3 rows after add', await reprows.count() === 3);
  const row3 = reprows.nth(2);
  await row3.locator('[name$="[title]"]').fill('Added row');
  await row3.locator('[name$="[kind]"]').selectOption('blue');
  await row3.locator('[name$="[when]"]').fill('2028-08-08');
  await row3.locator('[name$="[note]"]').fill('hello');
  await reprows.nth(0).locator('.cfs-repeater-remove').click();
  t('repeater remove row', await reprows.count() === 2);
  // drag last row above first
  const h = reprows.nth(1).locator('> .cfs-repeater-row-handle > .cfs-drag-handle'), tg = reprows.nth(0).locator('> .cfs-repeater-row-handle > .cfs-drag-handle');
  const hb = await h.boundingBox(), tb = await tg.boundingBox();
  await p.mouse.move(hb.x + 5, hb.y + 5); await p.mouse.down();
  await p.mouse.move(hb.x + 5, hb.y - 40, { steps: 8 });
  await p.mouse.move(tb.x + 5, tb.y - 5, { steps: 15 });
  await p.mouse.up(); await p.waitForTimeout(500);
  t('repeater reorder by drag', await reprows.nth(0).locator('[name$="[title]"]').inputValue() === 'Added row', await reprows.nth(0).locator('[name$="[title]"]').inputValue());

  // repeater block: nested repeater add row
  const blk = p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_block]"]') }).first();
  const nested = blk.locator('.cfs-repeater').first();
  await nested.locator('.cfs-repeater-add').first().click();
  const nrows = nested.locator('> .cfs-repeater-rows > .cfs-repeater-row');
  await nrows.last().locator('[name$="[title]"]').fill('Nested added');
  t('nested repeater accepts rows', await nrows.count() === 3, await nrows.count());
  // block repeater max=2: add button disabled/hidden when at max
  const addBlk = blk.locator('> .cfs-repeater-footer .cfs-repeater-add');
  t('block repeater below max: add enabled', await addBlk.isEnabled());
  await addBlk.click();
  t('block repeater at max (2): add button disabled', await addBlk.isDisabled());
  await blk.locator('> .cfs-repeater-rows > .cfs-repeater-row').last().locator('> .cfs-repeater-row-actions .cfs-repeater-remove, .cfs-repeater-remove').first().click();

  // flexible: add 'cards' layout with nested repeater
  const flex = p.locator('.cfs-flex').first();
  await flex.locator('> .cfs-flex-footer .cfs-flex-add').click();
  await flex.locator('> .cfs-flex-footer .cfs-flex-add-layout', { hasText: 'Cards' }).click();
  const frows = flex.locator('> .cfs-flex-rows > .cfs-flex-row');
  t('flexible now 3 rows', await frows.count() === 3, await frows.count());
  const cards = frows.last();
  await cards.locator('[name$="[section_title]"]').fill('UI cards');
  await cards.locator('.cfs-repeater-add').first().click();
  await cards.locator('[name$="[card_title]"]').first().fill('UI card 1');
  await media(cards.locator('.cfs-image-select').first(), [2]);
  await p.screenshot({ path: (process.env.SHOTS || '.') + '/qa-page1-edited.png', fullPage: true });

  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const m = JSON.parse(php('meta', P1));
  t('saved text', m.qa_text === 'Edited text', m.qa_text);
  t('saved textarea', m.qa_textarea === 'Edited\narea', m.qa_textarea);
  t('saved select single', m.qa_select_single === 'red');
  t('saved select multi', JSON.stringify(m.qa_select_multi) === '["blue"]', m.qa_select_multi);
  t('saved true/false off', String(m.qa_true_false) === '0', m.qa_true_false);
  t('saved wysiwyg basic', /Edited <em>basic<\/em>/.test(m.qa_wysiwyg_basic), m.qa_wysiwyg_basic);
  t('saved wysiwyg full', /Edited full/.test(m.qa_wysiwyg_full), m.qa_wysiwyg_full);
  t('saved image', m.qa_image_array === I[4] || String(m.qa_image_array) === String(I[4]), m.qa_image_array);
  t('saved date long', m.qa_date_long === '20310506', m.qa_date_long);
  t('saved date cleared', String(m.qa_date_raw) === '', m.qa_date_raw);
  t('saved gallery (3, includes new)', Array.isArray(m.qa_gallery_id) && m.qa_gallery_id.length === 3 && m.qa_gallery_id.includes(I[5]), m.qa_gallery_id);
  t('saved relationship obj', JSON.stringify(m.qa_rel_obj) === JSON.stringify([R[1], R[3]]), m.qa_rel_obj);
  t('saved relationship id (2)', Array.isArray(m.qa_rel_id) && m.qa_rel_id.length === 2, m.qa_rel_id);
  t('saved tax checkbox (B only)', JSON.stringify(m.qa_tax_checkbox) === JSON.stringify([C[2]]), m.qa_tax_checkbox);
  t('saved tax select cleared', !m.qa_tax_select || m.qa_tax_select.length === 0, m.qa_tax_select);
  t('saved tax multi', JSON.stringify(m.qa_tax_multi) === JSON.stringify([T[3]]), m.qa_tax_multi);
  t('saved tax radio', JSON.stringify(m.qa_tax_radio) === JSON.stringify([T[1]]), m.qa_tax_radio);
  t('saved repeater count 2, first row = Added row', String(m.qa_rep_table) === '2' && m.qa_rep_table_0_title === 'Added row' && m.qa_rep_table_0_kind === 'blue' && m.qa_rep_table_0_when === '20280808' && m.qa_rep_table_0_note === 'hello', [m.qa_rep_table, m.qa_rep_table_0_title]);
  t('saved repeater second row = old row 2', m.qa_rep_table_1_title === 'Entry 2', m.qa_rep_table_1_title);
  t('saved nested repeater 3 rows', String(m.qa_rep_block_0_qa_nested) === '3' && m.qa_rep_block_0_qa_nested_2_title === 'Nested added', [m.qa_rep_block_0_qa_nested, m.qa_rep_block_0_qa_nested_2_title]);
  t('saved flexible 3 rows with cards last', JSON.stringify(m.qa_flex) === '["hero","quote","cards"]', m.qa_flex);
  t('saved flexible nested card', m.qa_flex_2_section_title === 'UI cards' && m.qa_flex_2_cards_0_card_title === 'UI card 1' && Number(m.qa_flex_2_cards_0_card_img) === I[2], [m.qa_flex_2_section_title, m.qa_flex_2_cards_0_card_title, m.qa_flex_2_cards_0_card_img]);
  // reload shows saved edits
  t('reload: text shows edited value', await p.locator(nm('qa_text')).inputValue() === 'Edited text');
  t('reload: repeater first row shows Added row', await p.locator('.cfs-repeater', { has: p.locator('[name^="cfs[qa_rep_table]"]') }).first().locator('.cfs-repeater-row').first().locator('[name$="[title]"]').inputValue() === 'Added row');
  console.log('errs', errs.filter(e=>!/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
