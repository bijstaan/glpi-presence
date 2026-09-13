// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Two technicians, one ticket: verify the glpipresence bar sees them both,
// reports typing, and honours a soft claim.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { fullPage } = require('./shot');
const { openDark, audit } = require('./dark');

const BASE = 'http://localhost:8081';
const TICKET = process.env.TICKET_ID || '47';
const SHOTS = process.env.SHOT_DIR || '.';
const DARK_SHOTS = path.join(SHOTS, 'dark');

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  if (await page.locator('#login_name').count()) {
    throw new Error(`login failed for ${user}`);
  }
  return { ctx, page };
}

const barText = (page) =>
  page.evaluate(() => {
    const el = document.querySelector('.glpipresence-bar');
    if (!el) return '<no bar>';
    if (el.classList.contains('is-hidden')) return '<hidden>';
    return el.innerText.replace(/\s+/g, ' ').trim();
  });

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`);
  if (!cond) fail.push(name);
}

(async () => {
  const browser = await chromium.launch();
  const a = await login(browser, 'glpi', 'glpi');
  const b = await login(browser, 'tech', 'tech');

  const open = async (p) => {
    await p.goto(`${BASE}/front/ticket.form.php?id=${TICKET}`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1200);
  };

  // --- 1. Alone -------------------------------------------------------
  await open(a.page);
  await a.page.waitForTimeout(1500);
  const alone = await barText(a.page);
  check('bar renders when alone (claim affordance only)', !alone.includes('<no bar>'), alone);

  // --- 2. Second technician arrives -----------------------------------
  await open(b.page);
  await b.page.waitForTimeout(1000);
  // Force a fresh poll on A rather than waiting out the full interval.
  await a.page.reload({ waitUntil: 'networkidle' });
  await a.page.waitForTimeout(2000);

  const aSeesB = await barText(a.page);
  check('A sees B present', /tech|viewing/i.test(aSeesB), aSeesB);

  const bSeesA = await barText(b.page);
  check('B sees A present', /glpi|viewing/i.test(bSeesA), bSeesA);

  await a.page.screenshot({ path: `${SHOTS}/presence-01-both-viewing.png` });

  // --- 3. B types a reply ---------------------------------------------
  // Expand the followup composer, then type into whatever input it exposes.
  const opened = await b.page.evaluate(() => {
    const btn = document.querySelector('[data-bs-target="#new-ITILFollowup-block"], .timeline-buttons button, #new-ITILFollowup-block');
    if (btn && btn.click) { btn.click(); return true; }
    return false;
  });
  await b.page.waitForTimeout(1500);

  // Keep typing for a while, the way a person actually does, rather than
  // firing one event and hoping A's poll lands on it.
  const typed = await b.page.evaluate(() => {
    const send = () => {
      const ed = window.tinymce && window.tinymce.editors && window.tinymce.editors[0];
      if (ed) {
        ed.focus();
        ed.setContent('<p>looking at this now ' + Date.now() + '</p>');
        ed.fire('input');
        return 'tinymce';
      }
      const ta = document.querySelector('#new-itilobject-form textarea, #new-itilobject-form input[type=text]');
      if (ta) {
        ta.focus();
        ta.value = 'looking at this now ' + Date.now();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        return 'textarea';
      }
      return null;
    };
    const via = send();
    window.__typer = window.setInterval(send, 2000);
    return via;
  });
  check('composer reachable for typing', typed !== null, `opened=${opened} via=${typed}`);

  // A polls every heartbeat_focused (8s); allow one full interval plus margin.
  await a.page.waitForTimeout(11000);
  const aSeesTyping = await barText(a.page);
  check('A sees B typing', /typing|writing/i.test(aSeesTyping), aSeesTyping);
  await a.page.screenshot({ path: `${SHOTS}/presence-02-typing.png` });

  await b.page.evaluate(() => window.clearInterval(window.__typer));

  // --- 4. B claims the ticket -----------------------------------------
  const claimed = await b.page.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('.glpipresence-actions button'));
    const target = btns.find((x) => /working this/i.test(x.textContent));
    if (target) { target.click(); return target.textContent.trim(); }
    return null;
  });
  check('claim button present for B', claimed !== null, String(claimed));

  // The claim only reaches A on A's next poll.
  await a.page.waitForTimeout(11000);
  const aSeesClaim = await barText(a.page);
  check('A sees B\'s claim', /tech is working this/i.test(aSeesClaim), aSeesClaim);
  check('A is offered take-over', /take over/i.test(aSeesClaim), aSeesClaim);
  await a.page.screenshot({ path: `${SHOTS}/presence-03-claimed.png` });

  // --- 5. A takes over -------------------------------------------------
  const tookOver = await a.page.evaluate(() => {
    // Stub the confirmation rather than driving a real dialog: Playwright's
    // dialog handling races page teardown at the end of this script.
    window.confirm = () => true;
    const btn = Array.from(document.querySelectorAll('.glpipresence-actions button'))
      .find((x) => /take over/i.test(x.textContent));
    if (btn) { btn.click(); return true; }
    return false;
  });
  await a.page.waitForTimeout(2500);
  const afterTake = await barText(a.page);
  check('take-over succeeds', tookOver && /you is working this/i.test(afterTake), afterTake);
  await a.page.screenshot({ path: `${SHOTS}/presence-04-takeover.png` });

  // --- 6. Departure ----------------------------------------------------
  // Close the *page* with runBeforeUnload so pagehide actually fires — a bare
  // context.close() tears the browser down and kills the in-flight keepalive
  // request, which looks like a plugin bug but is only a teardown artifact.
  // B typed into the composer, so GLPI's unsaved-changes guard raises a
  // beforeunload dialog on close; it has to be accepted or the close hangs.
  b.page.on('dialog', (d) => d.accept().catch(() => {}));
  await b.page.close({ runBeforeUnload: true });
  await a.page.waitForTimeout(1000);
  await b.ctx.close().catch(() => {});
  await a.page.waitForTimeout(11000);
  const afterLeave = await barText(a.page);
  check('B disappears after closing the tab', !/tech is (viewing|writing)/i.test(afterLeave), afterLeave);
  // The claim is A's now and must survive B leaving — it expires on idleness,
  // not on disconnect.
  check('A keeps the claim after B leaves', /you is working this/i.test(afterLeave), afterLeave);


  // --- The dark palette --------------------------------------------------
  //
  // Presence paints chips and a typing indicator over core's timeline, in
  // colours of its own — the one place a dark body is most likely to leave
  // them unreadable.
  fs.mkdirSync(DARK_SHOTS, { recursive: true });
  console.log('\nswitching to the dark palette...');

  const dark = await openDark(browser, { plugin: 'glpipresence' });

  await dark.goto(`${BASE}/plugins/glpipresence/front/config.php`, { waitUntil: 'networkidle' });
  await dark.waitForTimeout(400);
  let bad = await audit(dark, 'glpipresence-');
  check('[dark] settings: no near-white panel carrying dark-body text',
    bad.whiteBg.length === 0, JSON.stringify(bad.whiteBg));
  check('[dark] settings: muted text meets 4.5:1',
    bad.lowContrast.length === 0, JSON.stringify(bad.lowContrast));
  await fullPage(dark, `${DARK_SHOTS}/presence-dark-01-settings.png`);

  check('[dark] no page errors', dark.__darkErrors.length === 0, dark.__darkErrors.join(' | '));

  await browser.close();
  console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(', ')}` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });
