// Screenshots and accessibility check of the demo, for reviewing the interface (see docs/superpowers/specs/2026-10-12-v082-demo-interface-design.md, section 9).
//
// Not part of the demo image and not a dependency of the project: it runs in the official Playwright image.
//
//   docker run --rm -v "$PWD/demo/tools:/work" -v "$PWD/build/shots:/out" -e BASE=http://host.docker.internal:8099 \
//       mcr.microsoft.com/playwright:v1.55.0-noble sh -c "cd /work && npm i --no-save --no-audit --no-fund playwright@1.55.0 @axe-core/playwright >/dev/null 2>&1 && node shots.mjs"
//
// Writes <out>/<page>-<viewport>-<scheme>.png and prints the axe violations (light scheme, desktop) per page.
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const BASE = process.env.BASE ?? 'http://host.docker.internal:8099';
const OUT = process.env.OUT ?? '/out';
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;

const pages = JSON.parse(process.env.PAGES ?? JSON.stringify([
    { name: 'home', path: '/' },
    { name: 'home-query', path: '/?q=mouse' },
    { name: 'relevance', path: '/relevance?q=tv' },
    { name: 'synonyms', path: '/synonyms' },
    { name: 'languages', path: '/languages?lang=hu&q=tv' },
    { name: 'playground', path: '/playground' },
    { name: 'wizard', path: '/wizard' },
    { name: 'doctor', path: '/doctor' },
    { name: 'observability', path: '/observability' },
]));
const viewports = { phone: { width: 390, height: 844 }, laptop: { width: 1280, height: 800 } };
// interactions: typing into the search box and capturing the suggestion panel
const flows = JSON.parse(process.env.FLOWS ?? JSON.stringify([
    { name: 'suggest-home', path: '/', type: 'wireless mou', selector: 'input[type=search]' },
]));

const browser = await chromium.launch();
let failures = 0;
for (const page of pages) {
    if (ONLY && !ONLY.includes(page.name)) continue;
    for (const [vpName, viewport] of Object.entries(viewports)) {
        for (const scheme of ['light', 'dark']) {
            const context = await browser.newContext({ viewport, colorScheme: scheme, reducedMotion: 'reduce' });
            const p = await context.newPage();
            const messages = [];
            p.on('console', (m) => ['error', 'warning'].includes(m.type()) && messages.push(`${m.type()}: ${m.text()}`));
            p.on('pageerror', (e) => messages.push(`pageerror: ${e.message}`));
            await p.goto(BASE + page.path, { waitUntil: 'networkidle' });
            await p.waitForTimeout(500);
            await p.screenshot({ path: `${OUT}/${page.name}-${vpName}-${scheme}.png`, fullPage: true });
            const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (overflow > 0) {
                console.log(`! ${page.name} ${vpName} ${scheme}: horizontal overflow of ${overflow}px`);
                failures++;
            }
            if (vpName === 'laptop' && scheme === 'light') {
                const result = await new AxeBuilder({ page: p }).analyze();
                for (const v of result.violations) {
                    console.log(`! axe ${page.name}: ${v.id} (${v.impact}) x${v.nodes.length}: ${v.help}`);
                    for (const n of v.nodes.slice(0, 3)) console.log(`    ${n.html.slice(0, 140)}`);
                    failures++;
                }
            }
            for (const m of messages) console.log(`! console ${page.name} ${vpName} ${scheme}: ${m}`);
            await context.close();
        }
    }
}
for (const flow of flows) {
    if (ONLY && !ONLY.includes(flow.name)) continue;
    for (const [vpName, viewport] of Object.entries(viewports)) {
        const context = await browser.newContext({ viewport, colorScheme: 'light', reducedMotion: 'reduce' });
        const p = await context.newPage();
        await p.goto(BASE + flow.path, { waitUntil: 'networkidle' });
        await p.click(flow.selector);
        await p.keyboard.type(flow.type, { delay: 40 });
        await p.waitForTimeout(900);
        await p.screenshot({ path: `${OUT}/${flow.name}-${vpName}.png` });
        await context.close();
    }
}
await browser.close();
console.log(failures === 0 ? 'no problems found' : `${failures} problem(s)`);
