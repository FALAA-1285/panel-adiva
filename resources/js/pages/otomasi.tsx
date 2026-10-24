import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink, kelasTombol } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatStrip } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { Note, T1, T2 } from '@/components/ui/teks';
import { cn } from '@/lib/utils';
import { notifikasi, pengaturan } from '@/routes';
import { template as ubahTemplate } from '@/routes/otomasi';

type Workflow = {
    id: number;
    nama: string;
    node: string | null;
    jadwal: string | null;
    jadwalKeterangan: string | null;
    deskripsi: string | null;
    terakhir: { waktu: string; catatan: string | null } | null;
    status: { label: string; nada: Nada };
};

type Template = {
    id: number;
    kode: string;
    judul: string | null;
    aktif: boolean;
    jeda: string;
    tahap: string;
    pesan: string;
};

type PesanSistem = {
    kode: string;
    judul: string;
    nada: 'red' | 'amber';
    waktu: string;
    workflow: string | null;
    teks: string;
    catatan: string;
};

type Props = {
    n8nUrl: string | null;
    ringkasan: { label: string; nilai: string; meta: string | null }[];
    workflow: Workflow[];
    aturan: {
        batasBayar: number;
        batasKonfirmasi: number;
        batasPickup: number;
        jedaFollowup: number;
        malam: string;
        arsipOrder: number;
        arsipCustomer: number;
    };
    template: Template[];
    laporan: { teks: string; jam: string; hari: string } | null;
    pesanSistem: PesanSistem[];
};

const kolom: KolomTabel<Workflow>[] = [
    {
        id: 'workflow',
        header: 'Workflow',
        cell: ({ row }) => (
            <>
                <T1 className="font-semibold">{row.original.nama}</T1>
                <T2>{row.original.jadwalKeterangan || row.original.jadwal || 'Otomasi Adiva'}</T2>
            </>
        ),
    },
    {
        id: 'jadwal',
        header: 'Jadwal',
        cell: ({ row }) => (
            <>
                <T1>{row.original.jadwal}</T1>
                <T2>{row.original.jadwalKeterangan}</T2>
            </>
        ),
    },
    {
        id: 'deskripsi',
        header: 'Yang dikerjakan',
        cell: ({ row }) => (
            <span className="text-body leading-[1.4] text-ink-2">
                {row.original.deskripsi}
            </span>
        ),
    },
    {
        id: 'terakhir',
        header: 'Terakhir jalan',
        cell: ({ row }) =>
            row.original.terakhir && (
                <>
                    <span className="text-body leading-[1.4] font-medium text-ink">
                        {row.original.terakhir.waktu}
                    </span>
                    <T2>{row.original.terakhir.catatan}</T2>
                </>
            ),
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <Status nada={row.original.status.nada}>
                {row.original.status.label}
            </Status>
        ),
    },
];

