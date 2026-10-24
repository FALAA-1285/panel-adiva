// Ambil hasil terstruktur dari AI Agent + rapikan jadi format WhatsApp
const out =
    $json.output && typeof $json.output === 'object'
        ? $json.output
        : $json || {};
const src = $('Siapkan Konteks').first().json;
const lama = src.data_lama || {};

// Customer minta berhenti dihubungi -> tandai optout supaya tidak difollow-up lagi.
// Hanya kalimat yang jelas maksudnya (contoh: "stop", "jangan chat saya lagi", "stop promo"),
// supaya kalimat biasa seperti "jangan kirim dulu ya kak" tidak ikut terhitung.
const _pesanCust = String(src.pesan || '')
    .toLowerCase()
    .trim();
const _JANGAN =
    '(jangan|gausah|ga usah|gak usah|nggak usah|ngga usah|tidak usah|tdk usah|ndak usah|gk usah)';
const mintaBerhenti =
    /^(stop|berhenti|unsubscribe|unsub)[\s!.]*$/.test(_pesanCust) ||
    /\b(unsubscribe|hapus nomor (saya|aku|ku))\b/.test(_pesanCust) ||
    /\b(stop|berhenti)\b[^.?!]{0,15}\b(promo|broadcast|spam|kirim(in)? (promo|pesan|chat|broadcast)|chat(in)? (saya|aku)|hubungi|wa(in)? (saya|aku))\b/.test(
        _pesanCust,
    ) ||
    new RegExp(
        '\\b' +
            _JANGAN +
            '\\b[^.?!]{0,20}\\b(chat|hubungi|kirim(in)? (promo|pesan|chat)|promo|wa|spam)\\b[^.?!]{0,15}\\blagi\\b',
    ).test(_pesanCust) ||
    new RegExp(
        '\\b' + _JANGAN + '\\b[^.?!]{0,10}\\b(promo|spam|broadcast)\\b',
    ).test(_pesanCust);

