// Cegah order dobel TANPA membuang pesanan ulang yang disengaja.
// - Isinya sama persis dengan order yang MASIH MENUNGGU ACC / pembayaran dan dibuat 2 hari terakhir
//   -> dianggap dobel, tidak dicatat lagi (order lamanya masih bisa diproses seperti biasa).
// - Sama persis dengan order yang sudah dibayar (LUNAS/DIKIRIM) atau order karyawan -> TETAP dicatat,
//   karena bisa jadi memang pesanan ulang; supervisor / bu Sarah diberi peringatan "kemungkinan dobel".
// - Isinya berbeda -> dicatat; kalau masih ada order lain yang berjalan, supervisor diberi catatan supaya bisa memutuskan.
const rb = $('Rapikan Balasan').first().json;
const rows = $input
    .all()
    .map(function (i) {
        return i.json;
    })
    .filter(function (o) {
        return o && o.order_id;
    });
const MENUNGGU = ['MENUNGGU_KONFIRMASI', 'DIACC'];
const BELUM_BAYAR = ['MENUNGGU_KONFIRMASI', 'DIACC', 'KARYAWAN'];
const SUDAH_BAYAR = ['LUNAS', 'DIKIRIM'];
const st = function (o) {
    return String(o.status_order || '').toUpperCase();
};
const berjalan = rows.filter(function (o) {
    return BELUM_BAYAR.indexOf(st(o)) >= 0 || SUDAH_BAYAR.indexOf(st(o)) >= 0;
});

const angka = function (v) {
    return Number(String(v == null ? '' : v).replace(/[^0-9]/g, '')) || 0;
};
const rp = function (n) {
    return (
        'Rp' +
        String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
    );
};
const normNama = function (s) {
    return String(s || '')
        .toLowerCase()
        .replace(/kilogram|kilo/g, 'kg')
        .replace(/gram|gr\b/g, 'g')
        .replace(/[^a-z0-9]+/g, '');
};
const parseDet = function (raw) {
    try {
        var d = typeof raw === 'string' ? JSON.parse(raw || '[]') : raw;
        return Array.isArray(d) ? d : [];
    } catch (e) {
        return [];
    }
};
const tandaItem = function (det, teks) {
    var t = parseDet(det)
        .map(function (it) {
            return (
                normNama(it.nama || it.name) +
                '#' +
                (Number(it.qty) || 0) +
                '#' +
                angka(it.harga_satuan || it.harga)
            );
        })
        .sort()
        .join('|');
    return t || normNama(teks);
};
const tanda = function (det, teks, total, ongkir, kurir, bayar) {
    return [
        tandaItem(det, teks),
        angka(total),
        angka(ongkir),
        normNama(kurir),
        normNama(bayar),
    ].join('~');
};
// umur order (hari) dari kolom tanggal "yyyy-mm-dd HH:mm" (WIB)
const umurHari = function (o) {
    const m = String(o.tanggal || '').match(
        /(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/,
    );
    if (!m) return 999;
    const t = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) - 7 * 3600 * 1000;
    return (Date.now() - t) / 86400000;
};

// === REVISI: customer mengganti order lama (AI mengisi ganti_order_id setelah customer setuju rekap revisi) ===
// - Order lama masih MENUNGGU_KONFIRMASI -> ditutup otomatis (DIBATALKAN) setelah order baru tercatat.
// - Order lama sudah DIACC / order karyawan -> supervisor diberi perintah BATAL siap salin.
// - Order lama sudah dibayar / dikirim -> tidak diubah, supervisor diminta cek manual.
const gantiId = String(rb.ganti_order_id || '')
    .trim()
    .toUpperCase();
const lamaGanti = gantiId
    ? rows.find(function (o) {
          return String(o.order_id || '').toUpperCase() === gantiId;
      })
    : null;
let tutup_order_lama = '',
    catatan_lama_baru = '',
    catatan_revisi = '',
    log_revisi = '';
if (gantiId) {
    const stG = lamaGanti ? st(lamaGanti) : '';
    const ringkasLama = lamaGanti
        ? String(lamaGanti.items || '-')
              .replace(/\s+/g, ' ')
              .slice(0, 160) +
          ' — ' +
          rp(angka(lamaGanti.total_produk) + angka(lamaGanti.ongkir))
        : '';
    if (stG === 'MENUNGGU_KONFIRMASI') {
        tutup_order_lama = lamaGanti.order_id;
        catatan_lama_baru =
            (lamaGanti.catatan_admin
                ? String(lamaGanti.catatan_admin) + ' | '
                : '') +
            'DIGANTI oleh ' +
            rb.order_id +
            ' (revisi customer) ' +
            (rb.tgl_interaksi_terakhir || '');
        catatan_revisi =
            '♻️ *REVISI dari ' +
            lamaGanti.order_id +
            '* — order lama (belum di-ACC) sudah *ditutup otomatis*, cukup proses order ini.\nSebelumnya: ' +
            ringkasLama;
        log_revisi =
            'Revisi dari ' +
            lamaGanti.order_id +
            ' (order lama ditutup otomatis)';
    } else if (stG === 'DIACC' || stG === 'KARYAWAN') {
        catatan_revisi =
            '♻️ *REVISI dari ' +
            lamaGanti.order_id +
            '* (' +
            stG +
            (lamaGanti.invoice_number
                ? ', invoice ' + lamaGanti.invoice_number + ' sudah terkirim'
                : '') +
            ').\nSebelumnya: ' +
            ringkasLama +
            '\nKalau revisi ini disetujui, batalkan dulu yang lama: *BATAL ' +
            lamaGanti.order_id +
            ' revisi customer*, lalu ACC yang baru.';
        log_revisi =
            'Revisi dari ' +
            lamaGanti.order_id +
            ' (' +
            stG +
            ', perlu BATAL manual)';
    } else {
        catatan_revisi =
            '♻️ Customer menyebut order ini *revisi dari ' +
            gantiId +
            '*' +
            (stG ? ' (status ' + stG + ')' : ' (order lama tidak ditemukan)') +
            '. Order lama TIDAK diubah sistem — mohon dicek manual sebelum ACC.';
        log_revisi =
            'Disebut revisi dari ' +
            gantiId +
            (stG ? ' (' + stG + ')' : ' (tidak ditemukan)');
    }
}
const tandaBaru = tanda(
    rb.order_items_detail,
    rb.order_items,
    rb.order_total,
    rb.order_ongkir,
    rb.kurir_pilihan,
    rb.metode_bayar,
);
const samaPersis = berjalan.filter(function (o) {
    return (
        tanda(
            o.items_detail,
            o.items,
            o.total_produk,
            o.ongkir,
            o.kurir_pilihan,
            o.metode_bayar,
        ) === tandaBaru
    );
});
const kembar = samaPersis.find(function (o) {
    return MENUNGGU.indexOf(st(o)) >= 0 && umurHari(o) <= 2;
});
if (kembar) return []; // order yang sama persis masih menunggu diproses -> tidak dicatat dobel
const miripLama = samaPersis.length ? samaPersis[samaPersis.length - 1] : null;
const karyawan = /POTONG GAJI/i.test(String(rb.metode_bayar || ''));

