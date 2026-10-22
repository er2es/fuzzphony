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

// the search log: a search typed and left alone (no Enter: the live search repeats it silently after 1.5 s, and that one is logged)
// shows up on the Analytics page, the requests of the live search while typing do not. The words are unique to this run (the page lists the top 15 of the last seven days, and an
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
        await page.waitForTimeout(2500); // the text settles (1.5 s after the live response) and the silent request is made
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

// an example chip is a deliberate search: it is logged at once, by its own request, also when the visitor is on the Analytics page
// before the settle time has passed. The chips are given words unique to this run (the log is shared); three rounds of three chips,
// each click less than a second after the last, then Analytics straight away. A word typed and then left by navigation (no time to
// settle) is logged too: the pending settle is sent when the page is hidden.
const chipWords = ['a', 'b', 'c'].map((l) => `chip${l}${suffix}`);
const leftWord = `leftw${suffix}`;
await go(page, BASE + '/');
await page.evaluate((words) => document.querySelectorAll('.examples-row a.chip').forEach((chip, i) => {
    if (i < words.length) {
        chip.setAttribute('data-live-search-q-param', words[i]);
    }
}), chipWords);
for (let round = 0; round < 3; round++) {
    const started = Date.now();
    for (let i = 0; i < 3; i++) {
        await page.locator('.examples-row a.chip').nth(i).click();
    }
    if (round === 0) {
        check('three example chips are clicked less than a second apart', Date.now() - started < 1000, `${Date.now() - started} ms`);
    }
}
await go(page, BASE + '/under-the-hood/analytics'); // at once: nothing has had 1.5 s to settle
for (let round = 0; round < 3; round++) {
    await go(page, BASE + '/');
    await page.fill('input[name=q]', '');
    await page.type('input[name=q]', leftWord, { delay: 40 });
    await page.waitForSelector('#results');
    await go(page, BASE + '/under-the-hood/analytics'); // left before the settle time: the pending settle goes out as the page hides
}
let logged = [];
for (let attempt = 0; attempt < 8; attempt++) {
    await go(page, BASE + '/under-the-hood/analytics');
    const tables = page.locator('.table-card');
    logged = [];
    for (let t = 0; t < (await tables.count()); t++) {
        logged = logged.concat((await tables.nth(t).locator('td code').allTextContents()).map((x) => x.trim()));
    }
    if (chipWords.every((w) => logged.includes(w)) && logged.includes(leftWord)) break;
    await page.waitForTimeout(500);
}
check('each example chip clicked in quick succession was logged', chipWords.every((w) => logged.includes(w)), `${chipWords.join(', ')} in ${logged.join(', ')}`);
check('a word typed and left at once by navigation was logged', logged.includes(leftWord), `${leftWord} in ${logged.join(', ')}`);
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
// page (kept in the session) is its own. The limit is 5 searches in 10 seconds; reset=1 forgets the viewer's searches of the window
// (demo only), so a re-run is never refused by the previous one. The rate-limit checks come last: they spend Ada's five searches, and
// the six searches are made in one go (the first four without rendering the page) so they fit in the window.
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
for (let i = 2; i <= 4; i++) await sec.request.get(viewing('menu', 'ada'));
await go(sec, viewing('menu', 'ada'));
check('security: five searches in 10 seconds are answered', (await sec.locator('.hit-row').count()) > 0 && (await sec.locator('.callout.is-warn').count()) === 0);
await go(sec, viewing('menu', 'ada'));
const refused = await sec.locator('.callout.is-warn').innerText().catch(() => '');
check('security: the sixth is refused with the time to wait', /Too many searches: try again in\s+(\d+ seconds\s+)?\d+ s/.test(refused) && (await sec.locator('.hit-row').count()) === 0, refused);
// the wait counts down in the browser, and the card offers the reset next to it
const seconds = async () => Number((await sec.locator('[data-countdown-target=seconds]').innerText()).replace(/\D+/g, ''));
const first = await seconds();
const resetShown = await sec.locator('.callout.is-warn .button.primary.small', { hasText: 'Reset the count' }).isVisible();
await sec.waitForTimeout(1300);
const later = (await sec.locator('.callout.is-ok').count()) > 0 ? 0 : await seconds();
check('security: the wait counts down in the page, with the reset button next to it', later < first && resetShown, `${first} -> ${later}, reset button ${resetShown}`);
// past the window the card says so and a search works again
await sec.waitForSelector('.callout.is-ok', { timeout: 15000 });
check('security: at zero the card says the visitor can search again', /You can search again/.test(await sec.locator('.callout.is-ok').innerText()) && (await sec.locator('.callout.is-ok .button', { hasText: 'Search again' }).isVisible()));
await sec.click('.callout.is-ok .button >> text=Search again');
await sec.waitForSelector('.hit-row');
check('security: after the window a search is answered again', (await sec.locator('.hit-row').count()) > 0 && (await sec.locator('.callout.is-warn').count()) === 0);
await go(sec, viewing('', 'ada', '&reset=1')); // leave Ada's count at zero for whoever looks next

