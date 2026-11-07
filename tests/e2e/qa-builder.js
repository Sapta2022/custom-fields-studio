const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
const SP = __dirname; // ids.php lives next to this file
const WP_ROOT = process.env.CFS_WP_ROOT; // path to the WordPress install under test
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+(x||''))); };
(async () => {
  const ids = JSON.parse(php('ids'));
  const { b, p, errs } = await launch(2600);
  await login(p);
  p.on('dialog', d => d.accept());
  for (const key of ['basic','repeater','options']) {
    const gid = ids.group[key];
    const before = php('group', gid);
    await p.goto(`http://127.0.0.1:8099/wp-admin/post.php?post=${gid}&action=edit`);
    const nrows = await p.locator('#cfs-fields-rows > tr.cfs-field-row').count();
    t(`[${key}] builder shows all top-level rows`, nrows === JSON.parse(before).length, nrows);
    await p.locator('.cfs-expand-all').click();
    // every row's visible settings blocks must match its type
    const bad_vis = await p.evaluate(() => {
      const out = [];
      document.querySelectorAll('tr.cfs-field-row').forEach(tr => {
        const type = tr.querySelector(':scope > td > .cfs-field-row-main .cfs-field-type-select').value;
        tr.querySelectorAll(':scope > td > .cfs-field-settings-panel > .cfs-field-setting').forEach(s => {
          const shown = s.style.display !== 'none';
          if (shown !== s.dataset.type.split(' ').includes(type)) out.push(type + '/' + s.dataset.type);
        });
      });
      return out;
    });
    t(`[${key}] only the matching type's settings are visible`, bad_vis.length === 0, bad_vis.slice(0,5).join());
    await p.screenshot({ path: `${process.env.SHOTS || '.'}/qa-builder-${key}.png`, fullPage: true });
    await Promise.all([p.waitForNavigation(), p.click('#publish')]);
    const after = php('group', gid);
    t(`[${key}] open+Update through the builder UI changes nothing`, before === after, (() => { for (let i=0;i<Math.min(before.length,after.length);i++) if (before[i]!==after[i]) return 'diff@'+i+' '+before.slice(Math.max(0,i-60),i+80)+' ||| '+after.slice(Math.max(0,i-60),i+80); return 'len '+before.length+' vs '+after.length; })());
  }
  console.log('errs', errs.filter(e=>!/Failed to load resource/.test(e)));
  console.log(ok+' passed, '+bad+' failed');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
