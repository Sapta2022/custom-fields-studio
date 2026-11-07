const { chromium } = require('playwright-core');
exports.launch = async (h=1800) => {
  const b = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args:['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1300, height: h } });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', e => errs.push('pageerror: ' + e.message));
  p.on('console', m => { if (m.type()==='error') errs.push('console: ' + m.text()); });
  return { b, p, errs };
};
exports.login = async (p) => {
  await p.goto('http://127.0.0.1:8099/wp-login.php');
  await p.fill('#user_login',process.env.WP_USER || 'admin'); await p.fill('#user_pass',process.env.WP_PASS || 'admin123');
  await p.click('#wp-submit'); await p.waitForURL(/wp-admin/);
};
