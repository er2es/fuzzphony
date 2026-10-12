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

const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
const page = await context.newPage();
page.setDefaultTimeout(8000);
await page.goto(BASE + '/', { waitUntil: 'load' });
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
await page.goto(BASE + '/?q=mouse', { waitUntil: 'load' });
await page.click('.segmented a >> text=Speed');
await page.waitForSelector('.speed-bars');
check('Speed shows the bars', (await page.locator('.speed-row').count()) >= 2);

// 7. a phone: the tab bar is at the bottom of the screen
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
const p2 = await phone.newPage();
await p2.goto(BASE + '/', { waitUntil: 'load' });
const box = await p2.locator('nav.nav').boundingBox();
check('on a phone the navigation is a tab bar at the bottom', box !== null && box.y > 700, JSON.stringify(box));
await p2.click('.nav .menu summary');
check('the menu opens upwards from the tab bar', (await p2.locator('.menu-panel a').first().boundingBox())?.y < box.y);

await browser.close();
const failed = results.filter((ok) => !ok).length;
console.log(failed === 0 ? `all ${results.length} checks passed` : `${failed} of ${results.length} checks failed`);
process.exit(failed === 0 ? 0 : 1);
