import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { useKonfirmasi } from '@/components/ui/konfirmasi';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';
import { broadcast } from '@/routes';
import { perkiraan as rutePerkiraan, simpan, uji } from '@/routes/broadcast';

type Filter = {
    tipe: string[];
    tahap: string[];
    skor: string[];
    minat: string[];
    kota: string;
    interaksi: string;
    kecualikan_order: boolean;
    kecualikan_baru: boolean;
};

type Isian = {
    /** The draft being edited; saving writes over it. */
    ubah: number | null;
    template: number | null;
    nama: string;
    filter: Filter;
    pesan: string;
    gambar: number | null;
    tombol: string[];
    stop: boolean;
    konteks: boolean;
    mode: 'SEKARANG' | 'JADWAL';
    tanggal: string;
    jam: string;
    kecepatan: string;
    nomor_bot: string;
};

type Opsi = { nilai: string; label: string };

type Props = {
    awal: Isian;
    template: { id: number; nama: string; isian: Isian }[];
    kosong: Isian;
    pilihan: {
        tipe: Opsi[];
        tahap: Opsi[];
        skor: Opsi[];
        minat: string[];
        kota: Opsi[];
        interaksi: Opsi[];
        kecepatan: (Opsi & { detik: number })[];
        nomorBot: Opsi[];
        gambar: { id: number; judul: string; kategori: string | null }[];
    };
    perkiraan: number;
    contoh: {
        id: number;
        nama: string;
        panggilan: string;
        kota: string | null;
        minat: string | null;
    }[];
    kuota: number;
};

const VARIABEL = ['{nama}', '{minat_produk}', '{kota}'];

/** BroadcastNew.dc.html: 1 · Penerima, 2 · Pesan, 3 · Jadwal & kecepatan. */
const kelasBagian =
    'flex flex-col gap-3.5 rounded-card border border-line bg-surface px-5 py-[18px] max-md:px-4';

/** "Estimasi 12 mnt": seconds per message × recipients (the design's script). */
function durasi(jumlah: number, detik: number) {
    const menit = Math.max(1, Math.round((jumlah * detik) / 60));

    return menit >= 60
        ? `${Math.floor(menit / 60)} jam ${menit % 60} mnt`
        : `${menit} mnt`;
}

/** "30 Sep 2026, 09.00". */
function waktuMulai(tanggal: string, jam: string) {
    const [tahun, bulan, hari] = tanggal.split('-').map(Number);
    const namaBulan = new Intl.DateTimeFormat('id-ID', {
        month: 'short',
    }).format(new Date(tahun, bulan - 1, hari));

    return `${hari} ${namaBulan} ${tahun}, ${jam.replace(':', '.')}`;
}

function queryFilter(f: Filter) {
    const q = new URLSearchParams();

    for (const kunci of ['tipe', 'tahap', 'skor', 'minat'] as const) {
        f[kunci].forEach((v) => q.append(`${kunci}[]`, v));
    }

    q.set('kota', f.kota);
    q.set('interaksi', f.interaksi);
    q.set('kecualikan_order', f.kecualikan_order ? '1' : '0');
    q.set('kecualikan_baru', f.kecualikan_baru ? '1' : '0');

    return q.toString();
}

