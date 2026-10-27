import { Head, router, usePoll } from '@inertiajs/react';
import { Fragment } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { usePintasanBaris } from '@/hooks/use-pintasan-baris';
import { cn } from '@/lib/utils';
import { notifikasi as halaman, pengaturan } from '@/routes';
import { bacaSemua, buka } from '@/routes/notifikasi';

type Notifikasi = {
    id: number;
    judul: string;
    detail: string | null;
    jam: string;
    dibaca: boolean;
    aksi: string | null;
};

type Hari = { tanggal: string; judul: string; notifikasi: Notifikasi[] };

/** Mirrors App\Enums\TabNotifikasi. */
type KodeTab = 'semua' | 'belum' | 'order' | 'butuh-admin' | 'sistem';

type Props = {
    tab: KodeTab;
    jumlah: { kode: KodeTab; label: string; n: number }[];
    hari: Hari[];
    pj: string;
};

// Penerima di WhatsApp: how the workflows route today (static copy from the design).
// The PJ karyawan's name is filled in from the server.
const penerima = (pj: string) => [
    {
        nama: 'Supervisor',
        kunci: 'ADMIN_WA',
        isi: 'Order baru, bukti & lampiran, butuh admin, balasan/AI gagal, invoice PDF gagal, customer dijeda chat lagi, jeda kedaluwarsa, order dibatalkan/ditutup otomatis, bukti belum dicek, nomor bot putus, workflow error, pengingat 09.00, laporan 20.00, rekap arsip Minggu',
    },
    { nama: pj, kunci: 'SARAH_WA', isi: 'Order karyawan (potong gaji)' },
    {
        nama: 'Staf lain',
        kunci: 'ADMIN_STAFF',
        isi: 'Tidak dikirimi notifikasi — cukup lihat di panel. Nomornya tetap boleh mengirim perintah.',
    },
];

export default function HalamanNotifikasi({ tab, jumlah, hari, pj }: Props) {
    // new notices from the workflows come in by themselves (not in the design)
    usePoll(10000, { only: ['jumlah', 'hari', 'panel'] });
    usePintasanBaris();

    return (
        <AppLayout active="none">
            <Head title="Notifikasi" />
            <PageHeader
                judul="Notifikasi"
                sub={`Salinan notifikasi yang dikirim workflow ke WhatsApp supervisor dan ${pj}. Tandai selesai setelah ditangani.`}
                aksi={
                    <Button
                        onClick={() =>
                            router.post(
                                bacaSemua.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Ikon nama="centang" ukuran={15} tebal={2} />
                        Tandai semua dibaca
                    </Button>
                }
                tab={jumlah.map((t) => ({
                    label: t.label,
                    n: t.n,
                    aktif: t.kode === tab,
                    href: halaman.url(
                        t.kode === 'semua' ? {} : { query: { tab: t.kode } },
                    ),
                }))}
            />
            <IsiHalaman>
                <div className="grid grid-cols-[minmax(0,1fr)_340px] items-start gap-5 max-xl:grid-cols-[minmax(0,1fr)_300px] max-lg:grid-cols-1">
                    <Panel isiClassName="p-0">
                        {hari.map((h) => (
                            <Fragment key={h.tanggal}>
                                <div className="border-b border-line bg-subtle px-[18px] py-2.5 text-caption font-semibold text-muted">
                                    {h.judul}
                                </div>
                                {h.notifikasi.map((n) => (
                                    <BarisNotifikasi
                                        key={n.id}
                                        notifikasi={n}
                                    />
                                ))}
                            </Fragment>
                        ))}
                    </Panel>
                    <Panel
                        judul="Penerima di WhatsApp"
                        sub="Sesuai workflow saat ini"
                        isiClassName="px-[18px] pt-1 pb-3.5"
                    >
                        {penerima(pj).map((p) => (
                            <div
                                key={p.kunci}
                                className="flex flex-col gap-[3px] border-b border-line-soft py-2.5"
                            >
                                <span
                                    className="text-body font-semibold"
                                    title={p.kunci}
                                >
                                    {p.nama}
                                </span>
                                <span className="text-caption leading-[1.45] text-ink-3">
                                    {p.isi}
                                </span>
                            </div>
                        ))}
                        <div className="pt-3">
                            <ButtonLink href={pengaturan.url()} varian="link">
                                Ubah nomor di Pengaturan
                            </ButtonLink>
                        </div>
                    </Panel>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function BarisNotifikasi({ notifikasi: n }: { notifikasi: Notifikasi }) {
    return (
        <div
            data-baris
            tabIndex={-1}
            className={cn(
                'grid grid-cols-[8px_minmax(0,1fr)_60px_auto] items-start gap-3.5 border-b border-line-soft px-[18px] py-3.5',
                // phones: the button goes under the text
                'max-md:grid-cols-[8px_minmax(0,1fr)_auto] max-md:gap-x-3 max-md:gap-y-2',
                'outline-none focus-visible:shadow-[inset_3px_0_0_var(--color-primary)]',
                n.dibaca ? 'bg-surface' : 'bg-unread',
            )}
        >
            {/* the blue dot is the only unread cue, so screen readers get it as a label */}
            <span
                role={n.dibaca ? undefined : 'img'}
                aria-label={n.dibaca ? undefined : 'Belum dibaca'}
                aria-hidden={n.dibaca ? true : undefined}
                className={cn(
                    'mt-1.5 size-2 shrink-0 rounded-full',
                    n.dibaca ? 'bg-transparent' : 'bg-primary',
                )}
            />
            <div className="flex min-w-0 flex-col gap-[3px]">
                <span
                    className={cn(
                        'text-body text-ink',
                        n.dibaca ? 'font-medium' : 'font-semibold',
                    )}
                >
                    {n.judul}
                </span>
                <span className="text-body text-ink-3">{n.detail}</span>
            </div>
            <span className="pt-px text-right text-caption text-muted">
                {n.jam}
            </span>
            <div className="flex min-w-[96px] justify-end max-md:col-span-2 max-md:col-start-2 max-md:min-w-0 max-md:justify-start">
                {n.aksi && (
                    <ButtonLink href={buka.url(n.id)} ukuran="sm">
                        {n.aksi}
                    </ButtonLink>
                )}
            </div>
        </div>
    );
}