let teks = String(out.balasan || '');
teks = teks
    .replace(/\\r\\n/g, '\n')
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, ' ');
teks = teks.replace(/\*\*\*(.+?)\*\*\*/g, '*$1*'); // ***x*** -> *x*
teks = teks.replace(/\*\*(.+?)\*\*/g, '*$1*'); // **x**   -> *x*
teks = teks.replace(/^#{1,6}\s*/gm, ''); // buang heading markdown
teks = teks.replace(/^\s*[-*]\s+/gm, '• '); // bullet   -> •
teks = teks.replace(/\n{3,}/g, '\n\n').trim();
if (!teks) teks = 'Mohon maaf Kak, boleh diulang pesannya ya 😊';

const pad = (n) => String(n).padStart(2, '0');
const wib = new Date(Date.now() + 7 * 60 * 60 * 1000);
const fmt = (d) =>
    `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
const jeda = Number(out.jeda_followup_hari);
const hari = Number.isFinite(jeda) && jeda > 0 ? jeda : jeda === 0 ? 3650 : 2;
const hariAman = Math.min(
    Math.max(1, Number.isFinite(Number(hari)) ? Number(hari) : 2),
    3650,
);
const next = new Date(wib.getTime() + hariAman * 24 * 60 * 60 * 1000);

// Customer minta batal order tertentu (diisi AI) -> admin dapat perintah BATAL siap salin
const _batalId = (String(out.batal_order_id || '')
    .toUpperCase()
    .match(/ORD-[A-Z0-9-]+/) || [''])[0];
const isiAtauLama = (baru, lamaVal) =>
    baru && String(baru).trim() !== '' ? String(baru) : lamaVal || '';
// Prospek yang berhenti membalas cukup disapa SATU KALI, JEDA_FOLLOWUP_JAM (default 10) jam setelah chat terakhir.
// Jeda 0 dari AI = customer minta tidak dihubungi.
const _TAHAP_PROSPEK = [
    'BARU',
    'TANYA_PRODUK',
    'TANYA_HARGA',
    'NEGO',
    'SIAP_ORDER',
    'CART_DITINGGAL',
];
const _tahapAI = isiAtauLama(out.tahap_funnel, lama.tahap_funnel) || 'BARU';
// Customer yang masih punya order berjalan tidak diturunkan ke tahap prospek (CRM & jadwal sapaan tetap benar).
let _adaOrderAktif = false;
try {
    _adaOrderAktif = $('Ambil Order Customer')
        .all()
        .some(function (i) {
            const r = (i && i.json) || {};
            return (
                /^(MENUNGGU_KONFIRMASI|DIACC|KARYAWAN|LUNAS|SIAP|DIKIRIM)$/i.test(
                    String(r.status_order || '').trim(),
                ) &&
                String(r.order_id || '') !== String(out.batal_order_id || '')
            );
        });
} catch (e) {}
const _tahapFinal =
    _adaOrderAktif &&
    !out.order_siap &&
    _TAHAP_PROSPEK.indexOf(String(_tahapAI).toUpperCase()) >= 0
        ? 'ORDER_DIBUAT'
        : _tahapAI;
let _jamFU = 10;
try {
    const _rj = $('Ambil Config Ongkir')
        .all()
        .map(function (i) {
            return i.json;
        })
        .find(function (r) {
            return r && r.kunci === 'JEDA_FOLLOWUP_JAM';
        });
    const _nj = Number(_rj && _rj.nilai);
    if (_nj > 0) _jamFU = _nj;
} catch (e) {}
// Tahap selain prospek (REPEAT, ORDER_DIBUAT, TIDAK_MINAT) tidak dijadwalkan follow-up; kesan pesanan ditanya lewat LOG_ORDER.
const _isProspek =
    _TAHAP_PROSPEK.indexOf(String(_tahapFinal).toUpperCase()) >= 0;
const nextFU =
    jeda === 0
        ? next
        : _isProspek
          ? new Date(wib.getTime() + _jamFU * 3600000)
          : null;

// Nomor HP asli dari hasil resolusi LID (kalau customer pakai @lid dan WAHA berhasil).
// Aman: kalau node 'Cari Nomor dari LID' belum tersambung/kosong, pakai nilai lama.
let _nomorFinal = String(src.nomor_wa || '');
try {
    if (String(src.chatId || '').endsWith('@lid')) {
        var _lidRes = $('Pesan Gabungan').first().json;
        var _arr = Array.isArray(_lidRes) ? _lidRes[0] : _lidRes;
        var _pn = _arr && _arr.pn;
        if (_pn) _nomorFinal = String(_pn).split('@')[0].replace(/\D/g, '');
    }
} catch (e) {}
const kontak = /^62\d{7,}$/.test(_nomorFinal)
    ? 'wa.me/' + _nomorFinal
    : String(src.chatId || '');

// === Total produk dihitung ulang dari rincian item (bukan angka tulisan AI) ===
// Mencegah ongkir terhitung dua kali kalau AI memasukkan ongkir ke total produk,
// dan membaca harga yang ditulis "35.000" sebagai 35000 (bukan 35).
const _angka = function (v) {
    if (typeof v === 'number') return Math.round(v);
    var s = String(v == null ? '' : v)
        .replace(/rp/gi, '')
        .replace(/\s/g, '');
    if (/^\d{1,3}([.,]\d{3})+$/.test(s)) return Number(s.replace(/[.,]/g, ''));
    if (/^\d+([.,]\d{1,2})?$/.test(s))
        return Math.round(parseFloat(s.replace(',', '.')));
    return Number(s.replace(/[^0-9]/g, '')) || 0;
};
const _rpTeks = function (n) {
    return (
        'Rp' +
        String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
    );
};
const _detail = Array.isArray(out.order_items_detail)
    ? out.order_items_detail
          .map(function (it) {
              return {
                  nama: String((it && it.nama) || '').slice(0, 120),
                  qty: Number(it && it.qty) || 1,
                  harga_satuan: _angka(it && it.harga_satuan),
              };
          })
          .filter(function (it) {
              return it.nama;
          })
          .slice(0, 60)
    : [];
const _sumDetail = _detail.reduce(function (a, it) {
    return a + it.qty * it.harga_satuan;
}, 0);
const _totalAI = _angka(out.order_total);
const _ongkirAI = _angka(out.order_ongkir);
let _totalFinal = _totalAI;
let _peringatanTotal = '';
if (_sumDetail > 0) {
    _totalFinal = _sumDetail;
    const _ongkirIkutMasuk =
        _ongkirAI > 0 && _totalAI === _sumDetail + _ongkirAI;
    if (_totalAI > 0 && _totalAI !== _sumDetail && !_ongkirIkutMasuk) {
        _peringatanTotal =
            '⚠️ Total produk yang ditulis AI (' +
            _rpTeks(_totalAI) +
            ') tidak sama dengan hitungan rincian item (' +
            _rpTeks(_sumDetail) +
            '). Sistem memakai hitungan rincian. Mohon cek angka yang disebut ke customer sebelum ACC.';
    }
}

// === Ongkir Rp0 untuk pesanan yang dikirim kurir: biasanya paket gratis ongkir — supervisor diminta memastikan ===
const _kurirAI = String(out.kurir_pilihan || '');
// === Pengiriman EKSPEDISI = ONGKIR COD (ongkir dibayar ke kurir, ekspedisi & nominal ditentukan CS). Paket gratis ongkir dikecualikan. ===
const _ekspedisiAI = !!_kurirAI.trim() && !/AMBIL SENDIRI|PICKUP|KARYAWAN|KURIR MOTOR|ARMADA KANTOR|POTONG GAJI/i.test(_kurirAI + ' ' + String(out.metode_bayar || ''));
const _ongkirCod = _ekspedisiAI && !/GRATIS ONGKIR/i.test(_kurirAI);
const _kurirFinal = (_ongkirCod && !/ONGKIR COD/i.test(_kurirAI)) ? ('EKSPEDISI (ONGKIR COD)' + (/^\s*EKSPEDISI\b/i.test(_kurirAI) ? '' : ' - ' + _kurirAI.trim())).slice(0, 80) : _kurirAI;
if (out.order_siap && _kurirAI && !_ongkirCod && !/AMBIL SENDIRI|PICKUP|KARYAWAN|KURIR MOTOR|ARMADA KANTOR/i.test(_kurirAI + ' ' + String(out.metode_bayar || '')) && !(_ongkirAI > 0)) {
_peringatanTotal = (_peringatanTotal ? _peringatanTotal + '\n\n' : '') + 'ℹ️ Ongkir tercatat *Rp0* (kurir ' + _kurirAI + '). Bot menganggap ini paket gratis ongkir — mohon pastikan sebelum ACC.';
}

if (out.order_siap && _ongkirCod) {
  _peringatanTotal = (_peringatanTotal ? _peringatanTotal + '\n\n' : '') + '📦 Dikirim *EKSPEDISI — ONGKIR COD* (' + _kurirFinal + '). Ongkir dibayar customer ke kurir. Kalau mau mencantumkan ongkir di invoice sebagai info, tulis saat ACC, contoh: *ACC ' + 'ORD-xxx 25000*.';
}

// === Kurir motor Bang Bana: ongkir di order = batas atas estimasi, admin boleh menetapkan angka pasti saat ACC ===
if (out.order_siap && /KURIR MOTOR/i.test(_kurirAI)) {
    _peringatanTotal =
        (_peringatanTotal ? _peringatanTotal + '\n\n' : '') +
        '🛵 Dikirim *KURIR MOTOR* (dari pabrik Lawang). ' +
        (_ongkirAI > 0
            ? 'Ongkir di order ' +
              _rpTeks(_ongkirAI) +
              ' = batas atas estimasi. Kalau ongkir pastinya beda, tulis angkanya saat ACC, contoh: *ACC ' +
              'ORD-xxx 25000*.'
            : 'Ongkir belum tercatat — tulis ongkirnya saat ACC, contoh: *ACC ' +
              'ORD-xxx 30000*.');
}

// === Armada kantor (truk/mobil kantor): biaya kirim dari tarif CONFIG_TOKO ARMADA_TARIF, atau ditetapkan admin saat ACC untuk belanja besar ===
if (out.order_siap && /ARMADA KANTOR/i.test(_kurirAI)) {
    let _minArm = 0,
        _batasArm = 45000000;
    try {
        $('Ambil Config Ongkir')
            .all()
            .forEach(function (i) {
                const r = i.json || {};
                if (r.kunci === 'ARMADA_MIN_ORDER') _minArm = _angka(r.nilai);
                if (r.kunci === 'ARMADA_BATAS_TARIF' && _angka(r.nilai))
                    _batasArm = _angka(r.nilai);
            });
    } catch (e) {}
    const _belanjaArm = _totalFinal;
    _peringatanTotal =
        (_peringatanTotal ? _peringatanTotal + '\n\n' : '') +
        '🚚 Dikirim *ARMADA KANTOR*. ' +
        (_ongkirAI > 0
            ? 'Biaya kirim di order ' +
              _rpTeks(_ongkirAI) +
              ' sesuai tarif armada. Kalau beda, tulis angkanya saat ACC, contoh: *ACC ' +
              'ORD-xxx 450000*.'
            : 'Biaya kirim belum ditetapkan' +
              (_belanjaArm >= _batasArm
                  ? ' (belanja mulai ' + _rpTeks(_batasArm) + ' dihitung admin)'
                  : '') +
              ' — tulis saat ACC, contoh: *ACC ' +
              'ORD-xxx 500000*, atau *ACC ' +
              'ORD-xxx gratis*.') +
        (_minArm > 0 && _belanjaArm > 0 && _belanjaArm < _minArm
            ? '\n⚠️ Belanja produk ' +
              _rpTeks(_belanjaArm) +
              ' masih DI BAWAH minimal armada (' +
              _rpTeks(_minArm) +
              ') — mohon dicek sebelum ACC.'
            : '');
}

// === Pengaman potong gaji: hanya untuk nomor yang terdaftar di tabel KARYAWAN ===
let _metodeBayar = String(out.metode_bayar || '');
const _tipeAI =
    isiAtauLama(out.tipe_customer, lama.tipe_customer) || 'BELUM_JELAS';
let _tipeFinal =
    String(_tipeAI).toUpperCase() === 'KARYAWAN' && !src.karyawan_terverifikasi
        ? 'BELUM_JELAS'
        : _tipeAI;
if (/POTONG GAJI/i.test(_metodeBayar) && !src.karyawan_terverifikasi) {
    // PENTING: teks pengganti tidak boleh memuat kata "potong gaji", karena langkah lain mengenali order karyawan dari kata itu
    _metodeBayar =
        'PERLU DICEK - minta bayar lewat gaji, nomor belum terdaftar karyawan';
    _peringatanTotal =
        (_peringatanTotal ? _peringatanTotal + '\n\n' : '') +
        '⚠️ Customer ini minta *potong gaji*, tapi nomornya TIDAK terdaftar di tabel KARYAWAN. Order dicatat sebagai order biasa (menunggu ACC) dan tidak diteruskan ke bu Sarah. Mohon dicek dulu sebelum diproses.';
}

// === Pilihan balasan ditulis di akhir pesan (WhatsApp tidak lagi mendukung tombol) ===
// Hanya ditambahkan kalau pilihannya belum tertulis di teks balasan.
const _pilihanB = Array.isArray(out.pilihan_balasan)
    ? out.pilihan_balasan
          .map(function (x) {
              return String(x).trim();
          })
          .filter(Boolean)
          .slice(0, 3)
    : [];
const _normP = function (s) {
    return String(s)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
};
if (_pilihanB.length >= 2) {
    const _teksN = _normP(teks);
    // pilihan dianggap sudah tertulis kalau salah satu kata pentingnya sudah ada di teks
    const _UMUM = [
        'yang',
        'dulu',
        'saja',
        'untuk',
        'dengan',
        'atau',
        'lain',
        'lagi',
        'mana',
    ];
    const _kataP = function (x) {
        return _normP(x)
            .split(' ')
            .filter(function (k) {
                return k.length >= 4 && _UMUM.indexOf(k) < 0;
            });
    };
    const _ada = function (x) {
        const ks = _kataP(x);
        return ks.length
            ? ks.some(function (k) {
                  return _teksN.indexOf(k) >= 0;
              })
            : true;
    }; // pilihan angka saja (1 pcs, 2 pcs) tidak ditambahkan
    const _sudahAda = _pilihanB.every(_ada);
    if (!_sudahAda)
        teks =
            teks +
            '\n\n👉 Balas: ' +
            _pilihanB
                .map(function (x) {
                    return '*' + x + '*';
                })
                .join(' / ');
}
// Nomor bu Sarah diambil dari CONFIG_TOKO (SARAH_WA), AI cukup menulis [NOMOR_SARAH]
const _noSarah = (function () {
    try {
        const v = String(
            ($('Pesan Gabungan').first().json || {}).sarah_wa || '',
        );
        return v.split('@')[0].replace(/\D/g, '') || '6281252440646';
    } catch (e) {
        return '6281252440646';
    }
})();
teks = teks.replace(/\[NOMOR_SARAH\]/g, _noSarah);

// === Balasan otomatis WA Business / pesan ulang yang sama ===
// Pesan panjang yang ditandai AI sebagai balasan otomatis (misalnya "di luar jam kerja"), atau pesan panjang yang SAMA PERSIS
// dengan pesan sebelumnya dari customer ini dalam 60 menit terakhir, TIDAK dibalas. Hitungan & jadwal follow-up juga tidak direset,
// supaya bot dan balasan otomatis tidak saling membalas dan customer tidak disapa berulang-ulang.
const _normTeks = function (s) {
    return String(s || '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
};
const _teksIni = _normTeks(src.pesan);
const _pesanPanjang = _teksIni.length >= 40;
let _pesanUlangSama = false;
if (_pesanPanjang && _teksIni === _normTeks(lama.pesan_terakhir_customer)) {
    const _mT = String(lama.tgl_interaksi_terakhir || '').match(
        /(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/,
    );
    if (_mT) {
        const _tMs = Date.UTC(+_mT[1], +_mT[2] - 1, +_mT[3], +_mT[4], +_mT[5]);
        if (wib.getTime() - _tMs < 3600000) _pesanUlangSama = true;
    }
}
const _ditandaiOtomatis =
    out.balasan_otomatis === true ||
    String(out.balasan_otomatis || '').toLowerCase() === 'true';
const _balasanOtomatis =
    _pesanPanjang &&
    !out.order_siap &&
    !mintaBerhenti &&
    (_pesanUlangSama || _ditandaiOtomatis);
// === Draft pesanan berjalan: keranjang terakhir yang sedang dibahas (disimpan di CRM, dibaca lagi di chat berikutnya) ===
const _orderIdBaru =
    'ORD-' +
    fmt(wib).replace(/[-: ]/g, '') +
    pad(wib.getUTCSeconds()) +
    '-' +
    String(
        _nomorFinal ||
            src.nomor_wa ||
            String(src.chatId || '').split('@')[0] ||
            '0000',
    ).slice(-4);
let _draftLama = {};
try {
    const _dl = lama.draft_pesanan;
    _draftLama =
        typeof _dl === 'string' && _dl.trim()
            ? JSON.parse(_dl)
            : _dl && typeof _dl === 'object'
              ? _dl
              : {};
} catch (e) {
    _draftLama = {};
}
if (!_draftLama || typeof _draftLama !== 'object' || Array.isArray(_draftLama))
    _draftLama = {};
const _normItem = function (it) {
    const q = Math.max(1, Math.round(Number(it && it.qty) || 1));
    const h = _angka(it && it.harga_satuan);
    return {
        nama: String((it && it.nama) || '')
            .trim()
            .slice(0, 120),
        qty: q,
        harga_satuan: h,
        subtotal: q * h,
    };
};
const _dpAI =
    out.draft_pesanan &&
    typeof out.draft_pesanan === 'object' &&
    !Array.isArray(out.draft_pesanan)
        ? out.draft_pesanan
        : null;
let _draft = Object.assign({ status: 'KOSONG', items: [] }, _draftLama);
let _draftItemsBaru = false,
    _draftBerubah = false;
if (_dpAI && !_balasanOtomatis) {
    const _st = String(_dpAI.status || '')
        .trim()
        .toUpperCase();
    const _itAI = Array.isArray(_dpAI.items)
        ? _dpAI.items
              .map(_normItem)
              .filter(function (it) {
                  return it.nama;
              })
              .slice(0, 30)
        : [];
    if (_itAI.length) {
        _draft.items = _itAI;
        _draftItemsBaru = true;
    } else if (_st === 'KOSONG') _draft.items = [];
    // draft yang sudah jadi order (DICATAT) tidak diturunkan statusnya selama isi pesanannya tidak berubah
    const _sigQ = function (arr) {
        return (Array.isArray(arr) ? arr : [])
            .map(function (it) {
                return (
                    (Math.round(Number(it && it.qty)) || 1) +
                    'x' +
                    _angka(it && it.harga_satuan)
                );
            })
            .sort()
            .join('|');
    };
    const _tetapDicatat =
        String(_draftLama.status || '').toUpperCase() === 'DICATAT' &&
        (!_itAI.length || _sigQ(_itAI) === _sigQ(_draftLama.items));
    if (
        !_tetapDicatat &&
        ['KOSONG', 'DISKUSI', 'MENUNGGU_KONFIRMASI', 'DIKONFIRMASI'].indexOf(
            _st,
        ) >= 0
    )
        _draft.status = _st;
    ['cara_terima', 'tujuan_kirim', 'metode_bayar'].forEach(function (k) {
        const v = String(_dpAI[k] == null ? '' : _dpAI[k]).trim();
        if (v) _draft[k] = v.slice(0, 120);
    });
    const _bg = _angka(_dpAI.budget);
    if (_bg > 0) _draft.budget = _bg;
    _draftBerubah = true;
}
// === Rencana ambil / bayar dari customer (contoh "Senin 5 Okt sore; transfer Senin") — ikut tersimpan di draft & order ===
const _rencanaAI = String(out.rencana_ambil || '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 150);
if (_rencanaAI && !_balasanOtomatis) {
    _draft.rencana_ambil = _rencanaAI;
    _draftBerubah = true;
}
const _rencanaFinal = _rencanaAI || String(_draft.rencana_ambil || '');
const _labelBayar = function (m) {
    const s = String(m || '');
    if (/BAYAR DI TEMPAT/i.test(s)) return 'Bayar di tempat saat ambil';
    if (/POTONG GAJI/i.test(s)) return 'Potong gaji';
    if (/TRANSFER/i.test(s)) return 'Transfer';
    return s.toLowerCase().replace(/^./, function (h) {
        return h.toUpperCase();
    });
};
// === Pengaman pencatatan order: order baru dicatat SETELAH customer menyetujui rekap akhir yang isinya sama ===
// Kalau AI langsung mencatat (tanpa rekap akhir yang disetujui, atau isi pesanan baru saja berubah),
// pencatatan ditahan dan customer dikirimi rekap akhir dari sistem + pertanyaan konfirmasi.
const _sigAngka = function (arr) {
    return (Array.isArray(arr) ? arr : [])
        .map(function (it) {
            return (
                (Math.round(Number(it && it.qty)) || 1) +
                'x' +
                _angka(it && it.harga_satuan)
            );
        })
        .sort()
        .join('|');
};
let _orderDitahan = false;
if (out.order_siap && !_balasanOtomatis) {
    const _stLama = String(_draftLama.status || '').toUpperCase();
    const _berubah = !!(
        _detail.length &&
        Array.isArray(_draftLama.items) &&
        _draftLama.items.length &&
        _sigAngka(_draftLama.items) !== _sigAngka(_detail)
    );
    // draft yang sudah DICATAT dengan isi sama: dibiarkan (pengecek order dobel yang memutuskan, tidak ada rekap ulang)
    if (
        _berubah ||
        (_stLama !== 'MENUNGGU_KONFIRMASI' &&
            !(
                _stLama === 'DICATAT' &&
                _draftLama.items &&
                _draftLama.items.length
            ))
    ) {
        _orderDitahan = true;
        if (_detail.length) _draft.items = _detail;
        _draft.status = 'MENUNGGU_KONFIRMASI';
        if (_kurirAI) {
            _draft.cara_terima = /AMBIL SENDIRI|PICKUP/i.test(_kurirAI)
                ? _kurirAI.slice(0, 60)
                : 'DIKIRIM';
            _draft.kurir = _kurirAI.slice(0, 60);
        }
        if (out.kota && !/AMBIL SENDIRI|PICKUP/i.test(_kurirAI))
            _draft.tujuan_kirim =
                (out.kecamatan ? String(out.kecamatan) + ', ' : '') +
                String(out.kota);
        if (_metodeBayar) _draft.metode_bayar = _metodeBayar.slice(0, 120);
        _draftBerubah = true;
    }
}
const _gantiId =
    out.order_siap && !_orderDitahan
        ? (String(out.ganti_order_id || '')
              .toUpperCase()
              .match(/ORD-[A-Z0-9-]+/) || [''])[0]
        : '';
if (out.order_siap && !_balasanOtomatis && !_orderDitahan) {
    if (_detail.length) _draft.items = _detail;
    _draft.status = 'DICATAT';
    _draft.order_id = _orderIdBaru;
    if (_kurirAI) _draft.kurir = _kurirAI.slice(0, 60);
    if (
        _kurirAI &&
        (!_draft.cara_terima || /AMBIL SENDIRI|PICKUP/i.test(_kurirAI))
    )
        _draft.cara_terima = /AMBIL SENDIRI|PICKUP/i.test(_kurirAI)
            ? _kurirAI.slice(0, 60)
            : 'DIKIRIM';
    if (out.kota && !/AMBIL SENDIRI|PICKUP/i.test(_kurirAI))
        _draft.tujuan_kirim =
            (out.kecamatan ? String(out.kecamatan) + ', ' : '') +
            String(out.kota);
    if (_metodeBayar) _draft.metode_bayar = _metodeBayar.slice(0, 120);
    if (_gantiId) _draft.ganti_dari = _gantiId;
    else delete _draft.ganti_dari;
    _draftBerubah = true;
}
if (_batalId && !_balasanOtomatis && _draft.order_id === _batalId) {
    _draft.status = 'BATAL';
    _draftBerubah = true;
}
_draft.items = (Array.isArray(_draft.items) ? _draft.items : [])
    .map(_normItem)
    .filter(function (it) {
        return it.nama;
    })
    .slice(0, 30);
const _draftTotal = _draft.items.reduce(function (a, it) {
    return a + it.subtotal;
}, 0);
_draft.total_produk = _draftTotal;
_draft.budget = _angka(_draft.budget);
if (_draft.budget > 0) _draft.sisa_budget = _draft.budget - _draftTotal;
else delete _draft.sisa_budget;
if (_draftBerubah) _draft.diperbarui = fmt(wib);
if (_orderDitahan && _draft.items.length) {
    const _baris = _draft.items
        .map(function (it) {
            return (
                '• ' + it.nama + ' (' + it.qty + ') — ' + _rpTeks(it.subtotal)
            );
        })
        .join('\n');
    const _ambil = /AMBIL SENDIRI|PICKUP/i.test(
        _kurirAI + ' ' + (_draft.cara_terima || ''),
    );
    const _ong = _angka(out.order_ongkir);
    let _rekap =
        '📦 *Detail Pesanan*\n' +
        _baris +
        '\n\n💰 *Total Produk:* ' +
        _rpTeks(_draftTotal);
    if (!_ambil && _kurirAI) {
        _rekap +=
            _ong > 0
                ? '\n🚚 *Ongkir ' +
                  _kurirAI +
                  ':* ' +
                  _rpTeks(_ong) +
                  '\n🧾 *Total Bayar:* ' +
                  _rpTeks(_draftTotal + _ong)
                : '\n🚚 *Kurir:* ' + _kurirAI;
        const _almt = [
            out.nama_penerima,
            out.alamat_lengkap,
            out.kelurahan,
            out.kecamatan,
            out.kota,
        ]
            .map(function (x) {
                return String(x || '').trim();
            })
            .filter(Boolean)
            .join(', ');
        if (_almt) _rekap += '\n📍 *Dikirim ke:* ' + _almt;
    } else if (_draft.cara_terima) {
        _rekap +=
            '\n📍 *Cara terima:* ' +
            String(_draft.cara_terima).replace(
                /^AMBIL SENDIRI\s*-\s*/i,
                'ambil sendiri di ',
            );
    }
    if (_metodeBayar && !/PERLU DICEK/i.test(_metodeBayar))
        _rekap += '\n💳 *Pembayaran:* ' + _labelBayar(_metodeBayar);
    if (_rencanaFinal) _rekap += '\n🗓️ *Rencana:* ' + _rencanaFinal;
    if (_ambil && /TRANSFER/i.test(_metodeBayar))
        _rekap +=
            '\n\n💡 Karena diambil langsung, bisa juga bayar di tempat saat ambil kalau lebih praktis — tinggal bilang ya kak.';
    teks =
        'Siap kak, sebelum kami catat, ini rekap akhir pesanannya ya 😊 || ' +
        _rekap +
        ' || Kalau sudah pas, balas *ya* nanti langsung kami catat ya kak 🙏';
}
// jumlah angka Rp di baris-baris "• ..." yang berada tepat di atas baris Total Produk
const _bulletSebelum = function (idx) {
    const lines = teks.slice(0, idx).split('\n');
    let sum = 0,
        nB = 0,
        i = lines.length - 1;
    if (i >= 0 && !/^\s*•/.test(lines[i])) i--; // baris tempat "Total Produk" berada (awal baris berisi emoji)
    for (; i >= 0; i--) {
        const L = lines[i].trim();
        if (!L) {
            if (nB) continue;
            else continue;
        }
        if (!L.startsWith('•')) break;
        const m = L.match(/Rp\s?(\d[\d.,]*)\s*$/);
        if (!m) break;
        sum += _angka(m[1]);
        nB++;
    }
    return { sum: sum, n: nB };
};
// Pengaman angka rekap: "Total Produk" di balasan harus sama dengan hitungan sistem (qty x harga satuan)
if (_draftBerubah) delete _draft.selisih_angka;
if (_draftItemsBaru && _draftTotal > 0) {
    const _reTP = /(Total Produk[:*\s]*)Rp\s?(\d[\d.,]*)/i;
    const _mTP = teks.match(_reTP);
    if (_mTP && _angka(_mTP[2]) !== _draftTotal) {
        const _bb = _bulletSebelum(_mTP.index);
        const _sumBaris = _bb.sum,
            _nBaris = _bb.n;
        if (_nBaris > 0 && _sumBaris === _draftTotal) {
            // rincian per item sudah benar, hanya angka total yang meleset -> dibetulkan otomatis
            teks = teks.replace(_reTP, function (m0, lbl) {
                return lbl + _rpTeks(_draftTotal);
            });
            const _mOng = teks.match(/Ongkir[^\n]*?Rp\s?(\d[\d.,]*)/i);
            if (_mOng && _angka(_mOng[1]) > 0)
                teks = teks.replace(
                    /(Total Bayar[:*\s]*)Rp\s?(\d[\d.,]*)/i,
                    function (m0, lbl) {
                        return lbl + _rpTeks(_draftTotal + _angka(_mOng[1]));
                    },
                );
        } else {
            // tidak bisa dibetulkan otomatis -> diingatkan ke AI di chat berikutnya
            _draft.selisih_angka =
                'Total produk di balasan terakhir ' +
                _rpTeks(_angka(_mTP[2])) +
                ', hitungan sistem ' +
                _rpTeks(_draftTotal);
        }
    }
}
// Rekap lengkap (rincian + Total Produk yang cocok dengan hitungan sistem) sudah ditunjukkan ke customer
// -> draft menunggu persetujuan, supaya jawaban "ya/oke" berikutnya bisa langsung dicatat
if (
    !_balasanOtomatis &&
    _draft.status !== 'DICATAT' &&
    _draft.items.length &&
    _draftTotal > 0 &&
    !_draft.selisih_angka
) {
    const _mTP2 = teks.match(/Total Produk[:*\s]*Rp\s?(\d[\d.,]*)/i);
    if (_mTP2 && _angka(_mTP2[1]) === _draftTotal) {
        const _bb2 = _bulletSebelum(_mTP2.index);
        if (_bb2.n > 0 && _bb2.sum === _draftTotal)
            _draft.status = 'MENUNGGU_KONFIRMASI';
    }
}
if (
    out.order_siap &&
    !_orderDitahan &&
    _draft.budget > 0 &&
    _totalFinal > _draft.budget
) {
    _peringatanTotal =
        (_peringatanTotal ? _peringatanTotal + '\n\n' : '') +
        '💸 Total produk ' +
        _rpTeks(_totalFinal) +
        ' MELEBIHI budget yang disebut customer (' +
        _rpTeks(_draft.budget) +
        '). Mohon dicek sebelum ACC.';
}
// === Bahasa CS manusia: istilah internal yang terlanjur ditulis AI diganti bahasa sehari-hari ===
teks = String(teks)
    .replace(/\b([A-Za-z]{2,})(?:[ \t]+\1\b){2,}/gi, '$1') // kata yang terulang 3x+ ("makin makin makin") dirapikan
    .replace(
        /(?:akan[ \t]+)?(?:ter|di)kirim(?:kan)?[ \t]+(?:secara[ \t]+)?otomatis(?:[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?)?/gi,
        'kami kirim',
    )
    .replace(
        /(?:secara[ \t]+)?otomatis[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?/gi,
        '',
    )
    .replace(/[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?/gi, '')
    .replace(/\btercatat[ \t]+di[ \t]+sistem(?:[ \t]+kami)?/gi, 'kami catat')
    .replace(/\bdi[ \t]+sistem(?:[ \t]+kami)?/gi, 'di catatan kami')
    .replace(/\bsistem kami\b/gi, 'tim kami')
    .replace(/\bdi[ \t]?-?[ \t]?ACC\b/gi, 'dikonfirmasi')
    .replace(/\bmeng[ \t]?-?[ \t]?ACC\b/gi, 'mengonfirmasi')
    .replace(/\bACC\b/g, 'konfirmasi')
    .replace(/\bMENUNGGU_KONFIRMASI\b/g, 'menunggu konfirmasi')
    .replace(/\bDIACC\b/g, 'sudah dikonfirmasi')
    .replace(/[ \t]+(?:secara[ \t]+)?otomatis\b/gi, '')
    .replace(/[ \t]{2,}/g, ' ')
    .replace(/[ \t]+([,.!?])/g, '$1');
// === Nama customer tidak disebut di setiap balasan: kalau balasan sebelumnya sudah menyebut namanya, kali ini cukup "kak" ===
try {
    const _kataNama = String(lama.nama || src.nama_wa || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    const _depan = _kataNama[0] || '';
    if (_depan.length >= 3 && !/^\d+$/.test(_depan)) {
        const _esc = function (x) {
            return x.replace(/[.*+?^(){}$|[\]\\]/g, '\\$&');
        };
        // sudah disebut di balasan sebelumnya -> tidak disebut lagi; kalau belum -> paling banyak sekali di balasan ini
        let _bolehSebut = new RegExp('\\b' + _esc(_depan) + '\\b', 'i').test(
            String(lama.balasan_terakhir_bot || ''),
        )
            ? 0
            : 1;
        const _sisa = _kataNama.slice(1).map(_esc).join('\\s+');
        const _reNama = new RegExp(
            '\\b([Kk]ak)\\s+' +
                _esc(_depan) +
                (_sisa ? '(?:\\s+' + _sisa + ')?' : '') +
                '\\b',
            'g',
        );
        teks = teks.replace(_reNama, function (m0, k, pos, all) {
            if (_bolehSebut > 0) {
                _bolehSebut--;
                return m0;
            }
            const _awalKalimat =
                pos === 0 ||
                /[.!?\n]\s*$/.test(
                    String(all).slice(Math.max(0, pos - 3), pos),
                );
            return _awalKalimat ? k : 'kak';
        });
    }
} catch (e) {}
// === Gelembung chat: AI memisahkan balasan dengan penanda || (maksimal 3 gelembung) ===
let _gelembung = String(teks || '')
    .split(/\s*\|\|\s*/)
    .map(function (x) {
        return x.trim();
    })
    .filter(Boolean);
if (_gelembung.length > 3)
    _gelembung = _gelembung
        .slice(0, 2)
        .concat([_gelembung.slice(2).join('\n\n')]);
if (!_gelembung.length)
    _gelembung = ['Mohon maaf kak, boleh diulang pesannya ya 😊'];
teks = _gelembung.join('\n\n');
const _balasanKirim = _gelembung[_gelembung.length - 1];
const _gelembungAwal = _gelembung.slice(0, -1);
// === Eskalasi terbuka: pertanyaan yang sedang ditanyakan ke supervisor. Ditutup lewat perintah JAWAB / LANJUT. ===
const _parseJ = function (s) {
    try {
        return JSON.parse(String(s || '') || 'null');
    } catch (e) {
        return null;
    }
};
const _eskLama = _parseJ(lama.eskalasi_terbuka),
    _jwLama = _parseJ(lama.jawaban_supervisor);
const _umurEsk = (function () {
    const m = String((_eskLama && _eskLama.waktu) || '').match(
        /(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/,
    );
    return m
        ? (wib.getTime() - Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5])) /
              3600000
        : 9999;
})();
// terbuka = belum dijawab (jawaban supervisor lebih lama dari pertanyaannya) dan belum lewat 72 jam
const _eskTerbuka = !!(
    _eskLama &&
    _eskLama.alasan &&
    _umurEsk <= 72 &&
    !(_jwLama && String(_jwLama.waktu || '') >= String(_eskLama.waktu || ''))
);
const _STOP = {
    customer: 1,
    menanyakan: 1,
    tanya: 1,
    nanya: 1,
    jawaban: 1,
    supervisor: 1,
    menunggu: 1,
    terkait: 1,
    soal: 1,
    minta: 1,
    meminta: 1,
    untuk: 1,
    dengan: 1,
    yang: 1,
    masih: 1,
    belum: 1,
    kami: 1,
    apakah: 1,
    boleh: 1,
    bisa: 1,
    tidak: 1,
    info: 1,
    informasi: 1,
    mengenai: 1,
    perihal: 1,
    progres: 1,
    kabar: 1,
    ketersediaan: 1,
    sudah: 1,
    dari: 1,
    kakak: 1,
};
const _kata = function (s) {
    return String(s || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]/g, ' ')
        .split(/\s+/)
        .filter(function (w) {
            return w.length >= 4 && !_STOP[w];
        });
};
let _eskalasi = String(lama.eskalasi_terbuka || '');
let _eskDuplikat = false;
if (
    !_balasanOtomatis &&
    out.butuh_admin &&
    !_batalId &&
    String(out.alasan_butuh_admin || '').trim()
) {
    const _alasanBaru = String(out.alasan_butuh_admin)
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 300);
    if (_eskTerbuka) {
        // pertanyaan yang sama masih menunggu jawaban supervisor -> jangan kirim notifikasi lagi
        const _a = new Set(_kata(_eskLama.alasan)),
            _b = new Set(_kata(_alasanBaru));
        let _sama = 0;
        _b.forEach(function (w) {
            if (_a.has(w)) _sama++;
        });
        _eskDuplikat = _sama / (Math.min(_a.size, _b.size) || 1) >= 0.5;
    }
    if (!_eskDuplikat)
        _eskalasi = JSON.stringify({
            waktu: fmt(wib),
            alasan: _alasanBaru,
            pesan: String(src.pesan || '')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 300),
        });
}
// BIAYA PACKING (dihitung sistem, bukan AI). Aktif kalau PACKING_PER_KG / PACKING_MINIMAL di CONFIG_TOKO diisi.
// Hanya untuk pesanan yang dikirim ekspedisi dan berongkir; ambil sendiri, kurir motor, armada kantor, karyawan, dan paket gratis ongkir tidak kena.
let _packing = 0;
try {
  const _cfgP = {};
  $('Ambil Config Ongkir').all().forEach(function (it) { _cfgP[(it.json || {}).kunci] = (it.json || {}).nilai; });
  const _pn = function (v) { return Number(String(v == null ? '' : v).replace(/[^0-9]/g, '')) || 0; };
  const _perKg = _pn(_cfgP.PACKING_PER_KG), _minP = _pn(_cfgP.PACKING_MINIMAL), _gratisAtas = _pn(_cfgP.PACKING_GRATIS_DI_ATAS);
  const _ekspedisi = _kurirAI && !/AMBIL SENDIRI|PICKUP|KARYAWAN|KURIR MOTOR|ARMADA KANTOR|POTONG GAJI/i.test(_kurirAI + ' ' + String(out.metode_bayar || ''));
  const _beratP = parseFloat(String(out.berat_kg == null ? '' : out.berat_kg).replace(',', '.')) || 0;
  if ((_perKg || _minP) && _ekspedisi && !/GRATIS ONGKIR/i.test(_kurirAI) && _beratP > 0 && !(_gratisAtas && _totalFinal >= _gratisAtas)) _packing = Math.max(_minP, Math.ceil(_beratP) * _perKg);
} catch (e) { _packing = 0; }

return [
    {
        json: {
            kontak: kontak,
            chatId: src.chatId,
            nomor_wa: _nomorFinal,
            session: src.session,
            label_nomor_bot: (function () {
                try {
                    return String(
                        ($('Pesan Gabungan').first().json || {})
                            ._label_nomor_bot || '',
                    );
                } catch (e) {
                    return '';
                }
            })(),
            balasan: teks,
            balasan_kirim: _balasanKirim,
            gelembung_awal: _gelembungAwal,
            perlu_dibalas: !_balasanOtomatis, // false = balasan otomatis WA Business atau pesan ulang yang sama: tidak dibalas
            balasan_otomatis: _balasanOtomatis,

            // nama yang sudah tersimpan tidak ditimpa nama penerima paket (bisa orang lain);
            // nama penerima hanya dipakai kalau CRM masih kosong atau baru berisi nama profil WhatsApp
            nama:
                lama.nama && String(lama.nama) !== String(src.nama_wa || '')
                    ? lama.nama
                    : String(out.nama_penerima || '').trim() ||
                      lama.nama ||
                      src.nama_wa ||
                      '',
            tipe_customer: _tipeFinal,
            tahap_funnel: _balasanOtomatis
                ? lama.tahap_funnel || _tahapFinal
                : _orderDitahan
                  ? 'SIAP_ORDER'
                  : _tahapFinal,
            skor_prospek:
                isiAtauLama(out.skor_prospek, lama.skor_prospek) || 'COLD',
            minat_produk: isiAtauLama(out.minat_produk, lama.minat_produk),
            lokasi_jualan: isiAtauLama(out.lokasi_jualan, lama.lokasi_jualan),
            estimasi_modal: isiAtauLama(
                out.estimasi_modal,
                lama.estimasi_modal,
            ),
            kota: isiAtauLama(out.kota, lama.kota),
            ringkasan_terakhir: _balasanOtomatis
                ? lama.ringkasan_terakhir || ''
                : isiAtauLama(out.ringkasan_terakhir, lama.ringkasan_terakhir),
            pesan_terakhir_customer: src.pesan,

            jumlah_followup: _balasanOtomatis
                ? Number(lama.jumlah_followup || 0)
                : 0, // reset: customer merespon lagi (kecuali balasan otomatis)
            tgl_interaksi_terakhir: fmt(wib),
            tgl_followup_berikutnya: _balasanOtomatis
                ? String(lama.tgl_followup_berikutnya || '')
                : nextFU
                  ? fmt(nextFU)
                  : _orderDitahan
                    ? fmt(new Date(wib.getTime() + _jamFU * 3600000))
                    : '',
            status_optout: mintaBerhenti ? 'YA' : lama.status_optout || 'TIDAK',
            butuh_admin:
                !_balasanOtomatis &&
                (out.butuh_admin || _batalId) &&
                !_eskDuplikat
                    ? 'YA'
                    : 'TIDAK',
            eskalasi_terbuka: _eskalasi,
            batal_order_id: _batalId,
            catatan: _balasanOtomatis
                ? String(lama.catatan || '')
                : String(
                      out.alasan_butuh_admin ||
                          (_batalId
                              ? 'Customer minta pembatalan ' + _batalId
                              : ''),
                  ),

            order_siap: !!out.order_siap && !_orderDitahan,
            order_id: _orderIdBaru,
            ganti_order_id: _gantiId,
            rencana_ambil: _rencanaFinal,
            draft_pesanan: JSON.stringify(_draft),
            order_items: String(out.order_items || ''),
            order_total: String(_totalFinal || ''),
            peringatan_total: _peringatanTotal,
            order_ongkir: (_ongkirAI && !_ongkirCod) ? String(_ongkirAI) : '',
            order_packing: _packing ? String(_packing) : '',
            nama_penerima: String(out.nama_penerima || ''),
            alamat_lengkap: String(out.alamat_lengkap || ''),
            kecamatan: String(out.kecamatan || ''),
            kode_pos: String(out.kode_pos || ''),
            berat_kg: out.berat_kg != null ? String(out.berat_kg) : '',
            kurir_pilihan: _kurirFinal,
            metode_bayar: _ongkirCod ? 'TRANSFER (ONGKIR COD)' : _metodeBayar,
            no_hp: String(out.no_hp || ''),
            kelurahan: String(out.kelurahan || ''),
            kota_kirim: String(out.kota || ''),
            provinsi: String(out.provinsi || ''),
            kirim_gambar: !!out.kirim_gambar,
            url_gambar: String(out.url_gambar || ''),
            pilihan_balasan: Array.isArray(out.pilihan_balasan)
                ? out.pilihan_balasan.map((x) => String(x)).slice(0, 3)
                : [],
            order_items_detail: _detail,
        },
    },
];