export default function BuatKampanye({
    awal,
    template,
    kosong,
    pilihan,
    perkiraan,
    contoh,
    kuota,
}: Props) {
    const [isian, setIsian] = useState<Isian>(awal);
    const [jumlah, setJumlah] = useState(perkiraan);
    const [untuk, setUntuk] = useState(0);
    const [galat, setGalat] = useState<Record<string, string>>({});
    const pertama = useRef(true);
    const [konfirmasi, tanya] = useKonfirmasi();

    const ubah = (isi: Partial<Isian>) => setIsian({ ...isian, ...isi });
    const ubahFilter = (isi: Partial<Filter>) =>
        setIsian({ ...isian, filter: { ...isian.filter, ...isi } });
    const balik = (kunci: 'tipe' | 'tahap' | 'skor' | 'minat', nilai: string) =>
        ubahFilter({
            [kunci]: isian.filter[kunci].includes(nilai)
                ? isian.filter[kunci].filter((v) => v !== nilai)
                : [...isian.filter[kunci], nilai],
        });

    // "Perkiraan": recount a moment after the filter changes
    const kunciFilter = queryFilter(isian.filter);

    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(() => {
            fetch(`${rutePerkiraan.url()}?${kunciFilter}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((hasil: { jumlah: number }) => setJumlah(hasil.jumlah))
                .catch(() => {});
        }, 250);

        return () => clearTimeout(tunda);
    }, [kunciFilter]);

    const pilihTemplate = (nilai: string) => {
        const t = template.find((x) => String(x.id) === nilai);
        setIsian({
            ...(t ? t.isian : kosong),
            mode: isian.mode,
            tanggal: isian.tanggal,
            jam: isian.jam,
        });
    };

    const kirim = (aksi: 'draf' | 'kirim' | 'jadwal') =>
        router.post(
            simpan.url(),
            { ...isian, aksi },
            { onError: (e) => setGalat(e), preserveScroll: true },
        );

    // sending needs a name, a message and someone to send to; it is confirmed with the count
    const siapKirim =
        isian.nama.trim() !== '' && isian.pesan.trim() !== '' && jumlah > 0;
    const kirimKampanye = async () => {
        const orang = `${jumlah.toLocaleString('id-ID')} orang`;
        const setuju =
            siapKirim &&
            (await tanya(
                terjadwal
                    ? {
                          judul: `Jadwalkan "${isian.nama}"?`,
                          pesan: `Kampanye dikirim ke ${orang} pada jadwalnya.`,
                          ya: 'Jadwalkan',
                      }
                    : {
                          judul: `Kirim ke ${orang} sekarang?`,
                          pesan: `"${isian.nama}" langsung dikirim. Pesan yang sudah terkirim tidak bisa ditarik.`,
                          ya: 'Kirim sekarang',
                      },
            ));

        if (setuju) {
            kirim(terjadwal ? 'jadwal' : 'kirim');
        }
    };

    const kecepatan =
        pilihan.kecepatan.find((k) => k.nilai === isian.kecepatan) ??
        pilihan.kecepatan[0];
    const lama = durasi(jumlah, kecepatan.detik);
    const terjadwal = isian.mode === 'JADWAL';
    const gambar = pilihan.gambar.filter(
        (g) =>
            (g.kategori !== null && isian.filter.minat.includes(g.kategori)) ||
            g.id === isian.gambar,
    );
    const gambarDipilih = pilihan.gambar.find((g) => g.id === isian.gambar);
    const orang = contoh[untuk];
    const pratinjau = orang
        ? isian.pesan
              .split('{nama}')
              .join(orang.panggilan)
              .split('{kota}')
              .join(orang.kota ?? '')
              .split('{minat_produk}')
              .join(orang.minat ?? '')
        : isian.pesan;
    const nilaiTemplate =
        isian.template !== null ? String(isian.template) : 'kosong';

    return (
        <AppLayout active="broadcast">
            <Head title="Buat kampanye" />
            <PageHeader
                judul="Buat kampanye"
                sub={
                    <>
                        <Link
                            href={broadcast.url()}
                            className="font-medium no-underline"
                        >
                            Broadcast
                        </Link>{' '}
                        <span className="text-line-strong">/</span> Kampanye
                        baru
                    </>
                }
            />
            <IsiHalaman className="flex-row gap-5 pb-6 max-xl:flex-col">
                <div className="flex min-w-0 grow flex-col gap-4">
                    <section aria-labelledby="aud" className={kelasBagian}>
                        <JudulBagian nomor={1} id="aud" judul="Penerima" isi>
                            <span className="text-caption text-muted">
                                Perkiraan
                            </span>
                            <span className="text-judul font-semibold text-ink">
                                {jumlah.toLocaleString('id-ID')} orang
                            </span>
                        </JudulBagian>
                        <Galat pesan={galat.filter} />
                        <label className="flex flex-col gap-1.5">
                            <span className="text-caption font-semibold">
                                Nama kampanye
                            </span>
                            <Input
                                className="h-10 rounded-[9px] px-3"
                                value={isian.nama}
                                onChange={(e) => ubah({ nama: e.target.value })}
                            />
                            <Galat pesan={galat.nama} />
                        </label>
                        <div className="grid grid-cols-[124px_minmax(0,1fr)] items-center gap-x-3.5 gap-y-3 max-md:grid-cols-1 max-md:gap-y-2">
                            <Label>Tipe customer</Label>
                            <DaftarChip>
                                {pilihan.tipe.map((p) => (
                                    <Chip
                                        key={p.nilai}
                                        aktif={isian.filter.tipe.includes(
                                            p.nilai,
                                        )}
                                        onClick={() => balik('tipe', p.nilai)}
                                    >
                                        {p.label}
                                    </Chip>
                                ))}
                            </DaftarChip>
                            <Label>Tahap pipeline</Label>
                            <DaftarChip>
                                {pilihan.tahap.map((p) => (
                                    <Chip
                                        key={p.nilai}
                                        aktif={isian.filter.tahap.includes(
                                            p.nilai,
                                        )}
                                        onClick={() => balik('tahap', p.nilai)}
                                    >
                                        {p.label}
                                    </Chip>
                                ))}
                            </DaftarChip>
                            <Label>Skor prospek</Label>
                            <DaftarChip>
                                {pilihan.skor.map((p) => (
                                    <Chip
                                        key={p.nilai}
                                        aktif={isian.filter.skor.includes(
                                            p.nilai,
                                        )}
                                        onClick={() => balik('skor', p.nilai)}
                                    >
                                        {p.label}
                                    </Chip>
                                ))}
                            </DaftarChip>
                            <Label>Minat produk</Label>
                            <DaftarChip>
                                {pilihan.minat.map((m) => (
                                    // spans in the design: 32px plus the border
                                    <Chip
                                        key={m}
                                        aktif={isian.filter.minat.includes(m)}
                                        onClick={() => balik('minat', m)}
                                        className="box-content"
                                    >
                                        {m}
                                    </Chip>
                                ))}
                            </DaftarChip>
                            <Label>Kota &amp; interaksi</Label>
                            <div className="flex gap-2 max-md:flex-wrap">
                                <Select
                                    aria-label="Kota"
                                    className="w-auto grow rounded-[9px] px-2.5 text-body"
                                    value={isian.filter.kota}
                                    onChange={(e) =>
                                        ubahFilter({ kota: e.target.value })
                                    }
                                >
                                    {pilihan.kota.map((p) => (
                                        <option key={p.nilai} value={p.nilai}>
                                            {p.label}
                                        </option>
                                    ))}
                                </Select>
                                <Select
                                    aria-label="Interaksi terakhir"
                                    className="w-auto grow rounded-[9px] px-2.5 text-body"
                                    value={isian.filter.interaksi}
                                    onChange={(e) =>
                                        ubahFilter({
                                            interaksi: e.target.value,
                                        })
                                    }
                                >
                                    {pilihan.interaksi.map((p) => (
                                        <option key={p.nilai} value={p.nilai}>
                                            {p.label}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                        <div className="flex flex-col gap-2 border-t border-line-soft pt-3.5">
                            <Label>Dikecualikan</Label>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-body max-md:grid-cols-1">
                                <Centang
                                    checked
                                    disabled
                                    className="text-ink-3"
                                >
                                    Customer yang opt-out — selalu
                                </Centang>
                                <Centang
                                    checked
                                    disabled
                                    className="text-ink-3"
                                >
                                    Sedang dijeda admin — selalu
                                </Centang>
                                <Centang
                                    checked={isian.filter.kecualikan_order}
                                    onChange={(v) =>
                                        ubahFilter({ kecualikan_order: v })
                                    }
                                >
                                    Punya order yang masih berjalan
                                </Centang>
                                <Centang
                                    checked={isian.filter.kecualikan_baru}
                                    onChange={(v) =>
                                        ubahFilter({ kecualikan_baru: v })
                                    }
                                >
                                    Sudah dapat broadcast 3 hari terakhir
                                </Centang>
                            </div>
                        </div>
                    </section>

                    <section aria-labelledby="psn" className={kelasBagian}>
                        <JudulBagian nomor={2} id="psn" judul="Pesan" isi>
                            <Select
                                aria-label="Template"
                                className="h-9 w-auto rounded-[9px] px-2.5 text-body"
                                value={nilaiTemplate}
                                onChange={(e) => pilihTemplate(e.target.value)}
                            >
                                {template.map((t) => (
                                    <option key={t.id} value={String(t.id)}>
                                        {nilaiTemplate === String(t.id)
                                            ? `Template: ${t.nama}`
                                            : t.nama}
                                    </option>
                                ))}
                                <option value="kosong">
                                    {nilaiTemplate === 'kosong'
                                        ? 'Template: Kosong'
                                        : 'Kosong'}
                                </option>
                            </Select>
                        </JudulBagian>
                        <div className="flex flex-col gap-2">
                            <Textarea
                                aria-label="Isi pesan"
                                rows={6}
                                className="resize-none rounded-card px-3 py-2.5"
                                value={isian.pesan}
                                onChange={(e) =>
                                    ubah({ pesan: e.target.value })
                                }
                            />
                            <Galat pesan={galat.pesan} />
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="text-caption text-ink-3">
                                    Sisipkan variabel:
                                </span>
                                {VARIABEL.map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() =>
                                            ubah({
                                                pesan: `${isian.pesan} ${v}`,
                                            })
                                        }
                                        className="h-7 shrink-0 cursor-pointer rounded-control border border-line bg-subtle px-2.5 font-mono text-caption whitespace-nowrap text-ink"
                                    >
                                        {v}
                                    </button>
                                ))}
                                <span className="grow" />
                                <span className="text-caption text-muted">
                                    {isian.pesan.length} karakter
                                </span>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3.5 max-md:grid-cols-1">
                            <div className="flex flex-col gap-2">
                                <Label>Gambar produk</Label>
                                <div className="flex gap-2 max-xl:flex-wrap">
                                    {[...gambar, null].map((g) => {
                                        const aktif =
                                            (g?.id ?? null) === isian.gambar;

                                        return (
                                            <button
                                                key={g?.id ?? 'tanpa'}
                                                type="button"
                                                aria-pressed={aktif}
                                                onClick={() =>
                                                    ubah({
                                                        gambar: g?.id ?? null,
                                                    })
                                                }
                                                className={cn(
                                                    'flex h-[72px] w-24 cursor-pointer items-center justify-center rounded-control p-1.5 text-ink-2',
                                                    g
                                                        ? 'bg-line-softer'
                                                        : 'bg-surface',
                                                    aktif
                                                        ? 'border-2 border-primary'
                                                        : 'border border-line-strong',
                                                )}
                                            >
                                                <span className="text-center text-tag leading-[1.3] font-medium">
                                                    {g?.judul ?? 'Tanpa gambar'}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label>Tombol balasan (maks. 3)</Label>
                                <TombolBalasan
                                    tombol={isian.tombol}
                                    onUbah={(tombol) => ubah({ tombol })}
                                />
                            </div>
                        </div>
                        <div className="flex flex-col gap-2 border-t border-line-softer pt-3">
                            <Centang
                                checked={isian.stop}
                                onChange={(v) => ubah({ stop: v })}
                                className="text-body"
                            >
                                Tambahkan “Balas STOP bila tidak ingin menerima
                                info lagi”
                            </Centang>
                            <Centang
                                checked={isian.konteks}
                                onChange={(v) => ubah({ konteks: v })}
                                className="text-body"
                            >
                                Balasan customer dijawab Adiva dengan konteks
                                kampanye ini
                            </Centang>
                        </div>
                    </section>

                    <section aria-labelledby="jdw" className={kelasBagian}>
                        <JudulBagian
                            nomor={3}
                            id="jdw"
                            judul="Jadwal & kecepatan"
                        />
                        <div className="grid grid-cols-2 gap-3.5 max-md:grid-cols-1">
                            <div className="flex flex-col gap-2">
                                <Label>Waktu kirim</Label>
                                <div className="flex gap-1.5 max-md:flex-wrap">
                                    {(
                                        [
                                            ['SEKARANG', 'Kirim sekarang'],
                                            ['JADWAL', 'Jadwalkan'],
                                        ] as const
                                    ).map(([nilai, label]) => (
                                        <Chip
                                            key={nilai}
                                            aktif={isian.mode === nilai}
                                            onClick={() =>
                                                ubah({ mode: nilai })
                                            }
                                            className="h-9"
                                        >
                                            {label}
                                        </Chip>
                                    ))}
                                </div>
                                {terjadwal && (
                                    <div className="flex gap-2">
                                        <Input
                                            type="date"
                                            aria-label="Tanggal"
                                            className="w-auto grow rounded-[9px] px-2.5 text-body"
                                            value={isian.tanggal}
                                            onChange={(e) =>
                                                ubah({
                                                    tanggal: e.target.value,
                                                })
                                            }
                                        />
                                        <Input
                                            type="time"
                                            aria-label="Jam"
                                            className="w-[110px] rounded-[9px] px-2.5 text-body"
                                            value={isian.jam}
                                            onChange={(e) =>
                                                ubah({ jam: e.target.value })
                                            }
                                        />
                                    </div>
                                )}
                                <Galat pesan={galat.tanggal ?? galat.jam} />
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label>Kecepatan</Label>
                                <div className="flex gap-1.5 max-md:flex-wrap">
                                    {pilihan.kecepatan.map((k) => (
                                        <Chip
                                            key={k.nilai}
                                            aktif={isian.kecepatan === k.nilai}
                                            onClick={() =>
                                                ubah({ kecepatan: k.nilai })
                                            }
                                            className="h-9"
                                        >
                                            {k.label}
                                        </Chip>
                                    ))}
                                </div>
                                <span className="text-caption text-ink-3">
                                    {`Estimasi ${lama} untuk ${jumlah} penerima · hanya dikirim 08.00–20.00`}
                                </span>
                            </div>
                            <div className="col-span-2 flex items-start gap-3 border-t border-line-soft pt-3 max-md:col-span-1 max-md:flex-wrap">
                                <div className="flex grow flex-col gap-0.5">
                                    <span className="text-caption font-semibold">
                                        Kirim lewat nomor bot
                                    </span>
                                    <span className="text-caption leading-[1.45] text-muted">
                                        Setiap customer dikirimi dari nomor
                                        WhatsApp yang terakhir ia gunakan, sama
                                        seperti balasan dan invoice. Customer
                                        tanpa riwayat dikirim dari nomor
                                        WhatsApp utama.
                                    </span>
                                </div>
                                <Select
                                    aria-label="Nomor bot"
                                    className="h-[34px] w-auto rounded-control px-2 text-caption"
                                    value={isian.nomor_bot}
                                    onChange={(e) =>
                                        ubah({ nomor_bot: e.target.value })
                                    }
                                >
                                    {pilihan.nomorBot.map((p) => (
                                        <option key={p.nilai} value={p.nilai}>
                                            {p.label}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                    </section>
                </div>

                <aside
                    aria-label="Pratinjau"
                    className="flex w-[360px] shrink-0 flex-col gap-3.5 max-xl:grid max-xl:w-auto max-xl:grid-cols-2 max-xl:items-start max-md:grid-cols-1"
                >
                    <div className="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
                        <div className="flex items-center gap-2 border-b border-line-softer px-3.5 py-3">
                            <span className="grow text-body font-semibold">
                                Pratinjau di WhatsApp
                            </span>
                            <Select
                                aria-label="Pratinjau untuk"
                                className="h-8 w-auto rounded-control px-2 text-caption"
                                value={untuk}
                                onChange={(e) =>
                                    setUntuk(Number(e.target.value))
                                }
                            >
                                {contoh.map((c, i) => (
                                    <option key={c.id} value={i}>
                                        Untuk: {c.nama}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="flex min-h-[420px] flex-col gap-2 bg-[#EAE3D8] px-3.5 pt-4 pb-[18px]">
                            <div className="flex items-center gap-2 pb-1.5">
                                <span className="flex size-[30px] items-center justify-center rounded-full bg-brand text-tag font-bold text-white">
                                    BB
                                </span>
                                <span className="text-body font-semibold">
                                    Bang Bana
                                </span>
                            </div>
                            <div className="box-content flex max-w-[290px] flex-col gap-2 self-start rounded-[4px_14px_14px_14px] bg-surface p-2 shadow-[0_1px_1px_rgba(0,0,0,0.06)]">
                                {gambarDipilih && (
                                    <div className="flex h-[150px] flex-col items-center justify-center gap-1.5 rounded-control bg-line text-ink-3">
                                        <Ikon nama="gambar" ukuran={22} />
                                        <span className="text-caption font-medium">
                                            Foto · {gambarDipilih.judul}
                                        </span>
                                    </div>
                                )}
                                <div className="px-1 text-body leading-[1.5] whitespace-pre-line">
                                    {pratinjau}
                                </div>
                                {isian.stop && (
                                    <div className="px-1 text-caption text-muted">
                                        Balas STOP bila tidak ingin menerima
                                        info lagi
                                    </div>
                                )}
                            </div>
                            {isian.tombol.length > 0 && (
                                <div className="flex w-[290px] flex-col gap-1.5 self-start">
                                    {isian.tombol.map((t) => (
                                        <span
                                            key={t}
                                            className="flex h-9 items-center justify-center rounded-control bg-surface text-body font-medium text-primary-ink"
                                        >
                                            {t}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-col gap-2.5 rounded-card border border-line bg-surface px-[18px] py-4">
                        <span className="text-body font-semibold">
                            Ringkasan
                        </span>
                        <BarisRingkasan label="Penerima">
                            {jumlah.toLocaleString('id-ID')} orang
                        </BarisRingkasan>
                        <BarisRingkasan label="Mulai">
                            {terjadwal
                                ? waktuMulai(isian.tanggal, isian.jam)
                                : 'Segera setelah disetujui'}
                        </BarisRingkasan>
                        <BarisRingkasan label="Estimasi durasi">
                            {lama}
                        </BarisRingkasan>
                        <BarisRingkasan label="Kuota harian tersisa">
                            {kuota.toLocaleString('id-ID')}
                        </BarisRingkasan>
                        <div className="border-t border-line-softer pt-2 text-caption leading-[1.45] text-muted">
                            Daftar penerima dikunci saat kampanye mulai.
                            Customer yang tiba-tiba dijeda atau opt-out di
                            tengah jalan otomatis dilewati.
                        </div>
                        <div className="flex flex-col gap-2 border-t border-line-softer pt-3">
                            <Button
                                varian="primary"
                                className="w-full border-0"
                                disabled={!siapKirim}
                                title={
                                    siapKirim
                                        ? undefined
                                        : 'Isi nama, pesan, dan penerima dulu'
                                }
                                onClick={kirimKampanye}
                            >
                                {terjadwal
                                    ? 'Jadwalkan kampanye'
                                    : 'Kirim sekarang'}
                            </Button>
                            <Button
                                className="w-full"
                                onClick={() =>
                                    router.post(
                                        uji.url(),
                                        {
                                            nama: isian.nama,
                                            pesan: isian.pesan,
                                            gambar: isian.gambar,
                                            tombol: isian.tombol,
                                            stop: isian.stop,
                                            nomor_bot: isian.nomor_bot,
                                            // the customer the preview shows
                                            contoh: orang?.id ?? null,
                                        },
                                        {
                                            onError: (e) => setGalat(e),
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <Ikon nama="kirim" ukuran={15} tebal={2} />
                                Kirim uji ke supervisor
                            </Button>
                            <Button
                                varian="ghost"
                                className="w-full"
                                onClick={() => kirim('draf')}
                            >
                                <Ikon nama="simpan" ukuran={15} tebal={2} />
                                Simpan draf
                            </Button>
                        </div>
                    </div>
                </aside>
            </IsiHalaman>
            {konfirmasi}
        </AppLayout>
    );
}

type JudulBagianProps = {
    nomor: number;
    id: string;
    judul: string;
    /** The heading takes the free space when something sits on its right. */
    isi?: boolean;
    children?: ReactNode;
};

function JudulBagian({ nomor, id, judul, isi, children }: JudulBagianProps) {
    return (
        <div className="flex items-center gap-2.5 max-md:flex-wrap">
            <span className="w-3.5 text-panel font-semibold text-faint tabular-nums">
                {nomor}
            </span>
            <h2
                id={id}
                className={cn('m-0 text-judul font-semibold', isi && 'grow')}
            >
                {judul}
            </h2>
            {children}
        </div>
    );
}

function Label({ children }: { children: ReactNode }) {
    return <span className="text-caption font-semibold">{children}</span>;
}

function DaftarChip({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap gap-1.5">{children}</div>;
}

type ChipProps = {
    aktif: boolean;
    onClick: () => void;
    className?: string;
    children: ReactNode;
};

function Chip({ aktif, onClick, className, children }: ChipProps) {
    return (
        <button
            type="button"
            aria-pressed={aktif}
            onClick={onClick}
            className={cn(
                'h-8 cursor-pointer rounded-nav border px-[11px] text-body whitespace-nowrap',
                aktif
                    ? 'border-primary bg-primary-soft font-semibold text-primary-ink'
                    : 'border-line-strong bg-surface font-medium text-ink',
                className,
            )}
        >
            {children}
        </button>
    );
}

type CentangProps = {
    checked: boolean;
    disabled?: boolean;
    onChange?: (nilai: boolean) => void;
    className?: string;
    children: ReactNode;
};

/** The design's checkboxes keep the browser's own margin (3px, 4px on the left). */
function Centang({
    checked,
    disabled,
    onChange,
    className,
    children,
}: CentangProps) {
    return (
        <label className={cn('flex items-center gap-2', className)}>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange?.(e.target.checked)}
                className="m-[3px_3px_3px_4px] size-4 accent-primary"
            />
            {children}
        </label>
    );
}

function BarisRingkasan({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex text-body">
            <span className="grow text-ink-3">{label}</span>
            <strong>{children}</strong>
        </div>
    );
}

function Galat({ pesan }: { pesan?: string }) {
    return pesan ? (
        <span className="text-caption text-red-text">{pesan}</span>
    ) : null;
}

/** WhatsApp allows a short label; SimpanBroadcastRequest checks the same. */
const MAKS_TOMBOL = 3;
const MAKS_PANJANG_TOMBOL = 20;

/**
 * "Tombol balasan (maks. 3)": a chip per button, clicked to remove it, and
 * "+ Tombol" opens a small field in place (Enter adds, Esc cancels).
 */
function TombolBalasan({
    tombol,
    onUbah,
}: {
    tombol: string[];
    onUbah: (tombol: string[]) => void;
}) {
    const [menulis, setMenulis] = useState(false);
    const [teks, setTeks] = useState('');

    const tambah = () => {
        const baru = teks.trim().slice(0, MAKS_PANJANG_TOMBOL);

        if (baru !== '' && !tombol.includes(baru)) {
            onUbah([...tombol, baru]);
        }

        setTeks('');
        setMenulis(false);
    };

    return (
        <div className="flex flex-wrap gap-1.5">
            {tombol.map((t) => (
                <button
                    key={t}
                    type="button"
                    aria-label={`Hapus tombol ${t}`}
                    title="Klik untuk hapus"
                    onClick={() => onUbah(tombol.filter((x) => x !== t))}
                    className="box-content flex h-8 cursor-pointer items-center rounded-nav border border-line-strong px-3 text-body font-medium text-ink"
                >
                    {t}
                </button>
            ))}
            {menulis ? (
                <input
                    autoFocus
                    aria-label="Label tombol baru"
                    maxLength={MAKS_PANJANG_TOMBOL}
                    value={teks}
                    onChange={(e) => setTeks(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            tambah();
                        } else if (e.key === 'Escape') {
                            setTeks('');
                            setMenulis(false);
                        }
                    }}
                    onBlur={tambah}
                    className="box-content h-8 w-[150px] rounded-nav border border-line-strong px-3 text-body text-ink outline-none"
                />
            ) : (
                tombol.length < MAKS_TOMBOL && (
                    <button
                        type="button"
                        onClick={() => setMenulis(true)}
                        className="h-8 shrink-0 cursor-pointer rounded-nav border border-dashed border-line-strong bg-surface px-3 text-caption font-semibold whitespace-nowrap text-ink-3"
                    >
                        + Tombol
                    </button>
                )
            )}
        </div>
    );
}
