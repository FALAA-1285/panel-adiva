// Uji visual: bandingkan halaman aplikasi dengan gambar desain, piksel demi piksel.
// Pakai:  npm run visual            -> semua halaman
//         npm run visual -- Main Otomasi
// Env:    APP_URL (default http://127.0.0.1:8000), VISUAL_MAX (default 0.005 = 0,5%),
//         VISUAL_LOGIN=0 untuk melewati /_demo/login
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import fs from 'node:fs';
import path from 'node:path';

const APP = (process.env.APP_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const MAX = Number(process.env.VISUAL_MAX || 0.005);
const OUT = 'visual-diff';
const pages = JSON.parse(fs.readFileSync('design/halaman.json', 'utf8'));
const only = process.argv.slice(2);
const todo = pages.filter((p) => p.rute && (!only.length || only.includes(p.kode)));
if (!todo.length) {
  console.error('Tidak ada halaman yang cocok. Kode yang tersedia: ' + pages.map((p) => p.kode).join(', '));
  process.exit(2);
}
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const errors = [];
const warnings = [];
const bukaHalaman = async () => {
  // the design is drawn at rest: no motion (transitions, toasts) and no hover in the shot
  const ctx = await browser.newContext({ deviceScaleFactor: 1, locale: 'id-ID', timezoneId: 'Asia/Jakarta', reducedMotion: 'reduce' });
  const pg = await ctx.newPage();
  pg.on('pageerror', (e) => errors.push(e.message));
  pg.on('console', (m) => {
    if (m.type() !== 'error') return;
    // aset yang gagal dimuat (mis. font saat offline) dicatat sebagai peringatan, bukan kegagalan
    (/Failed to load resource/.test(m.text()) ? warnings : errors).push(m.text());
  });
  return pg;
};
const pageMasuk = await bukaHalaman();
// halaman bertanda "login": false (Masuk, Daftar, Lupa sandi) dipotret tanpa sesi
let pageTamu = null;

if (process.env.VISUAL_LOGIN !== '0') {
  await pageMasuk.goto(APP + '/_demo/login', { waitUntil: 'networkidle' }).catch((e) => {
    console.error('Gagal membuka /_demo/login — pastikan APP_DEMO=true dan server jalan di ' + APP + '\n' + e.message);
    process.exit(2);
  });
}

const crop = (img, w, h) => {
  const o = new PNG({ width: w, height: h });
  PNG.bitblt(img, o, 0, 0, w, h, 0, 0);
  return o;
};

let failed = 0;
const rows = [];
for (const p of todo) {
  errors.length = 0;
  warnings.length = 0;
  const page = p.login === false ? (pageTamu ??= await bukaHalaman()) : pageMasuk;
  await page.setViewportSize({ width: p.w, height: p.h });
  await page.goto(APP + p.rute, { waitUntil: 'networkidle' });
  // "langkahApp": urutan ["isi", selector, nilai] / ["klik", teks] khusus aplikasi, menggantikan "klik"
  // (mis. form Daftar yang benar-benar divalidasi server); foto-desain.mjs mengabaikannya
  const langkah = p.langkahApp || (p.klik || []).map((t) => ['klik', t]);
  for (const [i, [aksi, target, nilai]] of langkah.entries()) {
    if (aksi === 'isi') {
      await page.fill(target, nilai);
    } else {
      await page.getByText(target, { exact: false }).first().click();
      await page.waitForTimeout(300);
      // klik terakhir boleh memicu permintaan ke server: tunggu sampai selesai sebelum memotret
      if (p.langkahApp && i === langkah.length - 1) await page.waitForLoadState('networkidle');
    }
  }
  if (langkah.at(-1)?.[0] === 'isi') await page.waitForTimeout(300);
  // a search typed earlier may still be waiting out its debounce: let it finish so the
  // Inertia progress bar is not in the shot
  if (p.langkahApp) await page.waitForTimeout(700);
  await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(250);

  const appPng = PNG.sync.read(await page.screenshot({ clip: { x: 0, y: 0, width: p.w, height: p.h } }));
  const designPng = PNG.sync.read(fs.readFileSync(path.join('design/screens', p.kode + '.png')));
  const w = Math.min(appPng.width, designPng.width);
  const h = Math.min(appPng.height, designPng.height);
  const A = crop(designPng, w, h);
  const B = crop(appPng, w, h);
  const D = new PNG({ width: w, height: h });
  const diff = pixelmatch(A.data, B.data, D.data, w, h, { threshold: 0.1, alpha: 0.25, diffColor: [220, 38, 38] });
  const ratio = diff / (w * h);
  const sizeOk = appPng.width === designPng.width && appPng.height === designPng.height;
  // tinggi konten: kalau halaman aplikasi lebih panjang dari desain, itu juga selisih.
  // Halaman panel bergulir di wadah [data-gulir] di bawah Topbar: yang diukur adalah isi
  // yang terlihat (kotak berlatar/bergaris dan teks), bukan padding bawah halaman.
  // Toleransi 16px: Detail pesanan sejak awal berakhir 15px di bawah garis desain 1250px.
  const scrollH = await page.evaluate(() => {
    const gulir = document.querySelector('[data-gulir]');
    if (!gulir) return document.documentElement.scrollHeight;
    let bawah = 0;
    for (const e of gulir.querySelectorAll('*')) {
      const r = e.getBoundingClientRect();
      const s = getComputedStyle(e);
      if (!r.height || s.visibility === 'hidden') continue;
      const terlihat = !e.children.length || s.borderBottomWidth !== '0px' || s.backgroundColor !== 'rgba(0, 0, 0, 0)';
      if (!terlihat) continue;
      // inside a pane with its own scroll (chat thread, lists) only the pane's box counts
      let ujung = r.bottom;
      for (let x = e.parentElement; x && x !== gulir; x = x.parentElement) {
        if (getComputedStyle(x).overflowY !== 'visible') ujung = Math.min(ujung, x.getBoundingClientRect().bottom);
      }
      bawah = Math.max(bawah, ujung + gulir.scrollTop);
    }
    return Math.round(Math.max(document.documentElement.scrollHeight, bawah));
  });
  const heightOk = scrollH <= p.h + 16;
  const pass = ratio <= MAX && sizeOk && heightOk && errors.length === 0;
  if (!pass) failed++;

  fs.writeFileSync(path.join(OUT, p.kode + '-app.png'), PNG.sync.write(appPng));
  fs.writeFileSync(path.join(OUT, p.kode + '-diff.png'), PNG.sync.write(D));
  rows.push({
    Kode: p.kode,
    Hasil: pass ? 'LOLOS' : 'GAGAL',
    Selisih: (ratio * 100).toFixed(2) + '%',
    Ukuran: sizeOk ? 'sama' : `${appPng.width}×${appPng.height} vs ${designPng.width}×${designPng.height}`,
    'Tinggi halaman': heightOk ? 'pas' : `${scrollH}px > ${p.h}px`,
    Error: errors.length ? errors.slice(0, 2).join(' | ').slice(0, 120) : '-',
    Peringatan: warnings.length ? warnings.length + ' aset gagal dimuat' : '-',
  });
}
await browser.close();
console.table(rows);
console.log(failed ? `${failed} halaman belum sama dengan desain. Lihat folder ${OUT}/` : 'Semua halaman sama dengan desain.');
process.exit(failed ? 1 : 0);
