// Runs one of n8n's old Code nodes on test cases, so the PHP that replaced it can be compared
// with it: node jalankan.cjs <code.js> <cases.json>  ->  prints the results as JSON.
// A case gives the node's input ($json, or several rows in "input"), what $('Node name')
// returns, and the clock.
const fs = require('fs');
const [kodeBerkas, kasusBerkas] = process.argv.slice(2);
const kode = fs.readFileSync(kodeBerkas, 'utf8');
const semua = JSON.parse(fs.readFileSync(kasusBerkas, 'utf8'));
const asli = Date.now;

const hasil = semua.map(function (k) {
    Date.now = function () {
        return k.sekarang_ms;
    };
    const node = k.node || {};
    const $ = function (nama) {
        const isi = node[nama] || [];
        return {
            first: function () {
                return { json: isi[0] };
            },
            all: function () {
                return isi.map(function (j) {
                    return { json: j };
                });
            },
            isExecuted: isi.length > 0,
        };
    };
    try {
        const baris = k.input || [k.json];
        const $input = {
            first: function () {
                return { json: baris[0] };
            },
            all: function () {
                return baris.map(function (j) {
                    return { json: j };
                });
            },
        };
        const keluar = new Function('$json', '$', '$input', kode)(
            k.json,
            $,
            $input,
        );
        return keluar && keluar.length ? keluar[0].json : null;
    } finally {
        Date.now = asli;
    }
});
process.stdout.write(JSON.stringify(hasil));
