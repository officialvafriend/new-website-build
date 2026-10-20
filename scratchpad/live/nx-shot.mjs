import { chromium } from 'playwright';
const qs = JSON.parse(process.argv[2]);
const out = process.argv[3];
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const res = {};
for (const [mode, vp] of [['m', { width: 390, height: 844 }], ['pc', { width: 1280, height: 900 }]]) {
  const ctx = await b.newContext({ viewport: vp, isMobile: mode === 'm', locale: 'ko-KR',
    userAgent: mode === 'm' ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
                            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' });
  await ctx.route('**/*', async r => {
    const u = r.request().url();
    if (!/^https?:/.test(u) || /\.(mp4|woff2?)(\?|$)/.test(u)) return r.abort();
    try { const f = await fetch(u, { method: r.request().method(), headers: { ...r.request().headers() }, body: r.request().postData() || undefined, redirect: 'manual' });
      const body = Buffer.from(await f.arrayBuffer()); const h = {}; f.headers.forEach((v, k) => { if (!/content-encoding|content-length|transfer-encoding/.test(k)) h[k] = v; });
      await r.fulfill({ status: f.status, headers: h, body });
    } catch { r.abort(); }
  });
  const p = await ctx.newPage();
  for (const q of qs) { await p.waitForTimeout(2500);
    const url = (mode === 'm' ? 'https://m.search.naver.com/search.naver?query=' : 'https://search.naver.com/search.naver?where=nexearch&query=') + encodeURIComponent(q);
    try { await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 }); await p.waitForTimeout(7000); } catch (e) { console.log('ERR', q, e.message); continue; }
    const r = await p.evaluate(() => {
      const links = [...document.querySelectorAll('a[href*="duck-hoo.com"]')].filter(a => { const s = getComputedStyle(a); const rc = a.getBoundingClientRect(); return rc.height > 0 && s.visibility !== 'hidden'; });
      const first = links.length ? links[0].getBoundingClientRect().top + scrollY : -1;
      const heads = [...document.querySelectorAll('h2, .api_title, .fds-comps-header-headline, .sds-comps-text-type-headline1')].map(h => ({ t: h.innerText.trim().split('\n')[0].slice(0, 30), y: Math.round(h.getBoundingClientRect().top + scrollY) })).filter(h => h.t && (first < 0 || h.y < first + 5));
      let root = document.querySelector('.fds-aib-expandable-container'); for (let i = 0; root && i < 6 && !/AI\s*브리핑/.test(root.innerText.slice(0, 60)); i++) root = root.parentElement;
      const ai = (root ? [...root.querySelectorAll('a[href]')] : []).map(a => { try { return new URL(a.href).hostname.replace(/^www\./,''); } catch { return ''; } }).filter(h => h && !/naver\.com|pstatic/.test(h));
      const aiSrc = [...new Set(ai)].slice(0, 6);
      const blocks = []; let seen = new Set();
      for (const a of document.querySelectorAll('a[href^="http"]')) { const rc = a.getBoundingClientRect(); if (rc.height < 8) continue; let h; try { h = new URL(a.href).hostname.replace(/^www\./,''); } catch { continue; } if (/naver\.com$|pstatic|naver\.net/.test(h) && !/blog\.naver|cafe\.naver|kin\.naver/.test(h)) continue; const y = Math.round(rc.top + scrollY); if (seen.has(h)) continue; seen.add(h); blocks.push({ h, y }); }
      blocks.sort((a, b) => a.y - b.y);
      const order = blocks.map(b => b.h); const ourRank = order.indexOf('duck-hoo.com') + 1;
      const aiHas = !!root, aiTop = root ? Math.round(root.getBoundingClientRect().bottom + scrollY) : 0;
      return { aiHas, aiTop, aiSrc, ourRank, order: order.slice(0, 8), first: Math.round(first), screens: first > 0 ? +(first / innerHeight).toFixed(1) : null, above: heads.map(h => h.t).filter((v, i, a) => a.indexOf(v) === i).slice(0, 12), docH: document.documentElement.scrollHeight };
    });
    res[`${mode}|${q}`] = r;
    console.log(mode, q, '| AI', r.aiHas ? '있음' : '없음', '출처:', r.aiSrc.join(',') || '-', '| 우리 AI안', r.aiSrc.indexOf('duck-hoo.com') + 1, '|', '| 사이트순서', r.ourRank, r.order.slice(0,6).join(' > '), '| y', r.first);
    if (process.env.SHOT && r.first > 0) await p.screenshot({ path: `${out}-${mode}-${qs.indexOf(q)}.png`, clip: { x: 0, y: Math.max(0, r.first - vp.height + 200), width: vp.width, height: vp.height } });
    await p.waitForTimeout(1500);
  }
  await ctx.close();
}
await b.close();
(await import('node:fs')).writeFileSync(out + '.json', JSON.stringify(res, null, 1));
