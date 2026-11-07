const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const SP = __dirname; // ids.php lives next to this file
const WP_ROOT = process.env.CFS_WP_ROOT; // path to the WordPress install under test
const php = (...a) => execFileSync('php', [SP + '/ids.php', WP_ROOT, ...a], { encoding: 'utf8' });
let ok=0,bad=0; const t=(n,c,x)=>{ c?ok++:bad++; console.log((c?'PASS ':'FAIL ')+n+(c?'':' '+JSON.stringify(x))); };
(async () => {
  const ids = JSON.parse(php('ids'));
  const b = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1200, height: 3000 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  const visit = async (n) => {
    await p.goto(`http://127.0.0.1:8099/?page_id=${ids.page[n]}`);
    return { text: await p.locator('.entry-content, article, main, body').first().innerText(), html: await p.content() };
  };
  for (const n of [1, 2, 3]) {
    const { text, html } = await visit(n);
    t(`Page ${n}: no PHP warnings/notices/fatals in output`, !/(Warning|Notice|Fatal error|Deprecated):/.test(html), (html.match(/(Warning|Notice|Fatal error|Deprecated):[^<]{0,120}/) || [])[0]);
    t(`Page ${n}: no unrendered [cfs] shortcodes`, !/\[cfs field=/.test(html));
    t(`Page ${n}: has content blocks`, (await p.locator('[class*="cfs-out"]').count()) > 5, await p.locator('[class*="cfs-out"]').count());
    const broken = await p.evaluate(() => [...document.querySelectorAll('[class*="cfs-out"] img')].filter(i => !i.complete || i.naturalWidth === 0).map(i => i.src));
    t(`Page ${n}: every CFS image loads`, broken.length === 0, broken);
    await p.screenshot({ path: `${process.env.SHOTS || '.'}/qa-front-${n}.png`, fullPage: true });
    if (n === 1) {
      t('P1 text', text.includes('Hello World'));
      t('P1 textarea two lines', text.includes('Line one') && text.includes('Line two'));
      t('P1 select values', text.includes('green') && text.includes('red, green'));
      t('P1 true/false Yes', /Yes/.test(text));
      t('P1 dates formatted', text.includes('November 7, 2026') && text.includes('20261225') && text.includes('31/01/2027'));
      t('P1 relationship links (post titles)', text.includes('QA Related Post 2') && await p.locator('.cfs-out-relationship a').count() >= 4);
      t('P1 taxonomy term names', text.includes('QA Cat A') && text.includes('QA Cat C') && text.includes('qa-tag-3'));
      t('P1 gallery images (3+2+2 + 2 in repeater)', await p.locator('.cfs-out-gallery img').count() === 9, await p.locator('.cfs-out-gallery img').count());
      t('P1 wysiwyg bold rendered as <strong>', await p.locator('.cfs-out-wysiwyg strong').count() >= 1);
      t('P1 repeater rows', await p.locator('.cfs-out-repeater .cfs-out-row').count() >= 2);
      t('P1 flexible layout sections', await p.locator('.cfs-out-flexible section.cfs-out-layout-hero').count() === 1 && await p.locator('.cfs-out-flexible section.cfs-out-layout-quote').count() === 1);
    }
    if (n === 2) {
      t('P2 flexible: hero, cards, quote, hero', JSON.stringify(await p.locator('.cfs-out-flexible > section').evaluateAll(a => a.map(s => s.className.match(/cfs-out-layout-(\w+)/)[1]))) === '["hero","cards","quote","hero"]');
      t('P2 nested cards rendered', text.includes('Card one') && text.includes('Card two'));
      t('P2 nested repeater inside repeater rows', text.includes('Nested 1a') && text.includes('Nested 2b'));
      t('P2 unchecked true/false shows No', /No/.test(text));
    }
    if (n === 3) {
      t('P3 special characters displayed safely (no raw tags)', !html.includes('<script>alert') && !html.includes('javascript:alert'));
      t('P3 text truncated to 20 chars and escaped', text.includes("Tom & Jerry's"));
      t('P3 flexible shows 4 sections only', await p.locator('.cfs-out-flexible > section').count() === 4, await p.locator('.cfs-out-flexible > section').count());
      t('P3 block repeater shows 2 rows only', text.includes('Block 2') && !text.includes('Block 3'));
    }
  }
  // options shortcodes in a post body
  await p.goto(`http://127.0.0.1:8099/wp-login.php`);
  t('JS errors on front end', errs.length === 0, errs);
  await b.close();
  console.log(ok+' passed, '+bad+' failed');
})().catch(e=>{console.error(e);process.exit(1);});