// 12. who searched what: the Security page's searches are in the search log with the viewer as the user (an answered one once, a refusal as a
// `denied` row), and the Analytics page lists them newest first with the masked client address. The words are unique to this run (the log is shared).
const whoQuery = (word, as) => viewing(word, as);
const adaWord = `adaq${suffix}`;
const anonWord = `anonq${suffix}`;
const eveWord = `eveq${suffix}`;
await go(sec, viewing('', 'ada', '&reset=1'));
await go(sec, whoQuery(adaWord, 'ada'));
await go(sec, whoQuery(anonWord, 'anonymous'));
await go(sec, whoQuery(eveWord, 'eve'));
const listSection = page.locator('section[aria-labelledby=who]');
const listRows = async (qs) => {
    await go(page, `${BASE}/under-the-hood/analytics${qs}`);
    const rows = [];
    for (const tr of await listSection.locator('tbody tr').all()) {
        rows.push((await tr.locator('td').allTextContents()).map((t) => t.trim().replace(/\s+/g, ' ')));
    }
    return rows; // when, who, tenant, index, query, hits, status, ip
};
const rowOf = (rows, word) => rows.filter((r) => r[4] === word);
let allRows = [];
for (let attempt = 0; attempt < 8; attempt++) {
    allRows = await listRows('');
    if ([adaWord, anonWord, eveWord].every((w) => rowOf(allRows, w).length > 0)) break;
    await page.waitForTimeout(500);
}
const adaRow = rowOf(allRows, adaWord)[0] ?? [];
const anonRow = rowOf(allRows, anonWord)[0] ?? [];
const eveRow = rowOf(allRows, eveWord)[0] ?? [];
check('who: a search of the Security page as Ada is listed with her name, her company, the intranet and "answered"', adaRow[1] === 'Ada' && adaRow[2] === 'Acme' && adaRow[3] === 'intranet' && adaRow[6] === 'answered', adaRow.join(' | '));
check('who: the anonymous refusal is a denied row without a user', anonRow[1] === 'anonymous' && anonRow[6] === 'denied', anonRow.join(' | '));
check('who: the refusal of Eve is a denied row with her name', eveRow[1] === 'Eve (guest)' && eveRow[6] === 'denied', eveRow.join(' | '));
check('who: the typed text of an answered search is listed once, not twice', rowOf(allRows, adaWord).length === 1, String(rowOf(allRows, adaWord).length));
const masked = (ip) => /^\d{1,3}\.\d{1,3}\.\d{1,3}\.0$/.test(ip ?? '') || /^[0-9a-f:]+::$/i.test(ip ?? '');
check('who: the client address is masked (IPv4 ends in .0, IPv6 keeps 48 bits)', masked(adaRow[7]) && masked(anonRow[7]), `${adaRow[7]} / ${anonRow[7]}`);
check('who: the newest search is first', allRows.length > 1 && allRows.every((r, i) => i === 0 || allRows[i - 1][0] >= r[0]), allRows.map((r) => r[0]).join(', '));
const adaOnly = await listRows('?who=ada');
check('who: the filter for one viewer shows only that viewer, and her search is there', adaOnly.length > 0 && adaOnly.every((r) => r[1] === 'Ada') && rowOf(adaOnly, adaWord).length === 1, adaOnly.map((r) => r[1]).join(', '));
const anonOnly = await listRows('?who=anonymous');
check('who: the filter for anonymous shows only searches without a user (the home page and the refused ones)', anonOnly.length > 0 && anonOnly.every((r) => r[1] === 'anonymous') && rowOf(anonOnly, anonWord).length === 1 && anonOnly.some((r) => r[3] === 'catalog'), anonOnly.map((r) => `${r[1]}/${r[3]}`).join(', '));
const deniedOnly = await listRows('?status=denied');
check('who: the filter for denied shows the refusals only', deniedOnly.length > 0 && deniedOnly.every((r) => r[6] === 'denied') && rowOf(deniedOnly, eveWord).length === 1 && rowOf(deniedOnly, adaWord).length === 0, deniedOnly.map((r) => r[6]).join(', '));
const intranetOnly = await listRows('?index=intranet');
check('who: the filter for the intranet shows its searches only', intranetOnly.length > 0 && intranetOnly.every((r) => r[3] === 'intranet'), intranetOnly.map((r) => r[3]).join(', '));
check('who: the page says it is personal data, with the retention and the masking', /personal data/i.test(await listSection.innerText()) && /retention_days: 30/.test(await listSection.innerText()) && /ip: masked/.test(await listSection.innerText()));
await go(page, `${BASE}/under-the-hood/analytics?who=bob`);
check('who: the chosen filter is marked for assistive technology and a link is reachable with the keyboard', (await listSection.locator('nav[aria-label=Who] a[aria-current=true]').innerText()) === 'Bob' && (await listSection.locator('nav[aria-label=Who] a').first().evaluate((a) => a.tabIndex >= 0)));
await go(page, BASE + '/under-the-hood/observability');
check('the observability page points to who searched what', (await page.locator('a[href$="/under-the-hood/analytics#who"]').count()) === 1);