export default function Otomasi({
    n8nUrl,
    ringkasan,
    workflow,
    aturan,
    template,
    laporan,
    pesanSistem,
}: Props) {
    const [tabAktif, setTabAktif] = useState<
        'semua' | 'workflow' | 'aturan' | 'template' | 'laporan'
    >('semua');

    const langkah = [
        {
            judul: 'Invoice terkirim setelah ACC',
            kunci: 'JAM_PESAN_OTOMATIS',
            isi: `Jam kerja mulai dihitung. Malam (${aturan.malam}) dan hari libur tidak ikut dihitung.`,
        },
        {
            judul: `Jam kerja ke-${aturan.batasBayar - 1} · customer diingatkan`,
            kunci: 'BATAS_BAYAR_JAM − 1',
            isi: 'Sekali saja, dengan total tagihan. Kalau gagal terkirim, tetap ditandai sudah diingatkan supaya tidak dobel.',
        },
        {
            judul: `Jam kerja ke-${aturan.batasBayar} · order dibatalkan`,
            kunci: `BATAS_BAYAR_JAM = ${aturan.batasBayar}`,
            isi: 'Customer dan supervisor dikabari. Customer ditandai Keranjang ditinggal di CRM.',
        },
        {
            judul: `${aturan.jedaFollowup} jam kemudian · sapaan FU-03`,
            kunci: 'JEDA_FOLLOWUP_JAM',
            isi: `Disapa sekali oleh Prospek Ulang Otomatis. Tidak dikirim ${aturan.malam}, pindah ke putaran pagi.`,
        },
    ];

    const tampilWorkflow = tabAktif === 'semua' || tabAktif === 'workflow';
    const tampilLaporan = tabAktif === 'semua' || tabAktif === 'laporan';

    return (
        <AppLayout active="otomasi">
            <Head title="Otomasi" />
            <PageHeader
                judul="Otomasi"
                sub="Sistem otomasi Adiva: jadwal kerja, aturan respon, dan alur pesan ke customer mengikuti Pengaturan Toko."
                tab={[
                    {
                        label: 'Semua',
                        aktif: tabAktif === 'semua',
                        onClick: () => setTabAktif('semua'),
                    },
                    {
                        label: 'Workflow & jadwal',
                        n: workflow.length,
                        aktif: tabAktif === 'workflow',
                        onClick: () => setTabAktif('workflow'),
                    },
                    {
                        label: 'Batas & arsip',
                        aktif: tabAktif === 'aturan',
                        onClick: () => setTabAktif('aturan'),
                    },
                    {
                        label: 'Template sapaan',
                        n: template.length,
                        aktif: tabAktif === 'template',
                        onClick: () => setTabAktif('template'),
                    },
                    {
                        label: 'Laporan & notifikasi',
                        n: pesanSistem.length,
                        aktif: tabAktif === 'laporan',
                        onClick: () => setTabAktif('laporan'),
                    },
                ]}
                aksi={
                    <>
                        <ButtonLink href="/monitoring">
                            <Ikon nama="riwayat" ukuran={15} tebal={2} />
                            Log eksekusi
                        </ButtonLink>
                        <ButtonLink href={pengaturan.url()} varian="primary">
                            <Ikon nama="pengaturan" ukuran={15} tebal={2} />
                            Ubah angka
                        </ButtonLink>
                    </>
                }
            />
            <IsiHalaman>
                <StatStrip
                    stat={ringkasan.map((r) => ({
                        ...r,
                        meta: r.meta ?? undefined,
                    }))}
                />

                {tampilWorkflow && (
                    <Panel
                        judul="Workflow & jadwal"
                        sub="Zona waktu Asia/Jakarta. Semua pesan ke customer dikirim lewat nomor WhatsApp yang ia gunakan; pesan ke supervisor lewat nomor WhatsApp utama."
                        aksi={
                            n8nUrl ? (
                                <a
                                    href={n8nUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className={kelasTombol('secondary', 'sm')}
                                >
                                    <Ikon nama="kanan" ukuran={15} tebal={2} />
                                    Buka n8n
                                </a>
                            ) : (
                                <Button
                                    ukuran="sm"
                                    aria-disabled
                                    title="N8N_EDITOR_URL belum diisi"
                                >
                                    <Ikon nama="kanan" ukuran={15} tebal={2} />
                                    Buka n8n
                                </Button>
                            )
                        }
                        isiClassName="p-0"
                    >
                        <DataTable
                            kolom={kolom}
                            data={workflow}
                            template="210px 200px minmax(0, 1fr) 170px 80px"
                            idBaris={(w) => String(w.id)}
                        />
                    </Panel>
                )}

                {tabAktif === 'semua' && (
                    <div className="grid grid-cols-2 items-start gap-5 max-lg:grid-cols-1">
                        <div className="flex min-w-0 flex-col gap-5">
                            <Panel
                                judul="Batas bayar & tutup order"
                                sub="Workflow Batas Bayar Order · order kirim yang sudah di-ACC"
                                aksi={
                                    <ButtonLink
                                        href={pengaturan.url()}
                                        varian="link"
                                    >
                                        Ubah batas
                                    </ButtonLink>
                                }
                                isiClassName="px-[18px] pt-4 pb-2"
                            >
                                <div className="flex flex-col">
                                    {langkah.map((l, i) => (
                                        <LangkahWaktu
                                            key={l.judul}
                                            no={i + 1}
                                            terakhir={i === langkah.length - 1}
                                            {...l}
                                        />
                                    ))}
                                </div>
                                <div className="mt-4">
                                    <Aturan
                                        judul="Belum di-ACC supervisor"
                                        kunci="BATAS_KONFIRMASI_JAM"
                                    >
                                        Ditutup setelah {aturan.batasKonfirmasi}{' '}
                                        jam kerja. Customer dikabari baik-baik,
                                        supervisor cukup dapat satu rekap.
                                    </Aturan>
                                    <Aturan
                                        judul="Ambil sendiri + transfer"
                                        kunci="BATAS_BAYAR_PICKUP_JAM"
                                    >
                                        Batas {aturan.batasPickup} jam kerja.
                                        Ambil sendiri yang bayar di tempat tidak
                                        punya batas.
                                    </Aturan>
                                    <Aturan
                                        judul="Sudah kirim bukti transfer"
                                        kunci="BUKTI MASUK"
                                    >
                                        Tidak pernah dibatalkan dan tidak
                                        ditagih lagi. Supervisor diingatkan
                                        sekali untuk cek mutasi.
                                    </Aturan>
                                    <Aturan judul="Customer sedang dijeda">
                                        Tidak dikirimi apa pun. Supervisor yang
                                        dikabari untuk menyampaikan manual.
                                    </Aturan>
                                    <Aturan judul="Menjaga nomor WhatsApp">
                                        Jeda 30 detik antar order, maksimal 60
                                        order per putaran.
                                    </Aturan>
                                </div>
                            </Panel>

                            <Panel
                                judul="Arsip data"
                                sub="Workflow Arsip Data · tiap Minggu 01.00 · rekap jumlahnya dikirim ke supervisor"
                                aksi={
                                    <ButtonLink
                                        href={pengaturan.url()}
                                        varian="link"
                                    >
                                        Ubah umur
                                    </ButtonLink>
                                }
                                isiClassName="px-[18px] pt-1.5 pb-2"
                            >
                                <Aturan
                                    judul="Order lama"
                                    kunci="ARSIP_ORDER_HARI"
                                    pertama
                                >
                                    Selesai, ditolak, atau dibatalkan lebih dari{' '}
                                    {aturan.arsipOrder} hari.
                                </Aturan>
                                <Aturan
                                    judul="Customer diam"
                                    kunci="ARSIP_CUSTOMER_HARI"
                                >
                                    Tidak pernah chat lagi lebih dari{' '}
                                    {aturan.arsipCustomer} hari, kecuali masih
                                    punya order berjalan.
                                </Aturan>
                                <Aturan judul="Urutannya">
                                    Disalin utuh ke ARSIP_DATA dulu, baru
                                    dihapus per baris. Kalau penyalinan gagal,
                                    tidak ada yang dihapus.
                                </Aturan>
                            </Panel>
                        </div>

                        <Panel
                            judul="Template sapaan otomatis"
                            sub="Tabel TEMPLATE_FOLLOWUP · dikirim oleh Prospek Ulang Otomatis"
                            aksi={
                                <ButtonLink href="/pelanggan" varian="link">
                                    Riwayat kirim
                                </ButtonLink>
                            }
                            isiClassName="p-0"
                        >
                            {template.map((t, i) => (
                                <KartuTemplate
                                    key={t.id}
                                    template={t}
                                    pertama={i === 0}
                                />
                            ))}
                            <div className="flex flex-col gap-2 border-t border-line-soft px-[18px] pt-3 pb-4">
                                <Note>
                                    Follow-up ke-2 dan ke-3 (FU-01-2 sampai
                                    FU-05-3) tersimpan tapi <b>nonaktif</b>:
                                    tiap customer hanya disapa sekali, lalu
                                    berhenti sampai ia membalas lagi.
                                </Note>
                                <span className="text-caption leading-[1.5] text-muted">
                                    Dilewati: minta berhenti, Tidak minat, sudah
                                    order atau masih punya order berjalan,
                                    sedang dijeda, sudah disapa. Maksimal 40
                                    orang per putaran dengan jeda 60 detik.
                                    Kalau gagal terkirim, dicatat di CRM dan
                                    tidak dicoba ulang.
                                </span>
                            </div>
                        </Panel>
                    </div>
                )}

                {tabAktif === 'aturan' && (
                    <div className="grid grid-cols-2 items-start gap-5 max-lg:grid-cols-1">
                        <Panel
                            judul="Batas bayar & tutup order"
                            sub="Workflow Batas Bayar Order · order kirim yang sudah di-ACC"
                            aksi={
                                <ButtonLink
                                    href={pengaturan.url()}
                                    varian="link"
                                >
                                    Ubah batas
                                </ButtonLink>
                            }
                            isiClassName="px-[18px] pt-4 pb-2"
                        >
                            <div className="flex flex-col">
                                {langkah.map((l, i) => (
                                    <LangkahWaktu
                                        key={l.judul}
                                        no={i + 1}
                                        terakhir={i === langkah.length - 1}
                                        {...l}
                                    />
                                ))}
                            </div>
                            <div className="mt-4">
                                <Aturan
                                    judul="Belum di-ACC supervisor"
                                    kunci="BATAS_KONFIRMASI_JAM"
                                >
                                    Ditutup setelah {aturan.batasKonfirmasi} jam
                                    kerja. Customer dikabari baik-baik,
                                    supervisor cukup dapat satu rekap.
                                </Aturan>
                                <Aturan
                                    judul="Ambil sendiri + transfer"
                                    kunci="BATAS_BAYAR_PICKUP_JAM"
                                >
                                    Batas {aturan.batasPickup} jam kerja. Ambil
                                    sendiri yang bayar di tempat tidak punya
                                    batas.
                                </Aturan>
                                <Aturan
                                    judul="Sudah kirim bukti transfer"
                                    kunci="BUKTI MASUK"
                                >
                                    Tidak pernah dibatalkan dan tidak ditagih
                                    lagi. Supervisor diingatkan sekali untuk cek
                                    mutasi.
                                </Aturan>
                                <Aturan judul="Customer sedang dijeda">
                                    Tidak dikirimi apa pun. Supervisor yang
                                    dikabari untuk menyampaikan manual.
                                </Aturan>
                                <Aturan judul="Menjaga nomor WhatsApp">
                                    Jeda 30 detik antar order, maksimal 60 order
                                    per putaran.
                                </Aturan>
                            </div>
                        </Panel>

                        <Panel
                            judul="Arsip data"
                            sub="Workflow Arsip Data · tiap Minggu 01.00 · rekap jumlahnya dikirim ke supervisor"
                            aksi={
                                <ButtonLink
                                    href={pengaturan.url()}
                                    varian="link"
                                >
                                    Ubah umur
                                </ButtonLink>
                            }
                            isiClassName="px-[18px] pt-1.5 pb-2"
                        >
                            <Aturan
                                judul="Order lama"
                                kunci="ARSIP_ORDER_HARI"
                                pertama
                            >
                                Selesai, ditolak, atau dibatalkan lebih dari{' '}
                                {aturan.arsipOrder} hari.
                            </Aturan>
                            <Aturan
                                judul="Customer diam"
                                kunci="ARSIP_CUSTOMER_HARI"
                            >
                                Tidak pernah chat lagi lebih dari{' '}
                                {aturan.arsipCustomer} hari, kecuali masih punya
                                order berjalan.
                            </Aturan>
                            <Aturan judul="Urutannya">
                                Disalin utuh ke ARSIP_DATA dulu, baru dihapus
                                per baris. Kalau penyalinan gagal, tidak ada
                                yang dihapus.
                            </Aturan>
                        </Panel>
                    </div>
                )}

                {tabAktif === 'template' && (
                    <Panel
                        judul="Template sapaan otomatis"
                        sub="Tabel TEMPLATE_FOLLOWUP · dikirim oleh Prospek Ulang Otomatis"
                        aksi={
                            <ButtonLink href="/pelanggan" varian="link">
                                Riwayat kirim
                            </ButtonLink>
                        }
                        isiClassName="p-0"
                    >
                        {template.map((t, i) => (
                            <KartuTemplate
                                key={t.id}
                                template={t}
                                pertama={i === 0}
                            />
                        ))}
                        <div className="flex flex-col gap-2 border-t border-line-soft px-[18px] pt-3 pb-4">
                            <Note>
                                Follow-up ke-2 dan ke-3 (FU-01-2 sampai FU-05-3)
                                tersimpan tapi <b>nonaktif</b>: tiap customer
                                hanya disapa sekali, lalu berhenti sampai ia
                                membalas lagi.
                            </Note>
                            <span className="text-caption leading-[1.5] text-muted">
                                Dilewati: minta berhenti, Tidak minat, sudah
                                order atau masih punya order berjalan, sedang
                                dijeda, sudah disapa. Maksimal 40 orang per
                                putaran dengan jeda 60 detik. Kalau gagal
                                terkirim, dicatat di CRM dan tidak dicoba ulang.
                            </span>
                        </div>
                    </Panel>
                )}

                {tampilLaporan && (
                    <div className="grid grid-cols-2 items-start gap-5 max-lg:grid-cols-1">
                        <Panel
                            judul="Laporan harian ke supervisor"
                            sub={`Laporan Harian · ${laporan?.jam ?? '20.00'} WIB · contoh kiriman ${laporan?.hari ?? 'kemarin'}`}
                        >
                            {laporan && (
                                <div className="rounded-control bg-wa-bg p-3.5">
                                    <div className="box-content flex max-w-[420px] flex-col rounded-control bg-surface px-3 pt-2.5 pb-1.5 text-caption leading-[1.42] text-ink">
                                        {laporan.teks
                                            .split('\n')
                                            .map((baris, i) => (
                                                <BarisWhatsapp
                                                    key={i}
                                                    baris={baris}
                                                />
                                            ))}
                                        <div className="mt-0.5 text-right text-key text-muted">
                                            {laporan.jam}
                                        </div>
                                    </div>
                                </div>
                            )}
                            <div className="mt-3">
                                <span className="text-caption leading-[1.4] text-muted">
                                    Tetap dikirim walau tidak ada data, supaya
                                    ketahuan botnya hidup. Pembayaran dihitung
                                    dari order yang di-LUNAS-kan, harga produk
                                    tanpa ongkir.
                                </span>
                            </div>
                        </Panel>

                        <Panel
                            judul="Pesan sistem ke supervisor"
                            sub="Dikirim ke ADMIN_WA lewat SESI_UTAMA"
                            aksi={
                                <ButtonLink
                                    href={notifikasi.url()}
                                    varian="link"
                                >
                                    Notifikasi
                                </ButtonLink>
                            }
                            isiClassName="p-0"
                        >
                            {pesanSistem.map((p, i) => (
                                <div
                                    key={p.kode}
                                    className={cn(
                                        'flex flex-col gap-1.5 px-[18px] py-3.5',
                                        i > 0 && 'border-t border-line-soft',
                                    )}
                                >
                                    <div className="flex items-baseline gap-2">
                                        <span className="inline-flex items-center gap-1.5 text-body font-medium whitespace-nowrap text-ink">
                                            <span
                                                className={cn(
                                                    'size-1.5 shrink-0 rounded-full',
                                                    p.nada === 'red'
                                                        ? 'bg-red-dot'
                                                        : 'bg-amber-dot',
                                                )}
                                            />
                                            {p.judul}
                                        </span>
                                        <span className="grow" />
                                        <span className="text-caption whitespace-nowrap text-muted">
                                            {p.waktu}
                                        </span>
                                    </div>
                                    <span className="text-caption text-muted">
                                        {p.workflow}
                                    </span>
                                    <div className="rounded-control border border-line-quiet bg-subtle px-[11px] py-2 text-caption leading-[1.5] text-ink-2">
                                        {p.teks}
                                    </div>
                                    <span className="text-caption leading-[1.45] text-muted">
                                        {p.catatan}
                                    </span>
                                </div>
                            ))}
                        </Panel>
                    </div>
                )}
            </IsiHalaman>
        </AppLayout>
    );
}

/** One step of the payment timeline: numbered circle, line to the next step. */
function LangkahWaktu({
    no,
    judul,
    kunci,
    isi,
    terakhir,
}: {
    no: number;
    judul: string;
    kunci: string;
    isi: string;
    terakhir: boolean;
}) {
    return (
        <div
            className={cn(
                'relative grid grid-cols-[24px_minmax(0,1fr)] gap-3',
                !terakhir && 'pb-4',
            )}
        >
            {!terakhir && (
                <span className="absolute top-[26px] -bottom-1.5 left-[11px] w-0.5 bg-line" />
            )}
            {/* content-box as in the source: 24px + the border */}
            <span className="relative box-content flex size-6 items-center justify-center rounded-full border border-line-strong bg-surface text-caption font-semibold text-ink-2">
                {no}
            </span>
            <div className="flex min-w-0 flex-col gap-0.5">
                <div className="flex flex-wrap items-baseline gap-2">
                    <span className="text-body font-semibold">{judul}</span>
                    <span className="font-mono text-tag whitespace-nowrap text-muted">
                        {kunci}
                    </span>
                </div>
                <span className="text-caption leading-[1.45] text-ink-3">
                    {isi}
                </span>
            </div>
        </div>
    );
}

/** A rule: name and CONFIG_TOKO key on the left, what happens on the right. */
function Aturan({
    judul,
    kunci,
    pertama = false,
    children,
}: {
    judul: string;
    kunci?: string;
    pertama?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-[170px_minmax(0,1fr)] gap-3 py-2.5 max-md:grid-cols-1 max-md:gap-1.5',
                !pertama && 'border-t border-line-soft',
            )}
        >
            <div className="flex flex-col gap-0.5">
                <span className="text-body font-semibold">{judul}</span>
                {kunci && (
                    <span className="font-mono text-key whitespace-nowrap text-muted">
                        {kunci}
                    </span>
                )}
            </div>
            <span className="text-caption leading-[1.45] text-ink-3">
                {children}
            </span>
        </div>
    );
}

