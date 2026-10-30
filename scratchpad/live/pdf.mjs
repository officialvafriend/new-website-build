import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
const [inp, out] = process.argv.slice(2);
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const p = await b.newPage();
await p.goto(pathToFileURL(inp).href, { waitUntil: 'load' });
await p.evaluate(() => document.fonts.ready);
await p.pdf({ path: out, format: 'A4', printBackground: true, preferCSSPageSize: true });
await b.close();