// order lama yang direvisi tidak dihitung lagi sebagai "order lain yang berjalan"
if (gantiId && lamaGanti && ['LUNAS', 'DIKIRIM'].indexOf(st(lamaGanti)) < 0) {
    for (let _i = berjalan.length - 1; _i >= 0; _i--) {
        if (String(berjalan[_i].order_id).toUpperCase() === gantiId)
            berjalan.splice(_i, 1);
    }
}
let catatan_order = '',
    catatan_log = '';
if (berjalan.length) {
    const belum = berjalan.filter(function (o) {
        return BELUM_BAYAR.indexOf(st(o)) >= 0;
    });
    const daftar = berjalan
        .map(function (o) {
            return (
                '• *' +
                o.order_id +
                '* (' +
                st(o) +
                (SUDAH_BAYAR.indexOf(st(o)) >= 0 ? ', sudah dibayar' : '') +
                ') — ' +
                rp(angka(o.total_produk) + angka(o.ongkir))
            );
        })
        .join('\n');
    let saran;
    if (miripLama) {
        saran =
            '⚠️ *Kemungkinan dobel:* isi pesanan ini SAMA PERSIS dengan *' +
            miripLama.order_id +
            '* (' +
            st(miripLama) +
            ', dibuat ' +
            (miripLama.tanggal || '-') +
            ').\n' +
            (karyawan
                ? 'Kalau karyawannya memang pesan lagi, proses seperti biasa. Kalau ternyata dobel, minta supervisor menolak yang baru supaya potong gajinya tidak dobel: *TOLAK ' +
                  rb.order_id +
                  ' dobel dengan ' +
                  miripLama.order_id +
                  '*'
                : 'Kalau customer memang pesan lagi, proses seperti biasa. Kalau ternyata dobel, tolak yang baru: *TOLAK ' +
                  rb.order_id +
                  ' dobel dengan ' +
                  miripLama.order_id +
                  '*');
    } else if (karyawan) {
        saran = belum.length
            ? 'Kalau order ini *revisi/pengganti*, minta supervisor membatalkan yang lama (*BATAL ' +
              belum[belum.length - 1].order_id +
              '*) supaya potong gajinya tidak dobel.'
            : 'Kalau order ini ternyata dobel, kabari supervisor sebelum potong gaji diproses.';
    } else if (belum.length) {
        saran =
            'Kalau order baru ini *revisi/pengganti* order yang belum dibayar, batalkan dulu yang lama: *BATAL ' +
            belum[belum.length - 1].order_id +
            '*, lalu ACC yang baru.\nKalau ini *pesanan tambahan*, proses keduanya seperti biasa.';
    } else {
        saran =
            'Order lamanya sudah dibayar. Kalau order baru ini ternyata *dobel atau revisi*, tanyakan dulu ke customer sebelum ACC.\nKalau ini *pesanan tambahan*, proses seperti biasa.';
    }
    catatan_order =
        '⚠️ *' +
        (karyawan ? 'Karyawan' : 'Customer') +
        ' ini masih punya order lain yang berjalan:*\n' +
        daftar +
        '\n\n' +
        saran;
    catatan_log =
        'Masuk saat masih ada order lain berjalan: ' +
        berjalan
            .map(function (o) {
                return o.order_id + ' (' + st(o) + ')';
            })
            .join(', ') +
        (miripLama ? '. Isinya sama persis dengan ' + miripLama.order_id : '');
}
if (log_revisi)
    catatan_log = log_revisi + (catatan_log ? '. ' + catatan_log : '');
const catatan_notif = [catatan_revisi, rb.peringatan_total, catatan_order]
    .filter(Boolean)
    .map(function (s) {
        return s + '\n\n';
    })
    .join('');

return [
    {
        json: {
            boleh_catat: true,
            order_berjalan: berjalan.map(function (o) {
                return o.order_id;
            }),
            sama_persis_dengan: miripLama ? miripLama.order_id : '',
            catatan_order: catatan_order,
            catatan_log: catatan_log,
            catatan_notif: catatan_notif,
            ganti_order_id: gantiId,
            tutup_order_lama: tutup_order_lama,
            catatan_lama_baru: catatan_lama_baru,
        },
        pairedItem: { item: 0 },
    },
];