// 14. the zero-result rescue: a typo that finds nothing, and has one word of the catalogue exactly one letter away, is answered with the results
// of that word under a bar that says so ("Showing results for ..."), with a link that searches the typed text as it is (exact=1). The home page
// searches as you type, which the library never rescues, so the page searches once more as a finished text when that finds nothing.
// `kpttle` (kettle) and `mpuse` (mouse) are one replaced letter in the middle of a word: typo tolerance does not find them, the rescue does.
const resultsSettled = async (p, fragment) => {
    await p.waitForFunction((f) => location.search.includes(f), fragment);
    await p.waitForSelector('#results:not([aria-busy="true"])');
};
await go(page, BASE + '/');
await page.click('input[type=search]');
await page.keyboard.type('kpttle', { delay: 50 });
await resultsSettled(page, 'q=kpttle');
const bar = page.locator('#results .corrected');
check('rescue: a typo that finds nothing shows the bar with the corrected query', (await bar.count()) === 1 && /Showing results for\s+\S*kettle/.test(await bar.innerText()), (await bar.allInnerTexts()).join('|'));
check('rescue: and the results of the corrected query', (await page.locator('#results .hit-row').count()) > 0);
check('rescue: and no second sentence for the same thing in the warnings', (await page.locator('#results .warnings').count()) === 0);
check('rescue: and no "did you mean" (the suggestion was taken)', (await page.locator('#results .did-you-mean').count()) === 0);
const insteadLink = bar.locator('a');
check('rescue: the link says what it searches, in the typed words', (await insteadLink.innerText()) === 'Search for “kpttle” instead', await insteadLink.innerText());
check('rescue: and it asks for the exact text', ((await insteadLink.getAttribute('href')) ?? '').includes('exact=1'));
check('rescue: the notice is part of the live region the results are announced from', await page.evaluate(() => document.querySelector('#results .corrected')?.closest('[aria-live]')?.id === 'results'));

// typing on to a query that finds something removes the bar (it belongs to the fragment the live search replaces)
await page.fill('input[name=q]', 'kettle');
await resultsSettled(page, 'q=kettle');
check('rescue: a following search that needs no rescue removes the bar', (await page.locator('#results .corrected').count()) === 0 && (await page.locator('#results .hit-row').count()) > 0);
await page.fill('input[name=q]', 'kpttle');
await resultsSettled(page, 'q=kpttle');
check('rescue: and typing the typo again brings it back', (await page.locator('#results .corrected').count()) === 1);

// the link, with the keyboard: the focus and Enter
await page.locator('#results .corrected a').focus();
check('rescue: the link takes the keyboard focus', await page.evaluate(() => document.activeElement?.closest('.corrected') !== null));
await Promise.all([page.waitForURL(/exact=1/), page.keyboard.press('Enter')]);
await page.waitForLoadState('load');
check('rescue: "search instead" keeps the typed text and adds exact=1', page.url().includes('q=kpttle') && page.url().includes('exact=1'), page.url());
check('rescue: and shows no bar and no results', (await page.locator('.corrected').count()) === 0 && (await page.locator('#results .hit-row').count()) === 0);
check('rescue: but says nothing was found for the typed text, with the suggestion', /Nothing found for “kpttle”/.test(await page.locator('#results .empty').innerText()) && (await page.locator('#results .did-you-mean a').count()) === 1);
check('rescue: the Results and Speed links keep the exact text', ((await page.locator('.segmented a >> text=Speed').getAttribute('href')) ?? '').includes('exact=1'));

