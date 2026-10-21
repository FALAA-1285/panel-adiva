// Membuat ulang gambar acuan design/screens/*.png dari HTML asli desain (design/source).
// Jalankan sekali di komputer yang sama dengan tempat uji visual dijalankan, supaya
// font dan cara browser menggambar teks identik:  npm run foto-desain  (atau: -- Main Otomasi)
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const SRC = path.resolve('design/source');
const OUT = path.resolve('design/screens');
const pages = JSON.parse(fs.readFileSync('design/halaman.json', 'utf8'));
const only = process.argv.slice(2);
fs.mkdirSync(OUT, { recursive: true });

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.json': 'application/json' };
const server = http.createServer((req, res) => {
  const f = path.join(SRC, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!f.startsWith(SRC) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/`;

const browser = await chromium.launch();
const ctx = await browser.newContext({ deviceScaleFactor: 1, locale: 'id-ID', timezoneId: 'Asia/Jakarta' });
const page = await ctx.newPage();
let n = 0;
for (const p of pages) {
  if (only.length && !only.includes(p.kode)) continue;
  await page.setViewportSize({ width: p.w, height: p.h });
  await page.goto(base + p.sumber, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  for (const t of p.klik || []) {
    await page.getByText(t, { exact: false }).first().click();
    await page.waitForTimeout(400);
  }
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: path.join(OUT, p.kode + '.png'), clip: { x: 0, y: 0, width: p.w, height: p.h } });
  console.log('✓', p.kode, `${p.w}×${p.h}`);
  n++;
}
await browser.close();
server.close();
console.log(`${n} gambar acuan ditulis ke design/screens/`);
