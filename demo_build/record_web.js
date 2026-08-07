const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://localhost/ZenX/school';
const OUT = 'clips';
fs.mkdirSync(OUT, { recursive: true });
const log = (m) => { console.log(m); fs.appendFileSync('record.log', m + '\n'); };

async function login(browser, url, id, pw) {
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const p = await ctx.newPage();
  await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await p.fill('input[name="admin_id"]', id);
  await p.fill('input[name="password"]', pw);
  const btn = await p.$('#saSubmitBtn, button[type="submit"], input[type="submit"], .sa-btn, .btn-primary');
  if (btn) { await btn.click(); } else { await p.press('input[name="password"]', 'Enter'); }
  await p.waitForFunction(() => !location.pathname.endsWith('/login') && !location.pathname.endsWith('/admin_login'), { timeout: 25000 }).catch(() => {});
  await p.waitForTimeout(3000);
  log('  login ' + id + ' -> ' + p.url());
  const state = await ctx.storageState();
  await ctx.close();
  return state;
}

async function rec(browser, state, name, fn) {
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 }, storageState: state, recordVideo: { dir: OUT, size: { width: 1920, height: 1080 } } });
  const p = await ctx.newPage();
  const vid = p.video();
  try { await fn(p); } catch (e) { log('  ' + name + ' ERR ' + e.message); }
  await ctx.close();
  try { const vp = await vid.path(); fs.renameSync(vp, OUT + '/' + name + '.webm'); log('recorded ' + name); }
  catch (e) { log('  ' + name + ' video save ERR ' + e.message); }
}

// visit: navigate (domcontentloaded, never networkidle — pages poll), then HOLD
// dataWait ms for AJAX data to populate before scrolling, so footage shows real data.
const visit = async (p, url, dataWait = 6000) => {
  await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  await p.waitForTimeout(dataWait);
};
const scroll = async (p, steps = 6, dy = 200, wait = 950) => { for (let i = 0; i < steps; i++) { await p.mouse.wheel(0, dy); await p.waitForTimeout(wait); } await p.mouse.wheel(0, -dy * steps); await p.waitForTimeout(800); };

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const su = await login(browser, B + '/superadmin/login', 'SUP0001', 'Admin@1234');
    await rec(browser, su, '01_dashboard', async p => { await visit(p, B + '/superadmin/dashboard', 7000); await scroll(p, 7); });
    await rec(browser, su, '03_schools',   async p => { await visit(p, B + '/superadmin/schools', 6000); await scroll(p, 5); });
    await rec(browser, su, '04_plans',     async p => { await visit(p, B + '/superadmin/plans', 6000); await scroll(p, 5); });
    await rec(browser, su, '05_analytics', async p => { await visit(p, B + '/superadmin/dashboard/statistics', 6000); await scroll(p, 5); await visit(p, B + '/superadmin/monitor', 6000); await scroll(p, 4); });

    const ad = await login(browser, B + '/admin_login', 'SSA0001', 'Admin@123');
    await rec(browser, ad, '06_erp',           async p => { await visit(p, B + '/admin', 7000); await scroll(p, 7); });
    await rec(browser, ad, '07_students',      async p => { await visit(p, B + '/sis/students', 7000); await scroll(p, 6); });
    await rec(browser, ad, '08_attendance',    async p => { await visit(p, B + '/attendance/student', 7000); await scroll(p, 5); });
    await rec(browser, ad, '09_exams',         async p => { await visit(p, B + '/examination', 6000); await scroll(p, 4); await visit(p, B + '/examination/tabulation', 6000); await scroll(p, 4); });
    await rec(browser, ad, '12_communication', async p => { await visit(p, B + '/communication/notices', 6000); await scroll(p, 4); await visit(p, B + '/communication/messages', 6000); });
    await rec(browser, ad, '13_architecture',  async p => { await visit(p, B + '/sis/students', 7000); await scroll(p, 4); });
    log('DONE_WEB');
  } catch (e) { log('FATAL ' + e.message); } finally { await browser.close(); }
})();
