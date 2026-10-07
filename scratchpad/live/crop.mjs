// 긴 스크린샷을 잘라 보기: node crop.mjs <in.png> <out.png> <height> [y]
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [,, inp, out, hh, yy] = process.argv; const y = +(yy||0);
const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const pg = await br.newPage();
const fs = await import('node:fs');
const b64 = fs.readFileSync(inp).toString('base64');
await pg.setContent(`<img id=i src="data:image/png;base64,${b64}" style="margin-top:-${y}px">`);
const dim = await pg.evaluate(()=>{const i=document.getElementById('i');return [i.naturalWidth,i.naturalHeight]});
const h = Math.min(dim[1]-y, +hh);
await pg.setViewportSize({width:dim[0],height:h});
await pg.evaluate(()=>{document.body.style.margin='0';});
await pg.screenshot({path:out,clip:{x:0,y:0,width:dim[0],height:h}});
await br.close(); console.log(out, dim);
