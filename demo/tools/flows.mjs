// Behaviour of the interface that screenshots cannot show: the results appear in place while typing, the field's clear button and
// the "/" shortcut, the suggestion panel with the keyboard, a facet, the Speed view. Run like shots.mjs (see there); exits 1 on a failure.
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://host.docker.internal:8099';
const browser = await chromium.launch();
const results = [];
const check = (name, ok, detail = '') => {
    results.push(ok);
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : ': ' + detail}`);
};

// php -S serves one connection at a time and Chromium opens speculative sockets, which can stall a load for a while: retry once.
const go = async (page, url) => {
    try {
        await page.goto(url, { waitUntil: 'load', timeout: 10000 });
    } catch {
        await page.goto(url, { waitUntil: 'load', timeout: 20000 });
    }
};

const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
const page = await context.newPage();
page.setDefaultTimeout(8000);
await go(page, BASE + '/');
await page.evaluate(() => { window.__same = true; });

// 1. results in place while typing
await page.click('input[type=search]');
await page.keyboard.type('mouse', { delay: 50 });
await page.waitForSelector('#results .hit-row');
check('results appear while typing', (await page.locator('#results .hit-row').count()) > 0);
check('without a navigation', await page.evaluate(() => window.__same === true));
check('the address follows', page.url().includes('q=mouse'), page.url());
check('the speed of the search is shown', (await page.locator('#results .stat-value').count()) === 1);

// 2. the clear button
await page.click('.search-field .clear');
await page.waitForFunction(() => document.querySelectorAll('#results .hit-row').length === 0);
check('clear empties the field and the results', (await page.inputValue('input[type=search]')) === '' && (await page.locator('#results .hit-row').count()) === 0);
check('clear leaves the cursor in the field', await page.evaluate(() => document.activeElement?.matches('input[type=search]') === true));

// 3. the "/" shortcut
await page.evaluate(() => document.activeElement?.blur());
await page.keyboard.press('/');
check('"/" focuses the field', await page.evaluate(() => document.activeElement?.matches('input[type=search]') === true));

// 4. the suggestion panel with the keyboard
await page.keyboard.type('wireless mou', { delay: 50 });
await page.waitForSelector('.suggest-panel:not([hidden]) .suggest-item');
const items = await page.locator('.suggest-panel .suggest-item').count();
check('the panel offers completions', items > 0, String(items));
await page.keyboard.press('ArrowDown');
check('ArrowDown marks the first item', await page.evaluate(() => document.querySelector('.suggest-item.is-active') !== null));
await page.keyboard.press('Escape');
check('Escape closes the panel', await page.evaluate(() => document.querySelector('.suggest-panel')?.hidden === true));

// 5. a facet narrows the search
await page.fill('input[type=search]', 'mouse');
await page.waitForSelector('#results .facet a');
await page.click('#results .facet a >> nth=0');
await page.waitForLoadState('load');
check('a facet narrows the search', /(cat|stock)=/.test(page.url()), page.url());
check('and shows the chosen filter as a token', (await page.locator('.token').count()) > 0);

// 6. the Speed view
await go(page, BASE + '/?q=mouse');
await page.click('.segmented a >> text=Speed');
await page.waitForSelector('.speed-bars');
check('Speed shows the bars', (await page.locator('.speed-row').count()) >= 2);

// 7. waiting on the server looks the same everywhere: the field shows a spinner and the region dims (aria-busy is the signal)
const slow = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
const p3 = await slow.newPage();
p3.setDefaultTimeout(30000);
await p3.route('**/*', async (route) => {
    const r = route.request();
    if (r.headers()['x-requested-with'] === 'fetch' || r.url().includes('/_components/')) {
        await new Promise((resolve) => setTimeout(resolve, 1200));
    }
    await route.continue();
});
const spinnerShown = (page) => page.evaluate(() => {
    const el = document.querySelector('.field-spinner');
    return el !== null && parseFloat(getComputedStyle(el).opacity) > 0.5;
});
const pillSays = (page, text) => page.evaluate((t) => { const el = document.querySelector('.nav-status'); return parseFloat(getComputedStyle(el).opacity) > 0.5 && el.textContent.includes(t); }, text);
const barShown = (page) => page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.topbar')).opacity) > 0.5);
await go(p3, BASE + '/?q=mouse');
await p3.fill('input[type=search]', 'mouse pad');
await p3.waitForSelector('#results[aria-busy="true"]');
await p3.waitForTimeout(500);
check('home: while the search runs the field shows a spinner', await spinnerShown(p3));
check('home: and the bar runs along the top', await barShown(p3));
check('home: and the page dims, the field included', await p3.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.hero h1')).opacity) < 0.9 && parseFloat(getComputedStyle(document.querySelector('.search-field')).opacity) < 0.9));
check('home: and the status pill says what is going on', await pillSays(p3, 'Searching for mouse pad'));
check('home: and the results dim', await p3.evaluate(() => parseFloat(getComputedStyle(document.querySelector('#results')).opacity) < 0.9));
await p3.waitForSelector('#results:not([aria-busy="true"])');
await p3.waitForTimeout(500);
check('home: afterwards both are gone', !(await spinnerShown(p3)) && (await p3.evaluate(() => parseFloat(getComputedStyle(document.querySelector('#results')).opacity) > 0.95)));

await go(p3, BASE + '/tune?query=mouse');
await p3.waitForSelector('.layout');
await p3.fill('.pane input[type=search]', 'mouse pad');
await p3.waitForSelector('.layout[aria-busy="true"]');
await p3.waitForTimeout(600);
check('tune: while it updates the field shows a spinner', await spinnerShown(p3));
check('tune: and the bar runs along the top', await barShown(p3));
check('tune: and the status pill is shown', await pillSays(p3, 'Updating'));
check('tune: and the settings dim', await p3.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.settings')).opacity) < 0.9));
check('tune: and the results dim', await p3.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.panels')).opacity) < 0.9));
await p3.waitForSelector('.layout:not([aria-busy="true"])');

// 8. a navigation: the top bar runs and the page dims. Playwright cannot evaluate while a navigation is pending, so the page
// records what it looked like in sessionStorage.
await p3.addInitScript(() => {
    new MutationObserver(() => {
        if (document.documentElement.hasAttribute('data-navigating')) {
            setTimeout(() => { sessionStorage.setItem('nav', getComputedStyle(document.querySelector('.topbar')).opacity); sessionStorage.setItem('after', getComputedStyle(document.body, '::after').animationName); }, 400);
        }
    }).observe(document, { attributes: true, subtree: true, attributeFilter: ['data-navigating'] });
});
await go(p3, BASE + '/');
await p3.route('**/relevance*', async (route) => { await new Promise((resolve) => setTimeout(resolve, 1500)); await route.continue(); });
await p3.click('.nav a >> text=Relevance');
await p3.waitForURL('**/relevance*');
const seen = (await p3.evaluate(() => sessionStorage.getItem('nav'))) ?? '';
check('a link: the page is marked as navigating and the top bar is visible', parseFloat(seen) > 0.5, seen);

// leaving a page without a search field (the wizard) shows a spinner of its own
await p3.unrouteAll();
await go(p3, BASE + '/under-the-hood/wizard');
await p3.route('**/relevance*', async (route) => { await new Promise((resolve) => setTimeout(resolve, 1500)); await route.continue(); });
await p3.click('.nav a >> text=Relevance');
await p3.waitForURL('**/relevance*');
check('a page without a field shows a spinner of its own while it loads', (await p3.evaluate(() => sessionStorage.getItem('after'))) === 'spin');

// 9. the Under the hood menu lists the benchmark, the synonyms, the analytics and the security page
await p3.unrouteAll();
await go(p3, BASE + '/');
await p3.click('.nav .menu summary');
const labels = (await p3.locator('.menu-panel a').allTextContents()).map((t) => t.trim());
check('the menu lists wizard, benchmark, doctor, observability, synonyms, analytics, security', ['Config wizard', 'Benchmark', 'Doctor', 'Observability', 'Synonyms', 'Analytics', 'Security'].every((l) => labels.includes(l)), labels.join(', '));

// the search log: a search submitted with Enter (a full page load, the settled query) shows up on the Analytics page, the requests of
// the live search while typing do not. The words are unique to this run (the page lists the top 15 of the last seven days, and an
// earlier run's rows must not satisfy the check): "mouse" plus a random suffix, which the search tolerates (it still finds mice), and
// a nonsense word that finds nothing. Each is searched three times so it outranks the single searches of other visitors on a
// shared demo database (the tables sort by the number of searches). The log is written after the response: poll.
const suffix = Math.random().toString(36).replace(/[^a-z0-9]/g, '').slice(2, 6).padEnd(4, 'x');
const found = `mouse${suffix}`;
const nothing = `qzxv${suffix}9`;
await go(page, BASE + '/');
for (const q of [found, nothing]) {
    for (let i = 0; i < 3; i++) {
        await page.fill('input[name=q]', '');
        await page.type('input[name=q]', q, { delay: 40 });
        await page.waitForSelector('#results');
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.press('input[name=q]', 'Enter')]);
        await page.waitForSelector('#results');
    }
}
let top = [];
let zero = [];
for (let attempt = 0; attempt < 6; attempt++) {
    await go(page, BASE + '/under-the-hood/analytics');
    const tables = page.locator('.table-card');
    top = (await tables.nth(0).locator('td code').allTextContents()).map((t) => t.trim());
    zero = (await tables.nth(1).locator('td code').allTextContents()).map((t) => t.trim());
    if (top.includes(found) && zero.includes(nothing)) break;
    await page.waitForTimeout(500);
}
const typedOnTheWay = [6, 7, 8].map((n) => found.slice(0, n)).concat([4, 5, 6, 7].map((n) => nothing.slice(0, n)), [nothing.slice(0, 8)]); // the fragments the live search saw, unique to this run
check('the analytics page lists the exact word that was just searched among the most searched', top.includes(found), `${found} in ${top.join(', ')}`);
check('the analytics page lists the nonsense word among the searches that found nothing', zero.includes(nothing), `${nothing} in ${zero.join(', ')}`);
check('the word that found something is not in the zero-result table', !zero.includes(found), zero.join(', '));
check('the analytics page does not list what was typed on the way', !top.concat(zero).some((t) => typedOnTheWay.includes(t)), top.concat(zero).join(', '));
check('there is no "/ to search" hint in the toolbar', (await p3.locator('.toolbar-hint').count()) === 0);

// the SQL of the Tune page wraps instead of scrolling sideways
await go(page, BASE + '/tune?query=mouse');
await page.click('label[for=view-sql]');
await page.waitForSelector('.panel-sql pre');
check('the SQL wraps instead of scrolling sideways', await page.evaluate(() => [...document.querySelectorAll('.panel-sql pre')].every((pre) => pre.scrollWidth <= pre.clientWidth + 1)));

// both stock chips of Tune narrow the search
await go(page, BASE + '/tune?query=mouse');
await page.waitForSelector('.facets .chip');
const chipCount = async (name) => Number((await page.locator('.facets .chip', { hasText: name }).first().innerText()).replace(/\D+/g, ' ').trim().split(' ').pop());
const before = await chipCount('out of stock');
await page.click('.facets .chip >> text=out of stock');
await page.waitForSelector('.facets .chip[aria-pressed=true]');
check('the out of stock chip is a button that narrows the search', (await page.locator('.facets .chip[aria-pressed=true]').innerText()).startsWith('out of stock') && before > 0);

// 10. a phone: the tab bar is at the bottom of the screen
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
const p2 = await phone.newPage();
await go(p2, BASE + '/');
const box = await p2.locator('nav.nav').boundingBox();
check('on a phone the navigation is a tab bar at the bottom', box !== null && box.y > 700, JSON.stringify(box));
await p2.click('.nav .menu summary');
check('the menu opens upwards from the tab bar', (await p2.locator('.menu-panel a').first().boundingBox())?.y < box.y);

// 11. the Security page (docs/security.md): who searches decides what a search may see. A fresh browser context, so the viewer chosen on the
// page (kept in the session) is its own. reset=1 forgets the viewer's searches of this minute (demo only), so a run within a minute of
// the last one is not refused by the rate limit; the rate-limit check comes last, because it spends Ada's five searches.
const secContext = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
const sec = await secContext.newPage();
sec.setDefaultTimeout(8000);
const viewing = (q, as, extra = '') => `${BASE}/under-the-hood/security?as=${as}&q=${q}${extra}`;
const sqlOf = async (p) => (await p.locator('.disclosure pre').allTextContents()).join(' ');

await go(sec, viewing('salary', 'hana', '&reset=1'));
check('security: HR finds notes by a word that is only in the hidden field', (await sec.locator('.hit-row').count()) > 0);
check('security: and the highlight shows the confidential text', /salary/i.test(await sec.locator('.hit-row .row-sub:has-text("Confidential") mark').first().innerText()));

await go(sec, viewing('salary', 'ada', '&reset=1'));
check('security: an ordinary viewer finds nothing with that word', (await sec.locator('.hit-row').count()) === 0 && (await sec.locator('text=Nothing found').count()) === 1);
const adaSql = await sqlOf(sec);
check('security: and the SQL she ran has no column of the hidden field', adaSql.includes('t_title') && !adaSql.includes('confidential'), adaSql.slice(0, 200));

const flagsOf = async (p) => (await p.locator('.hit-row .flag').allTextContents()).map((t) => t.trim());
await go(sec, viewing('budget', 'ada'));
const acme = await flagsOf(sec);
check('security: a viewer of Acme sees the notes of Acme only', acme.length > 0 && acme.every((c) => c === 'Acme'), acme.join(', '));
await go(sec, viewing('budget', 'bob'));
const globex = await flagsOf(sec);
check('security: a viewer of Globex sees the notes of Globex only', globex.length > 0 && globex.every((c) => c === 'Globex'), globex.join(', '));

await go(sec, viewing('budget', 'hana'));
await go(sec, `${BASE}/under-the-hood/security?q=menu`);
check('security: the chosen viewer is kept in the session', (await sec.locator('.segmented [aria-current]').innerText()).startsWith('Hana'));

await go(sec, viewing('budget', 'anonymous'));
check('security: an anonymous visitor is refused with an explanation, not an error page', /Refused/.test(await sec.locator('.callout.is-bad').innerText()) && (await sec.locator('.hit-row').count()) === 0);
check('security: the anonymous refusal explains that the tenant check comes before the authorization', /tenant check comes before the authorization/.test(await sec.locator('.callout.is-bad').innerText()));
await go(sec, viewing('budget', 'eve'));
const denied = await sec.locator('.callout.is-bad').innerText().catch(() => '');
check('security: a signed-in guest without ROLE_USER gets the AccessDenied card and no result list', /not granted to this actor: Eve \(guest\) has no ROLE_USER/.test(denied) && denied.includes('AccessDenied') && (await sec.locator('.hit-row').count()) === 0, denied);
await go(sec, viewing('budget', 'anonymous'));
const homeResponse = await sec.goto(`${BASE}/?q=mouse`, { waitUntil: 'load' });
await sec.waitForSelector('#results .hit-row');
check('security: the other pages never ask for a viewer: the home page works for an anonymous visitor', homeResponse?.status() === 200 && (await sec.locator('#results .hit-row').count()) > 0);

await go(sec, viewing('menu', 'ada', '&reset=1'));
for (let i = 2; i <= 5; i++) await go(sec, viewing('menu', 'ada'));
check('security: five searches in a minute are answered', (await sec.locator('.hit-row').count()) > 0 && (await sec.locator('.callout.is-warn').count()) === 0);
await go(sec, viewing('menu', 'ada'));
const refused = await sec.locator('.callout.is-warn').innerText().catch(() => '');
check('security: the sixth is refused with the time to wait', /Too many searches: try again in \d+ s/.test(refused) && (await sec.locator('.hit-row').count()) === 0, refused);
await go(sec, viewing('', 'ada', '&reset=1')); // leave Ada's count at zero for whoever looks next
await secContext.close();

await browser.close();
const failed = results.filter((ok) => !ok).length;
console.log(failed === 0 ? `all ${results.length} checks passed` : `${failed} of ${results.length} checks failed`);
process.exit(failed === 0 ? 0 : 1);
