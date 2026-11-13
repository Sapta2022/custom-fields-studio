// Conditional logic scoped per repeater row / inside a group.
const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE='http://127.0.0.1:8099';
(async () => {
  const root = process.env.CFS_WP_ROOT;
  const gid = execFileSync('php', [__dirname + '/mkcond.php', root], { encoding: 'utf8' }).trim();
  const { b, p, errs } = await launch(2600);
  await login(p);
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.fill('#title', 'P10b page');
  const box = p.locator('#cfstudio_group_' + gid);
  const rowsL = box.locator('.cfs-repeater-row');
  await box.locator('.cfs-repeater-add').first().click();
  await box.locator('.cfs-repeater-add').first().click();
  t('two rows', await rowsL.count() === 2, await rowsL.count());
  const dep = (i) => rowsL.nth(i).locator('input[name$="[detail]"]');
  t('detail hidden in both rows at first', !(await dep(0).isVisible()) && !(await dep(1).isVisible()));
  await rowsL.nth(1).locator('input[type=checkbox][name$="[more]"]').check();
  await dep(1).waitFor({ state: 'visible', timeout: 3000 });
  t('only row 2 shows its detail', !(await dep(0).isVisible()) && await dep(1).isVisible());
  await rowsL.nth(0).locator('input[type=checkbox][name$="[more]"]').check();
  await dep(0).waitFor({ state: 'visible', timeout: 3000 });
  await rowsL.nth(1).locator('input[type=checkbox][name$="[more]"]').uncheck();
  await dep(1).waitFor({ state: 'hidden', timeout: 3000 });
  t('row 1 shows, row 2 hides independently', await dep(0).isVisible() && !(await dep(1).isVisible()));
  await box.locator('.cfs-repeater-add').first().click();
  t('a freshly added row starts hidden', !(await dep(2).isVisible()));
  // group scope: sibling inside the group, not the page-level field of the same name
  t('group dependent hidden', !(await p.locator('input[name="cfstudio[cgrp][note]"]').isVisible()));
  await p.locator('select[name="cfstudio[cgrp][mode]"]').selectOption('custom');
  await p.locator('input[name="cfstudio[cgrp][note]"]').waitFor({ state: 'visible', timeout: 3000 });
  t('group dependent shows when its sibling matches', await p.locator('input[name="cfstudio[cgrp][note]"]').isVisible());
  // 'any' + two rules, '!empty' and '>'
  t('any-match field hidden at first', !(await p.locator('input[name="cfstudio[anyfield]"]').isVisible()));
  await p.locator('input[name="cfstudio[qty]"]').fill('11');
  await p.locator('input[name="cfstudio[anyfield]"]').waitFor({ state: 'visible', timeout: 3000 });
  t('"greater than" rule alone satisfies match=any', await p.locator('input[name="cfstudio[anyfield]"]').isVisible());
  await p.locator('input[name="cfstudio[qty]"]').fill('3');
  await p.locator('input[name="cfstudio[tag]"]').fill('x');
  await p.locator('input[name="cfstudio[anyfield]"]').waitFor({ state: 'visible', timeout: 3000 });
  t('"not empty" rule alone satisfies match=any', await p.locator('input[name="cfstudio[anyfield]"]').isVisible());
  await p.locator('input[name="cfstudio[tag]"]').fill('');
  await p.locator('input[name="cfstudio[anyfield]"]').waitFor({ state: 'hidden', timeout: 3000 });
  t('neither rule matches -> hidden', !(await p.locator('input[name="cfstudio[anyfield]"]').isVisible()));
  // checkbox group "contains"
  await p.locator('input[name="cfstudio[colours][]"][value="red"]').check();
  await p.locator('input[name="cfstudio[red_only]"]').waitFor({ state: 'visible', timeout: 3000 });
  t('checkbox-group value matches == for a multi value', await p.locator('input[name="cfstudio[red_only]"]').isVisible());
  await p.locator('input[name="cfstudio[colours][]"][value="red"]').uncheck();
  await p.locator('input[name="cfstudio[red_only]"]').waitFor({ state: 'hidden', timeout: 3000 });
  t('unticking hides it again', !(await p.locator('input[name="cfstudio[red_only]"]').isVisible()));
  console.log('errs', errs.filter(e => !/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
