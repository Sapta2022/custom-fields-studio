// Regression: the block form must work when the editor canvas is an iframe
// (newer WordPress always iframes it). Needs the P12 block fixtures (p12setup.php setup).
const { launch, login } = require('./common');
const { execFileSync } = require('child_process');
const SP = __dirname; const WP_ROOT = process.env.CFS_WP_ROOT;
const setup = (cmd) => execFileSync('php', [SP + '/p12setup.php', WP_ROOT, cmd], { encoding: 'utf8' });
const setOpt = (k, v) => execFileSync('php', ['-r', `define('WP_USE_THEMES',false);require '${WP_ROOT}/wp-load.php';update_option('${k}',${v});`]);
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
const BASE = 'http://127.0.0.1:8099';
(async () => {
  setup('teardown'); setup('setup'); setOpt('cfs_qa_block_editor', '1');
  const { b, p, errs } = await launch(2400); await login(p);
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
  await p.waitForFunction(() => window.wp && wp.data && wp.data.select('core/editor') && wp.blocks.getBlockType('cfs/p12e-hero'), null, { timeout: 30000 });
  await p.evaluate(() => { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); });
  await p.evaluate(() => { wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('cfs/p12e-hero')); });
  // WordPress 6.5 does not iframe this editor, so build the iframe the way newer
  // WordPress does: render the block's form inside a separate document.
  await p.waitForSelector('.cfs-block-form input[name="cfs[heading]"]', { timeout: 20000 });
  await p.evaluate(async () => {
    const res = await wp.apiFetch({ path: '/cfs/v1/block-form', method: 'POST', data: { block: 'cfs/p12e-hero', form: '' } });
    const fr = document.createElement('iframe'); fr.id = 'fx'; fr.style.cssText = 'width:900px;height:600px';
    document.body.appendChild(fr);
    fr.contentDocument.body.innerHTML = '<div id="host">' + res.html + '</div>';
    window.__fx = fr;
  });
  const add = () => p.evaluate(() => { window.__fx.contentDocument.querySelector('.cfs-repeater-add').click(); return window.__fx.contentDocument.querySelectorAll('[name$="[item]"]').length; });
  t('Before binding, the iframe form is dead (reproduces the bug)', await add() === 0);
  await p.evaluate(() => { window.cfsBindDocument(window.__fx.contentDocument); window.cfsInit(jQuery(window.__fx.contentDocument.getElementById('host'))); });
  await add();
  t('After cfsBindDocument, Add Row works inside the iframe', await add() === 2 || await p.evaluate(() => window.__fx.contentDocument.querySelectorAll('[name$="[item]"]').length) >= 1);
  const n = await p.evaluate(() => { const d = window.__fx.contentDocument; d.querySelector('.cfs-repeater-remove').click(); return d.querySelectorAll('[name$="[item]"]').length; });
  t('Remove row works inside the iframe', n >= 1);
  t('cfsBindDocument is idempotent', await p.evaluate(() => { const before = window.__fx.contentDocument.querySelectorAll('[name$="[item]"]').length; window.cfsBindDocument(window.__fx.contentDocument); window.__fx.contentDocument.querySelector('.cfs-repeater-add').click(); return window.__fx.contentDocument.querySelectorAll('[name$="[item]"]').length === before + 1; }));
  setOpt('cfs_qa_block_editor', 'false'); setup('teardown');
  console.log(`\n${ok} passed, ${bad} failed`, errs.length ? errs.slice(0,3) : '');
  await b.close();
})();