function KartuTemplate({
    template: t,
    pertama,
}: {
    template: Template;
    pertama: boolean;
}) {
    const [aktif, setAktif] = useState(t.aktif);
    const [bukaPesan, setBukaPesan] = useState(false);

    return (
        <div
            className={cn(
                'flex flex-col gap-2.5 px-[18px] py-[13px] transition-colors',
                !pertama && 'border-t border-line-soft',
            )}
        >
            <div className="flex items-center gap-2.5">
                <span className="font-mono text-caption font-semibold whitespace-nowrap text-ink">
                    {t.kode}
                </span>
                <span className="grow text-body font-semibold">{t.judul}</span>
                <Toggle
                    aria-label={`Aktifkan ${t.kode}`}
                    nyala={aktif}
                    onUbah={(nyala) => {
                        setAktif(nyala);
                        router.patch(
                            ubahTemplate.url(t.id),
                            { aktif: nyala },
                            { preserveScroll: true, preserveState: true },
                        );
                    }}
                />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 text-caption text-ink-3">
                <div className="flex items-center gap-2">
                    <span>{t.jeda}</span>
                    <span className="text-line-strong">·</span>
                    <span className="font-mono text-tag text-muted">
                        {t.tahap}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={() => setBukaPesan(!bukaPesan)}
                    className="hover:text-primary-hover inline-flex cursor-pointer items-center gap-1 text-tag font-medium text-primary"
                >
                    {bukaPesan ? 'Tutup isi pesan' : 'Lihat isi template'}
                    <Ikon
                        nama="turun"
                        ukuran={12}
                        tebal={2.2}
                        className={cn(
                            'transition-transform duration-150',
                            bukaPesan && 'rotate-180',
                        )}
                    />
                </button>
            </div>
            {bukaPesan ? (
                <div className="animate-in fade-in rounded-control border border-line-quiet bg-subtle px-[11px] py-2 text-caption leading-[1.45] text-ink-2 duration-150">
                    <IsiPesan teks={t.pesan} />
                </div>
            ) : (
                <div
                    onClick={() => setBukaPesan(true)}
                    className="cursor-pointer truncate rounded-control border border-transparent bg-subtle/60 px-2.5 py-1.5 font-mono text-tag text-muted transition-all hover:border-line-quiet hover:bg-subtle"
                    title="Klik untuk membuka pesan lengkap"
                >
                    {t.pesan.replace(/\n/g, ' ').slice(0, 95)}...
                </div>
            )}
        </div>
    );
}

/** Template text with its placeholders ({nama}, {minat_produk}, …) set apart. */
function IsiPesan({ teks }: { teks: string }) {
    return teks.split('\n').map((baris, i) => (
        <Fragment key={i}>
            {i > 0 && <br />}
            {baris.split(/(\{[a-z_]+\})/).map((bagian, j) =>
                /^\{[a-z_]+\}$/.test(bagian) ? (
                    <span
                        key={j}
                        className="font-mono text-tag text-primary-ink"
                    >
                        {bagian}
                    </span>
                ) : (
                    bagian
                ),
            )}
        </Fragment>
    ));
}

/** One line of a WhatsApp message: *bold*, _italic_, or an empty line as a gap. */
function BarisWhatsapp({ baris }: { baris: string }) {
    const tebal = /^\*(.+)\*$/.exec(baris);
    const miring = /^_(.+)_$/.exec(baris);

    if (baris.trim() === '') {
        return <div className="h-1.5" />;
    }

    if (tebal) {
        return <div className="font-semibold">{tebal[1]}</div>;
    }

    if (miring) {
        return <div className="text-ink-2 italic">{miring[1]}</div>;
    }

    return <div>{baris}</div>;
}
