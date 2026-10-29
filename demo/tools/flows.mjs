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

// The Analytics tables list the top 15 of a log that other visitors and earlier runs share, so a word searched three times can be outranked on a used stack.
// `outrank` makes a check independent of that: it searches the word again (a plain request to /?q=, which the page logs like any deliberate search) in rounds of ten
// until the given table of the Analytics page (0 = most searched, 1 = found nothing) lists it, at most 25 rounds.
const outrank = async (pg, word, table) => {
    let words = [];
    for (let round = 0; round < 25; round++) {
        await go(pg, BASE + '/under-the-hood/analytics');
        words = (await pg.locator('.table-card').nth(table).locator('td code').allTextContents()).map((t) => t.trim());
        if (words.includes(word)) {
            return words;
        }
        await Promise.all(Array.from({ length: 10 }, () => pg.request.get(`${BASE}/?q=${encodeURIComponent(word)}`)));
        await pg.waitForTimeout(400); // the log is written after the response
    }
    return words;
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
check('combobox: the field names its popup (aria-controls) and the options have ids', await page.evaluate(() => {
    const input = document.querySelector('input[type=search]');
    const panel = document.getElementById(input.getAttribute('aria-controls'));
    return panel !== null && panel.getAttribute('role') === 'listbox' && [...panel.querySelectorAll('[role=option]')].every((o) => o.id !== '');
}));
check('combobox: the headings are groups named by their heading, inside the listbox', await page.evaluate(() => {
    const groups = [...document.querySelectorAll('.suggest-panel [role=group]')];
    return groups.length > 0 && groups.every((g) => document.getElementById(g.getAttribute('aria-labelledby'))?.textContent !== '' && g.querySelector('[role=option]') !== null);
}));
check('combobox: the home page offers the completions only (the categories are the facets next to the results)', await page.evaluate(() => [...document.querySelectorAll('.suggest-panel .suggest-heading')].map((h) => h.textContent).join() === 'Searches'));
check('combobox: the panel is opaque', await page.evaluate(() => /^rgb\(/.test(getComputedStyle(document.querySelector('.suggest-panel')).backgroundColor)));
await page.keyboard.press('ArrowDown');
check('ArrowDown marks the first item', await page.evaluate(() => document.querySelector('.suggest-item.is-active') !== null));
check('combobox: and the field points at it (aria-activedescendant)', await page.evaluate(() => document.querySelector('input[type=search]').getAttribute('aria-activedescendant') === document.querySelector('.suggest-item.is-active')?.id));
const typedText = await page.inputValue('input[type=search]');
await page.keyboard.press('Escape');
check('Escape closes the panel', await page.evaluate(() => document.querySelector('.suggest-panel')?.hidden === true));
check('combobox: and leaves the text in the field (it does not empty it, which would leave the results of a gone text)', (await page.inputValue('input[type=search]')) === typedText && (await page.evaluate(() => !document.querySelector('input[type=search]').hasAttribute('aria-activedescendant'))));
await page.keyboard.press('Escape');
check('combobox: Escape with the panel closed does not empty the field either', (await page.inputValue('input[type=search]')) === typedText);

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
// the page does not wait for the measuring: this search's own time is there at once while the comparison is still being measured
const measuring = await context.newPage();
await measuring.route('**/speed/ilike*', async (route) => { await new Promise((resolve) => setTimeout(resolve, 3000)); await route.continue(); });
await measuring.goto(BASE + '/?q=mouse&view=speed', { waitUntil: 'load', timeout: 20000 });
check('speed: the page shows the time of this search at once, while the comparison is still being measured', (await measuring.locator('.speed-bars [aria-busy=true] .speed-row .speed-value').first().innerText()).includes(' ms') && (await measuring.locator('.speed-factor').count()) === 0);
await measuring.waitForSelector('.speed-factor', { timeout: 20000 });
check('speed: and then the bars hold the measured medians and the factor', (await measuring.locator('.speed-bars .speed-row').count()) === 2);
await measuring.close();

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
// earlier run's rows must not satisfy the check; `outrank` below makes them top entries on a used stack): "mouse" plus a random suffix, which the search tolerates (it still finds mice), and
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
// a used stack: more searches of the two words than the other entries have
if (!top.includes(found)) top = await outrank(page, found, 0);
if (!zero.includes(nothing)) zero = await outrank(page, nothing, 1);
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

// the library's highlight is markup (<mark>), on every list of products, never visible tags
for (const [path, selector] of [['/?q=hedphones', '#results .hit-row'], ['/relevance?q=mouse', '.hit-row'], ['/languages?lang=fr&q=chevaux', '.hit-row'], ['/tune?query=mouse', '.hit-row']]) {
    await go(page, BASE + path);
    await page.waitForSelector(selector);
    const titles = await page.locator(`${selector} .row-title`).allInnerTexts();
    check(`highlights are markup, not visible tags: ${path}`, (await page.locator(`${selector} mark`).count()) > 0 && !titles.some((t) => /<\/?mark>/.test(t)), titles.slice(0, 2).join(' | '));
}

// the ranking profile `popular` of the configuration is a starting point in Tune: its values land in the sliders
await go(page, `${BASE}/tune?query=mouse`);
await page.click('.profile-chips .chip >> text=popular');
const sliderValue = (label) => page.evaluate((l) => [...document.querySelectorAll('.slider')].find((s) => s.innerText.trim().startsWith(l))?.querySelector('input').value, label);
await page.waitForFunction(() => [...document.querySelectorAll('.slider')].find((s) => s.innerText.trim().startsWith('Recency half-life'))?.querySelector('input').value === '60', null, { timeout: 15000 });
check('tune: the popular profile fills the sliders from the configuration', (await sliderValue('Popularity boost')) === '0.03' && (await sliderValue('Recency half-life')) === '60');

// both stock chips of Tune narrow the search
await go(page, BASE + '/tune?query=mouse');
await page.waitForSelector('.facets .chip');
const chipCount = async (name) => Number((await page.locator('.facets .chip', { hasText: name }).first().innerText()).replace(/\D+/g, ' ').trim().split(' ').pop());
const before = await chipCount('out of stock');
await page.click('.facets .chip >> text=out of stock');
await page.waitForSelector('.facets .chip[aria-pressed=true]');
check('the out of stock chip is a button that narrows the search', (await page.locator('.facets .chip[aria-pressed=true]').innerText()).startsWith('out of stock') && before > 0);

// a failing live search keeps the old results and says so in one quiet line, which goes away with the next answer
await go(page, BASE + '/?q=mouse');
await page.route('**/*', (route) => (route.request().headers()['x-requested-with'] === 'fetch' && !route.request().headers()['x-settled'] ? route.fulfill({ status: 500, body: 'boom' }) : route.continue()));
await page.fill('input[name=q]', 'mouse pad');
await page.waitForSelector('.live-error:not([hidden])');
check('a failed live search keeps the old results and shows a quiet notice', (await page.locator('#results .hit-row').count()) > 0 && /Could not update the results/.test(await page.locator('.live-error').innerText()));
await page.unrouteAll({ behavior: 'ignoreErrors' });
await page.fill('input[name=q]', 'mouse');
await page.waitForSelector('.live-error', { state: 'hidden' });
check('and the notice goes away with the next answer', (await page.locator('#results .hit-row').count()) > 0);

// the error pages are the demo's own: the shell, the navigation and a way back, with the right status
const missing = await page.goto(`${BASE}/no-such-page`, { waitUntil: 'load' });
check('a missing page is a branded 404 with the navigation and a way back', missing?.status() === 404 && (await page.locator('main h1').innerText()) === 'Page not found' && (await page.locator('nav.nav').count()) === 1 && (await page.locator('main a[href="/"]').count()) > 0);

// 10. a phone: the tab bar is at the bottom of the screen
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
const p2 = await phone.newPage();
await go(p2, BASE + '/');
const box = await p2.locator('nav.nav').boundingBox();
check('on a phone the navigation is a tab bar at the bottom', box !== null && box.y > 700, JSON.stringify(box));
const secPhone = await phone.newPage();
await go(secPhone, BASE + '/under-the-hood/security?as=hana&q=menu');
check('security on a phone: a select instead of the row of five buttons', (await secPhone.locator('.viewer-select select').isVisible()) && !(await secPhone.locator('.viewer-links').isVisible()) && (await secPhone.locator('.viewer-select select').inputValue()) === 'hana');
await Promise.all([secPhone.waitForURL(/as=eve/), secPhone.selectOption('.viewer-select select', 'eve')]);
check('and choosing a viewer in it changes the viewer and keeps the search', /q=menu/.test(secPhone.url()) && (await secPhone.locator('.callout.is-bad').count()) === 1);
await secPhone.close();
await p2.click('.nav .menu summary');
check('the menu opens upwards from the tab bar', (await p2.locator('.menu-panel a').first().boundingBox())?.y < box.y);
const menuLink = await p2.locator('.menu-panel a').first().evaluate((a) => ({ size: parseFloat(getComputedStyle(a).fontSize), dir: getComputedStyle(a).flexDirection, panel: getComputedStyle(a.parentElement).backgroundColor, height: a.getBoundingClientRect().height }));
check('on a phone the links of the menu are rows of normal size, not tab-bar captions', menuLink.size >= 15 && menuLink.dir === 'row' && menuLink.height >= 44, JSON.stringify(menuLink));
check('and the panel is opaque', /^rgb\(/.test(menuLink.panel), menuLink.panel);

// 11. the Security page (docs/security.md): who searches decides what a search may see. A fresh browser context, so the viewer chosen on the
// page (kept in the session) is its own. The limit is 5 searches in 10 seconds; reset=1 forgets the viewer's searches of the window
// (demo only), so a re-run is never refused by the previous one. The rate-limit checks come last: they spend Ada's five searches, and
// the six searches are made in one go (the first four without rendering the page) so they fit in the window.
const secContext = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
const sec = await secContext.newPage();
sec.setDefaultTimeout(8000);
const viewing = (q, as, extra = '') => `${BASE}/under-the-hood/security?as=${as}&q=${q}${extra}`;
const sqlOf = async (p) => (await p.locator('.disclosure pre').allTextContents()).join(' ');

// a first visit opens on Ada and an answer, not on a refusal; Anonymous is the last choice; there is one reset button at a time
const fresh = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' })).newPage();
await go(fresh, `${BASE}/under-the-hood/security?q=menu`);
check('security: a first visit is Ada, with an answer and no refusal', (await fresh.locator('.viewer-links [aria-current]').innerText()) === 'Ada' && (await fresh.locator('.callout.is-bad').count()) === 0 && (await fresh.locator('.hit-row').count()) > 0);
check('security: Anonymous is the last choice', (await fresh.locator('.viewer-links a').last().innerText()) === 'Anonymous');
check('security: the reset button is shown once on the page', (await fresh.locator('a.button', { hasText: 'Reset the count' }).count()) === 1);
await fresh.context().close();

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
check('who: a search of the Security page as Ada is listed with her name, her company, the intranet and "found nothing" (it answered, with no hit)', adaRow[1] === 'Ada' && adaRow[2] === 'Acme' && adaRow[3] === 'intranet' && adaRow[6] === 'found nothing', adaRow.join(' | '));
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
// searches as you type, which the library never rescues, so a text that finds nothing is answered with "Searching for a correction..." and the
// browser asks at once for the rescue (no waiting for the typing to settle, no "Nothing found" in between); a word that finds results is never
// rescued while it is typed. `kpttle` (kettle) and `mpuse` (mouse) are one replaced letter in the middle of a word: typo tolerance does not find
// them, the rescue does.
const resultsSettled = async (p, fragment) => {
    await p.waitForFunction((f) => location.search.includes(f), fragment);
    await p.waitForSelector('#results:not([aria-busy="true"])');
};
await go(page, BASE + '/');
await page.click('input[type=search]');
// what the results region said, whenever it changed: "Nothing found for kpttle" must never be among it
await page.evaluate(() => {
    window.__said = [];
    new MutationObserver(() => window.__said.push(document.querySelector('#results .empty')?.innerText ?? '')).observe(document.querySelector('#results'), { childList: true, subtree: true });
});
// the settle request (the one that logs the search, 1.5 s after the last key) is held back for 8 s: the bar must not depend on it
let settleHeld = false;
await page.route('**/*', async (route) => {
    if (route.request().headers()['x-settled'] === '1' && route.request().url().includes('q=kpttle')) {
        settleHeld = true;
        await new Promise((resolve) => setTimeout(resolve, 8000));
        settleHeld = false;
    }
    await route.continue().catch(() => {});
});
await page.keyboard.type('kpttle', { delay: 50 });
const bar = page.locator('#results .corrected');
await page.waitForSelector('#results .corrected', { timeout: 6000 });
check('rescue: a typo that finds nothing shows the bar with the corrected query at once, not after the typing has settled', (await bar.count()) === 1 && /Showing results for\s+\S*kettle/.test(await bar.innerText()), (await bar.allInnerTexts()).join('|'));
await page.waitForTimeout(1800);
check('rescue: (the settle request that logs it was held back meanwhile, so the bar did not wait for it)', settleHeld === true);
await page.unrouteAll({ behavior: 'ignoreErrors' });
check('rescue: and the page never said "Nothing found" for it on the way (it said it was looking for a correction)', !(await page.evaluate(() => window.__said)).some((t) => /Nothing found for “kpttle”/.test(t)) && (await page.evaluate(() => window.__said)).some((t) => /Searching for a correction/.test(t)), (await page.evaluate(() => window.__said)).join(' | '));
check('rescue: and the results of the corrected query', (await page.locator('#results .hit-row').count()) > 0);
check('rescue: and no second sentence for the same thing in the warnings', (await page.locator('#results .warnings').count()) === 0);
check('rescue: and no "did you mean" (the suggestion was taken)', (await page.locator('#results .did-you-mean').count()) === 0);
const insteadLink = bar.locator('a');
check('rescue: the bar names the corrected word, not the expanded query', (await bar.locator('strong').innerText()) === 'kettle', await bar.locator('strong').innerText());
check('a11y: the results region is not a live region; a short status line announces the result instead', (await page.locator('#results[aria-live]').count()) === 0 && (await page.locator('#results').evaluate((el) => el.closest('[aria-live]') === null)) && /kettle/.test(await page.locator('[role=status][data-live-search-target=status]').innerText()) && (await page.locator('[role=status][data-live-search-target=status]').innerText()).length < 120, await page.locator('[role=status][data-live-search-target=status]').textContent());
check('rescue: the link says what it searches, in the typed words', (await insteadLink.innerText()) === 'Search for “kpttle” instead', await insteadLink.innerText());
check('rescue: and it asks for the exact text', ((await insteadLink.getAttribute('href')) ?? '').includes('exact=1'));

// typing on to a query that finds something removes the bar (it belongs to the fragment the live search replaces)
await page.fill('input[name=q]', 'kettle');
await resultsSettled(page, 'q=kettle');
check('rescue: a following search that needs no rescue removes the bar', (await page.locator('#results .corrected').count()) === 0 && (await page.locator('#results .hit-row').count()) > 0);
await page.fill('input[name=q]', 'kpttle');
await page.waitForSelector('#results .corrected', { timeout: 6000 });
check('rescue: typing the typo again brings it back', (await page.locator('#results .corrected').count()) === 1 && (await page.locator('#results .hit-row').count()) > 0);
await page.fill('input[name=q]', 'kettle');
await page.waitForSelector('#results .hit-row');
await page.waitForFunction(() => document.querySelectorAll('#results .corrected').length === 0);
check('rescue: and a search that needs no rescue takes it away again', (await page.locator('#results .corrected').count()) === 0);
// the owner's rule: a word that finds results is never rescued while it is typed, whatever the next letter will make of it
await page.fill('input[name=q]', '');
await page.keyboard.type('wireles mou', { delay: 50 });
await resultsSettled(page, 'q=wireles');
check('rescue: a text that finds results (typo tolerance, a half-typed word) shows no bar while it is typed', (await page.locator('#results .corrected').count()) === 0 && (await page.locator('#results .hit-row').count()) > 0);
// the rescue is not for a text with nothing close: after the request for it, the answer is "Nothing found"
await page.fill('input[name=q]', `qzxv${suffix}7`);
await page.waitForFunction(() => /Nothing found/.test(document.querySelector('#results .empty')?.innerText ?? ''));
check('rescue: a text nothing can rescue ends in "Nothing found", not in "Searching for a correction"', !/Searching for a correction/.test(await page.locator('#results').innerText()));
// Enter is a search the visitor means: a page load, rescued at once
await page.fill('input[name=q]', 'kpttle');
await Promise.all([page.waitForURL(/q=kpttle/), page.keyboard.press('Enter')]);
await page.waitForLoadState('load');
check('rescue: Enter shows the bar at once, with the results', (await page.locator('#results .corrected').count()) === 1 && (await page.locator('#results .hit-row').count()) > 0);

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

// nothing found: one block across the whole results area (not in the narrow facet column), no repeated examples, no "0 products" headline
await go(page, `${BASE}/?q=qzxv${suffix}6`);
const emptyBox = await page.locator('#results .empty').boundingBox();
const resultsBox = await page.locator('#results .results').boundingBox();
check('nothing found: the message spans the results area', emptyBox !== null && resultsBox !== null && emptyBox.width > resultsBox.width * 0.9, JSON.stringify([emptyBox, resultsBox]));
check('nothing found: no copy of the example chips and no "0 products" headline', (await page.locator('#results .empty .chip').count()) === 0 && (await page.locator('#results .stat').count()) === 0);

// a typo that typo tolerance already found says "Including results for ...", not "Did you mean": the results are the suggestion's
await go(page, `${BASE}/?q=hedphones`);
check('a typo that typo tolerance finds says "Including results for headphones", with no question', /^Including results for\s+headphones$/.test((await page.locator('#results .did-you-mean').innerText()).trim()) && (await page.locator('#results .did-you-mean a').count()) === 0);
check('the headline no longer carries the expanded query; the disclosure does', !(await page.locator('#results .stat-caption').innerText()).includes('understood as') && /understood as/.test(await page.locator('#results .disclosure').textContent()));

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
// (on a used stack "mpuse" may already be in the zero-result table from a deliberate exact=1 search: what must not change is its count there)
const zeroCountOf = async (word) => {
    await go(page, BASE + '/under-the-hood/analytics');
    const rows = await page.locator('.table-card').nth(1).locator('tbody tr').all();
    for (const tr of rows) {
        if ((await tr.locator('td code').first().innerText()).trim() === word) {
            return Number((await tr.locator('td').nth(1).innerText()).replace(/\D/g, ''));
        }
    }
    return 0;
};
const zeroBefore = await zeroCountOf('mpuse');
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
if (!rescuedTop.includes('mpuse')) {
    rescuedTop = await outrank(page, 'mpuse', 0);
    await go(page, BASE + '/under-the-hood/analytics');
    rescuedZero = (await page.locator('.table-card').nth(1).locator('td code').allTextContents()).map((t) => t.trim());
    rescuedRows = await listSection.locator('tbody tr').all();
}
check('rescue: the analytics page lists the rescued typo among the most searched', rescuedTop.includes('mpuse'), rescuedTop.join(', '));
const zeroAfter = await zeroCountOf('mpuse');
await go(page, BASE + '/under-the-hood/analytics');
rescuedRows = await listSection.locator('tbody tr').all();
check('rescue: and not among the searches that found nothing (its count there did not grow)', zeroAfter === zeroBefore, `${zeroBefore} -> ${zeroAfter}`);
const rescuedHits = [];
const insteadHits = [];
for (const tr of rescuedRows) {
    const typed = await tr.locator('td code').innerText();
    if (typed === 'mpuse') {
        rescuedHits.push((await tr.locator('td.num').innerText()).trim());
    } else if (typed === 'kpttle') {
        insteadHits.push((await tr.locator('td.num').innerText()).trim());
    }
}
check('rescue: the same text searched "instead" (exact) is logged as the search that found nothing it is, next to the rescued one', insteadHits.includes('0') && insteadHits.some((h) => h !== '0'), insteadHits.join(', '));
check('rescue: who searched what lists the typed text with the hits of the rescued search', rescuedHits.length > 0 && rescuedHits.every((h) => h !== '0'), rescuedHits.join(', '));
await go(page, BASE + '/under-the-hood/observability?q=mpuse');
check('rescue: the observability page counts a rescued search', /fuzzphony\.search\.rescued/.test(await page.locator('body').innerText()));
await go(page, BASE + '/relevance?q=kpttle'); // (not logged: the page runs searches of its own)
check('rescue: the Relevance page shows what each rule finds, not a rescue (the price of a letter replaced in the middle)', /\b0 hits/.test(await page.locator('body').innerText()));
// the Relevance and Synonyms pages run searches of their own (forced typo tolerance, a flat similarity, a suggestion): none is logged
const relWord = `relw${suffix}`;
for (const path of ['/relevance?q=', '/under-the-hood/synonyms?q=']) {
    for (let i = 0; i < 3; i++) {
        await go(page, BASE + path + relWord);
    }
}
await page.waitForTimeout(1500);
check('the Relevance and Synonyms pages log nothing', !(await listRows('')).some((r) => r[4] === relWord));

// a search the visitor means is logged once, with the hits it found: the probe that decided what to answer is not a row of its own, and the
// run that logs a found search happens after the response (kernel.terminate), so the row may come a moment later
const onceWord = `mouse${[...suffix].reverse().join('')}`;
await go(page, `${BASE}/?q=${onceWord}`);
let onceRows = [];
for (let attempt = 0; attempt < 8 && onceRows.length === 0; attempt++) {
    await page.waitForTimeout(500);
    onceRows = rowOf(await listRows(''), onceWord);
}
await page.waitForTimeout(1500);
onceRows = rowOf(await listRows(''), onceWord);
check('a search that found something is logged once, with its hits', onceRows.length === 1 && onceRows[0][5] !== '0', onceRows.map((r) => r.join(' | ')).join(' / '));

// a total at the candidate cap is "2,000+", not "2,000.0" (the table of the most searched) or a bare 2,000 (the list)
await go(page, `${BASE}/?q=wireless`);
await page.waitForTimeout(2000);
await go(page, `${BASE}/under-the-hood/analytics`);
const analyticsText = await page.locator('main').innerText();
check('analytics: a capped total is shown as 2,000+', /2,000\+/.test(analyticsText) && !/2,000\.0|2,000(?!\+)/.test(analyticsText), (analyticsText.match(/.{20}2,000.{10}/g) ?? []).join(' / '));

// small things the accessibility check found
await go(page, `${BASE}/relevance?q=tv`);
const h2s = (await page.locator('main h2').allTextContents()).map((t) => t.trim());
check('relevance: no two headings of level 2 have the same text', new Set(h2s).size === h2s.length, h2s.join(' | '));
await go(page, `${BASE}/tune?query=mouse`);
check('tune: the sliders are 24 px tall at least and the view switch has no frame of its own', (await page.locator('input[type=range]').evaluateAll((els) => els.every((e) => e.getBoundingClientRect().height >= 24))) && (await page.locator('fieldset.segmented').evaluate((f) => getComputedStyle(f).borderTopWidth)) === '0px');
await go(page, `${BASE}/under-the-hood/doctor`);
check('doctor: a skipped check does not wear the disclosure chevron', (await page.locator('.status-dot use[href="#i-chevron"]').count()) === 0);
await go(p2, `${BASE}/under-the-hood/analytics`);
check('analytics on a phone: a table that scrolls sideways can be reached with the keyboard and has a name', await p2.locator('.table-card').evaluateAll((els) => els.every((e) => e.scrollWidth <= e.clientWidth || (e.tabIndex === 0 && e.getAttribute('role') === 'region' && e.hasAttribute('aria-label')))));

// microcopy
await go(page, BASE + '/');
check('home: one line says what Fuzzphony is, with links to the repository and the documentation', (await page.locator('.hero .about a[href*="github.com/er2es/fuzzphony"]').count()) === 2 && /PostgreSQL/.test(await page.locator('.hero .about').innerText()));
await go(page, `${BASE}/under-the-hood/observability`);
const observed = await page.locator('main').innerText();
check('observability: milliseconds with one decimal and counts in plain words', /[\d,]+\.\d ms/.test(observed) && !/\d\.\d{3} ms/.test(observed) && !/item\(s\)/.test(observed), observed.slice(0, 400));
await go(page, `${BASE}/languages?lang=en&q=telly`);
check('languages: the merged list shows ranks, not scores of different indexes', (await page.locator('section[aria-labelledby=all] .row-trailing').first().innerText()).trim() === '#1' && /found through its synonyms/.test(await page.locator('aside[aria-labelledby=analysis]').innerText()));

// 0.9.10: a language switch drops the old query: the chips link to ?lang=xx only, the box is empty and that language's examples show
await go(page, `${BASE}/languages?lang=es&q=canciones`);
check('languages: the chips do not carry the query', (await page.locator('nav[aria-label=Language] a').evaluateAll((as) => as.every((a) => !a.getAttribute('href').includes('q=')))));
await page.locator('nav[aria-label=Language] a[lang=hu]').click();
await page.waitForURL(/lang=hu/);
check('languages: switching the language empties the box, drops q from the address and shows the examples of that language',
    !page.url().includes('q=') && (await page.inputValue('input[type=search]')) === '' && /Try, in Hungarian/.test(await page.locator('main').innerText()) && (await page.locator('#contrast').count()) === 0, page.url());
await go(page, `${BASE}/languages?lang=hu&q=zene`);
check('languages: ?lang=xx&q=... typed or linked directly still searches', (await page.inputValue('input[type=search]')) === 'zene' && (await page.locator('#contrast').count()) === 1);

// 0.9.10: emptying the box takes q out of the address on every page with a search box (a reload shows the empty page, other parameters stay)
await go(page, BASE + '/');
await page.click('input[type=search]');
await page.keyboard.type('mouse', { delay: 40 });
await page.waitForFunction(() => location.search.includes('q=mouse'));
await page.click('.search-field .clear');
await page.waitForFunction(() => !location.search.includes('q='));
await go(page, page.url());
check('home: clearing the box removes q from the address, a reload shows the empty page', !page.url().includes('q=') && (await page.inputValue('input[type=search]')) === '' && (await page.locator('#results .hit-row').count()) === 0, page.url());
await go(page, BASE + '/?q=mouse&cat=Computers');
await page.fill('input[type=search]', '');
await page.waitForFunction(() => !location.search.includes('q='));
check('home: and the filters stay in the address', page.url().includes('cat=Computers'), page.url());
for (const [path, keep] of [['/relevance?q=mouse', null], ['/languages?lang=fr&q=chevaux', 'lang=fr'], ['/under-the-hood/synonyms?q=tv', null], ['/under-the-hood/security?as=anonymous&q=salary', 'as=anonymous']]) {
    await go(page, BASE + path);
    await page.fill('input[type=search]', '');
    await page.waitForFunction(() => !new URLSearchParams(location.search).has('q'));
    await page.waitForLoadState('load');
    check(`${path.split('?')[0]}: clearing the box removes q from the address${keep ? ' and keeps ' + keep : ''}`, !page.url().includes('q=') && (keep === null || page.url().includes(keep)) && (await page.inputValue('input[type=search]')) === '', page.url());
}
// observability always measures something: without q it is the default example, so the box refills but the address has no q
await go(page, `${BASE}/under-the-hood/observability?q=mouse`);
await page.fill('input[type=search]', '');
await page.waitForFunction(() => !location.search.includes('q='));
check('observability: clearing the box removes q from the address (the page falls back to its default example)', !page.url().includes('q='), page.url());
await go(page, `${BASE}/tune?query=mouse`);
await page.fill('input[type=search]', '');
await page.waitForFunction(() => new URLSearchParams(location.search).get('query') === '');
await go(page, page.url());
check('tune: clearing the box empties the address parameter too, and a reload keeps the empty search', (await page.inputValue('input[type=search]')) === '' && !page.url().includes('mouse'), page.url());

// 0.9.10: the footer sits at the bottom of the window on a short page and below the content on a tall one (never fixed or sticky)
const footerBox = () => page.evaluate(() => {
    const f = document.querySelector('footer.site-footer');
    const r = f.getBoundingClientRect();
    return { bottom: r.bottom + scrollY, top: r.top + scrollY, vh: innerHeight, doc: document.documentElement.scrollHeight, mainBottom: document.querySelector('main').getBoundingClientRect().bottom + scrollY, position: getComputedStyle(f).position };
});
for (const path of ['/', '/nope']) {
    await go(page, BASE + path);
    const box = await footerBox();
    check(`footer: ${path === '/' ? 'the empty home page' : 'the error page'} ends with the footer exactly at the bottom of the window`, Math.abs(box.bottom - box.vh) < 1 && box.doc <= box.vh && box.position === 'static', JSON.stringify(box));
}
await go(page, BASE + '/?q=mouse');
await page.waitForSelector('#results .hit-row');
const tall = await footerBox();
check('footer: a tall results page has it below the content, at the end of the document', tall.doc > tall.vh && Math.abs(tall.bottom - tall.doc) < 1 && tall.top >= tall.mainBottom - 1 && tall.position === 'static', JSON.stringify(tall));
const foot = await page.locator('footer.site-footer').evaluate((f) => ({ text: f.innerText, links: [...f.querySelectorAll('a')].map((a) => [a.innerText.trim(), a.href]), role: f.parentElement.tagName }));
check('footer: a landmark child of <body> with the Fuzzphony version (a release number, not dev-main)', foot.role === 'BODY' && /^Fuzzphony\s+\d+\.\d+\.\d+/.test(foot.text.trim()) && !/dev-/.test(foot.text), foot.text);
check('footer: links to the documentation, GitHub, the changelog and the licence', ['Documentation', 'GitHub', 'Changelog', 'MIT licence'].every((n) => foot.links.some(([t, h]) => t === n && h.startsWith('https://github.com/er2es/fuzzphony'))), JSON.stringify(foot.links));
{
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const pp = await phone.newPage();
    await go(pp, BASE + '/');
    const m = await pp.evaluate(() => ({ bottom: document.querySelector('footer.site-footer').getBoundingClientRect().bottom, vh: innerHeight, tab: document.querySelector('.nav').getBoundingClientRect().top, foot: document.querySelector('.footer-links').getBoundingClientRect().bottom }));
    check('footer: on a phone it still ends at the bottom of the window, and its links are above the tab bar', Math.abs(m.bottom - m.vh) < 1 && m.foot <= m.tab, JSON.stringify(m));
    // the brand and the links are centred on the page at every width
    for (const width of [1280, 900, 700, 390]) {
        await pp.setViewportSize({ width, height: 844 });
        const c = await pp.evaluate(() => {
            const wm = document.querySelector('.wordmark').getBoundingClientRect();
            const nav = document.querySelector('.nav').getBoundingClientRect();
            return { navCentre: (nav.left + nav.right) / 2, wmCentre: (wm.left + wm.right) / 2, vw: document.documentElement.clientWidth, over: document.documentElement.scrollWidth - document.documentElement.clientWidth, wmRight: wm.right, navLeft: nav.left, stacked: nav.top > wm.bottom - 1 };
        });
        const fixedNav = width <= 640; // a tab bar at the bottom: centred too, the brand centred above
        check(`menu: centred on the page at ${width} px, no overflow`, Math.abs(c.navCentre - c.vw / 2) < 2 && c.over <= 0 && (width >= 768 ? c.wmRight <= c.navLeft : Math.abs(c.wmCentre - c.vw / 2) < 2), JSON.stringify(c) + String(fixedNav));
    }
    await phone.close();
}

await secContext.close();

await browser.close();
const failed = results.filter((ok) => !ok).length;
console.log(failed === 0 ? `all ${results.length} checks passed` : `${failed} of ${results.length} checks failed`);
process.exit(failed === 0 ? 0 : 1);
