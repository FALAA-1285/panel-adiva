// Cek tampilan di layar yang lebih kecil dari desain (desain hanya 1440 px).
// Pakai:  npm run responsif                 -> semua halaman, lebar 1280 1024 768 390
//         npm run responsif -- Inbox Orders -> hanya halaman itu
// Env:    APP_URL (default http://127.0.0.1:8000), LEBAR="390 768"
// Hasil:  visual-diff/responsif/<Kode>-<lebar>.png (seluruh isi halaman)
// Gagal bila: dokumen (atau wadah gulir halaman) bisa digeser ke samping, atau ada elemen yang keluar dari layar
// di luar wadah yang memang bisa digeser ke samping (tabel, tab, kanban).
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const APP = (process.env.APP_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const LEBAR = (process.env.LEBAR || '1280 1024 768 390').split(/\s+/).map(Number);
const OUT = 'visual-diff/responsif';
const only = process.argv.slice(2);
const pages = JSON.parse(fs.readFileSync('design/halaman.json', 'utf8')).filter(
    (p) => p.rute && !p.rute.startsWith('/_demo') && (!only.length || only.includes(p.kode)),
);
// one shot per route (Daftar_2, LupaSandi_2 … are steps of the same page)
const todo = pages.filter((p, i) => pages.findIndex((q) => q.rute === p.rute) === i);
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const buka = async (login) => {
    const ctx = await browser.newContext({ locale: 'id-ID', timezoneId: 'Asia/Jakarta', reducedMotion: 'reduce' });
    const pg = await ctx.newPage();
    pg.on('pageerror', (e) => galat.push(e.message));
    if (login) await pg.goto(APP + '/_demo/login', { waitUntil: 'networkidle' });
    return pg;
};
const galat = [];
const masuk = await buka(true);
const tamu = await buka(false);
const rows = [];
let gagal = 0;

for (const p of todo) {
    const page = p.login === false ? tamu : masuk;
    for (const w of LEBAR) {
        galat.length = 0;
        await page.setViewportSize({ width: w, height: 900 });
        await page.goto(APP + p.rute, { waitUntil: 'networkidle' });
        await page.mouse.move(0, 0);
        const m = await page.evaluate(() => {
            const lebar = window.innerWidth;
            const bisaGeser = (e) => {
                for (let x = e.parentElement; x; x = x.parentElement) {
                    // the page's own scroller only scrolls down; sideways it is a bug
                    if (x.hasAttribute('data-gulir')) return false;
                    const s = getComputedStyle(x);
                    if (/(auto|scroll)/.test(s.overflowX) && x.scrollWidth > x.clientWidth) return true;
                    if (s.position === 'fixed') return false;
                }
                return false;
            };
            const tersembunyi = (e) => {
                for (let x = e; x; x = x.parentElement) {
                    const s = getComputedStyle(x);
                    if (s.visibility === 'hidden' || s.display === 'none') return true;
                }
                return false;
            };
            const keluar = [];
            for (const e of document.body.querySelectorAll('*')) {
                const r = e.getBoundingClientRect();
                if (!r.width || r.right <= lebar + 1 || tersembunyi(e) || bisaGeser(e)) continue;
                keluar.push(`${e.tagName.toLowerCase()}.${String(e.className).split(' ').slice(0, 3).join('.')} (${Math.round(r.right - lebar)}px)`);
            }
            const gulir = document.querySelector('[data-gulir]');
            return {
                geserDokumen:
                    document.documentElement.scrollWidth > lebar + 1 ||
                    (gulir !== null && gulir.scrollWidth > gulir.clientWidth + 1),
                keluar: keluar.slice(0, 4),
                tinggi: gulir ? gulir.scrollHeight + gulir.getBoundingClientRect().top : document.documentElement.scrollHeight,
            };
        });
        // the whole page in one picture
        await page.setViewportSize({ width: w, height: Math.min(Math.max(900, Math.round(m.tinggi)), 8000) });
        await page.waitForTimeout(150);
        await page.screenshot({ path: path.join(OUT, `${p.kode}-${w}.png`) });
        const lolos = !m.geserDokumen && m.keluar.length === 0 && galat.length === 0;
        if (!lolos) gagal++;
        rows.push({
            Kode: p.kode,
            Lebar: w,
            Hasil: lolos ? 'LOLOS' : 'GAGAL',
            'Keluar layar': m.geserDokumen ? 'dokumen bisa digeser ke samping' : m.keluar.join(' | ').slice(0, 110) || '-',
            Error: galat.slice(0, 1).join('').slice(0, 60) || '-',
        });
    }
}
await browser.close();
console.table(rows);
console.log(gagal ? `${gagal} tampilan belum rapi. Lihat ${OUT}/` : `Semua tampilan rapi. Gambar di ${OUT}/`);
process.exit(gagal ? 1 : 0);