// a normal query, a typo that typo tolerance finds, and nonsense: no bar
for (const [what, q, hits] of [['a normal query', 'mouse', true], ['a typo that typo tolerance finds (it has results and a suggestion)', 'hedphones', true], ['nonsense', `qzxv${suffix}8`, false]]) {
    await go(page, `${BASE}/?q=${encodeURIComponent(q)}`);
    check(`rescue: ${what} shows no bar`, (await page.locator('.corrected').count()) === 0 && ((await page.locator('#results .hit-row').count()) > 0) === hits);
}

// the visitor's own words are escaped, in the bar and in the "nothing found" line: no element comes out of them
const hostile = 'mpuse<>"\'&';
await go(page, `${BASE}/?q=${encodeURIComponent(hostile)}`);
check('rescue: HTML characters in a rescued query are text in the bar', (await page.locator('.corrected a').innerText()) === `Search for “${hostile}” instead` && (await page.locator('.corrected > *').count()) === 2, (await page.locator('.corrected').allInnerTexts()).join('|'));
const injected = '<img src=x onerror="window.__xss=1"> <script>window.__xss=2</script>';
for (const q of [injected, `mpuse ${injected}`]) {
    await go(page, `${BASE}/?q=${encodeURIComponent(q)}`);
    await page.waitForTimeout(300);
    check('rescue: HTML in the typed text is not an element and does not run', (await page.locator('#results img, #results script').count()) === 0 && (await page.evaluate(() => window.__xss)) === undefined && (await page.locator('#results').innerHTML()).includes('&lt;img'));
}

// the log keeps what was typed, with the hits of the rescued search: the typo is among the most searched, not among the searches that found nothing
for (let i = 0; i < 3; i++) {
    await go(page, BASE + '/?q=mpuse');
}
let rescuedTop = [];
let rescuedZero = [];
let rescuedRows = [];
for (let attempt = 0; attempt < 6; attempt++) {
    await go(page, BASE + '/under-the-hood/analytics');
    const tables = page.locator('.table-card');
    rescuedTop = (await tables.nth(0).locator('td code').allTextContents()).map((t) => t.trim());
    rescuedZero = (await tables.nth(1).locator('td code').allTextContents()).map((t) => t.trim());
    rescuedRows = await listSection.locator('tbody tr').all();
    if (rescuedTop.includes('mpuse')) {
        break;
    }
    await page.waitForTimeout(500);
}
check('rescue: the analytics page lists the rescued typo among the most searched', rescuedTop.includes('mpuse'), rescuedTop.join(', '));
check('rescue: and not among the searches that found nothing', !rescuedZero.includes('mpuse'), rescuedZero.join(', '));
check('rescue: but the typed text searched "instead" (exact) is one that found nothing', rescuedZero.includes('kpttle'), rescuedZero.join(', '));
const rescuedHits = [];
for (const tr of rescuedRows) {
    if ((await tr.locator('td code').innerText()) === 'mpuse') {
        rescuedHits.push((await tr.locator('td.num').innerText()).trim());
    }
}
check('rescue: who searched what lists the typed text with the hits of the rescued search', rescuedHits.length > 0 && rescuedHits.every((h) => h !== '0'), rescuedHits.join(', '));
await go(page, BASE + '/under-the-hood/observability?q=mpuse');
check('rescue: the observability page counts a rescued search', /fuzzphony\.search\.rescued/.test(await page.locator('body').innerText()));
await go(page, BASE + '/relevance?q=mpuse');
check('rescue: the Relevance page shows what each rule finds, not a rescue (the price of a letter replaced in the middle)', /\b0 hits/.test(await page.locator('body').innerText()));

await secContext.close();

await browser.close();
const failed = results.filter((ok) => !ok).length;
console.log(failed === 0 ? `all ${results.length} checks passed` : `${failed} of ${results.length} checks failed`);
process.exit(failed === 0 ? 0 : 1);
