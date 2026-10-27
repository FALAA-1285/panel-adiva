import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Segmented, Select } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { InputCari } from '@/components/ui/input-cari';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { KeyValue, T1, T2 } from '@/components/ui/teks';
import { aktivitas } from '@/routes';
import { ekspor, kembalikan } from '@/routes/aktivitas';

type Kejadian = {
    id: number;
    jam: string;
    oleh: string;
    aksi: string;
    detail: string | null;
    sumber: { label: string; nada: Nada };
    rincian: {
        judul: string;
        sub: string | null;
        oleh: string;
        waktu: string;
        sumber: string;
        kunci: string | null;
        sebelum: string | null;
        sesudah: string | null;
        dampak: string | null;
        kembalikan: string | null;
    };
};

type Hari = { tanggal: string; judul: string; kejadian: Kejadian[] };

type Filter = { q: string; oleh: string; kategori: string; sumber: string };

type Pilihan = { nilai: string; label: string };

type Props = {
    hari: Hari[];
    pilih: number | null;
    filter: Filter;
    orang: Pilihan[];
    kategori: Pilihan[];
};

const sumber = [
    { nilai: '', label: 'Semua' },
    { nilai: 'PANEL', label: 'Panel' },
    { nilai: 'WHATSAPP', label: 'WhatsApp' },
    { nilai: 'ADIVA', label: 'Adiva' },
    { nilai: 'SISTEM', label: 'Sistem' },
];

const kolom: KolomTabel<Kejadian>[] = [
    {
        id: 'waktu',
        header: 'Waktu',
        cell: ({ row }) => <T2>{row.original.jam}</T2>,
    },
    {
        id: 'oleh',
        header: 'Oleh',
        cell: ({ row }) => <T1>{row.original.oleh}</T1>,
    },
    {
        id: 'aksi',
        header: 'Aksi',
        cell: ({ row }) => <T1>{row.original.aksi}</T1>,
    },
    {
        id: 'detail',
        header: 'Detail',
        cell: ({ row }) => (
            <T1 className="font-normal text-ink-3">{row.original.detail}</T1>
        ),
    },
    {
        id: 'sumber',
        header: 'Sumber',
        cell: ({ row }) => (
            <Status nada={row.original.sumber.nada}>
                {row.original.sumber.label}
            </Status>
        ),
    },
];

/** Query string without the empty filters. */
function isiFilter(filter: Filter) {
    return Object.fromEntries(
        Object.entries(filter).filter(([, nilai]) => nilai !== ''),
    );
}

export default function Aktivitas({
    hari,
    pilih,
    filter: awal,
    orang,
    kategori,
}: Props) {
    const [filter, setFilter] = useState(awal);
    const [pilihan, setPilihan] = useState(pilih);
    const jeda = useRef<ReturnType<typeof setTimeout>>(undefined);

    const semua = hari.flatMap((h) => h.kejadian);
    const terpilih =
        semua.find((k) => k.id === pilihan) ??
        semua.find((k) => k.id === pilih) ??
        null;

    const muat = (baru: Filter) =>
        router.get(aktivitas.url(), isiFilter(baru), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const ubah = (bagian: Partial<Filter>) => {
        const baru = { ...filter, ...bagian };
        setFilter(baru);
        muat(baru);
    };

    // typing in the search box waits for a pause before asking the server
    const cari = (q: string) => {
        const baru = { ...filter, q };
        setFilter(baru);
        clearTimeout(jeda.current);
        jeda.current = setTimeout(() => muat(baru), 300);
    };

    return (
        <AppLayout active="aktivitas">
            <Head title="Log aktivitas" />
            <PageHeader
                judul="Log aktivitas"
                sub="Semua perubahan dan aksi penting, dari panel, perintah WhatsApp, maupun Adiva sendiri."
                aksi={
                    <Button
                        onClick={() =>
                            window.location.assign(
                                ekspor.url({ query: isiFilter(filter) }),
                            )
                        }
                    >
                        <Ikon nama="unduh" ukuran={15} tebal={2} />
                        Ekspor CSV
                    </Button>
                }
            />
            <IsiHalaman>
                <div className="flex flex-wrap items-center gap-2.5">
                    <InputCari
                        lebar="w-[280px] max-md:w-full"
                        ukuran="md"
                        aria-label="Cari aktivitas"
                        placeholder="Cari order, customer, atau pengaturan"
                        value={filter.q}
                        onChange={(e) => cari(e.target.value)}
                    />
                    <Select
                        aria-label="Oleh"
                        value={filter.oleh}
                        onChange={(e) => ubah({ oleh: e.target.value })}
                        className="w-[150px] max-md:w-auto max-md:grow"
                    >
                        <option value="">Semua orang</option>
                        {orang.map((o) => (
                            <option key={o.nilai} value={o.nilai}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                    <Select
                        aria-label="Aksi"
                        value={filter.kategori}
                        onChange={(e) => ubah({ kategori: e.target.value })}
                        className="w-[150px] max-md:w-auto max-md:grow"
                    >
                        <option value="">Semua aksi</option>
                        {kategori.map((k) => (
                            <option key={k.nilai} value={k.nilai}>
                                {k.label}
                            </option>
                        ))}
                    </Select>
                    <span className="grow" />
                    <span className="text-caption text-muted">Sumber</span>
                    <Segmented
                        pilihan={sumber}
                        nilai={filter.sumber}
                        onUbah={(nilai) => ubah({ sumber: nilai })}
                        className="flex-wrap"
                    />
                </div>
                <div className="grid grid-cols-[minmax(0,1fr)_340px] items-start gap-5 max-lg:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-5">
                        {hari.map((h) => (
                            <Panel
                                key={h.tanggal}
                                judul={h.judul}
                                aksi={
                                    <span className="text-caption text-muted">
                                        {h.kejadian.length} kejadian
                                    </span>
                                }
                                isiClassName="p-0"
                            >
                                <DataTable
                                    kolom={kolom}
                                    data={h.kejadian}
                                    template="56px 70px 180px minmax(0, 1fr) 100px"
                                    idBaris={(k) => String(k.id)}
                                    terpilih={(k) => k.id === terpilih?.id}
                                    onPilih={(k) => setPilihan(k.id)}
                                />
                            </Panel>
                        ))}
                    </div>
                    {terpilih && (
                        <Rincian
                            kejadian={terpilih}
                            onKembalikan={() => setPilihan(null)}
                        />
                    )}
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function Rincian({
    kejadian,
    onKembalikan,
}: {
    kejadian: Kejadian;
    onKembalikan: () => void;
}) {
    const { rincian } = kejadian;

    return (
        <Panel judul={rincian.judul} sub={rincian.sub}>
            <div className="flex flex-col gap-3.5">
                <div className="grid grid-cols-2 gap-3">
                    <KeyValue label="Oleh">{rincian.oleh}</KeyValue>
                    <KeyValue label="Waktu">{rincian.waktu}</KeyValue>
                    <KeyValue label="Sumber">{rincian.sumber}</KeyValue>
                </div>
                {rincian.sebelum !== null && rincian.sesudah !== null && (
                    <div className="grid grid-cols-2 gap-2">
                        <div className="flex flex-col gap-0.5 rounded-control border border-note-red-line bg-note-red-bg px-3 py-2.5 text-note-red-text">
                            <span className="text-caption">Sebelum</span>
                            <span className="text-panel font-semibold">
                                {rincian.sebelum}
                            </span>
                        </div>
                        <div className="flex flex-col gap-0.5 rounded-control border border-ok-line bg-ok-bg px-3 py-2.5 text-ok-text">
                            <span className="text-caption">Sesudah</span>
                            <span className="text-panel font-semibold">
                                {rincian.sesudah}
                            </span>
                        </div>
                    </div>
                )}
                {rincian.dampak && (
                    <span className="text-caption leading-[1.5] text-muted">
                        {rincian.dampak}
                    </span>
                )}
                {rincian.kembalikan && (
                    <Button
                        className="w-full"
                        onClick={() =>
                            router.post(
                                kembalikan.url(kejadian.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: onKembalikan,
                                },
                            )
                        }
                    >
                        {rincian.kembalikan}
                    </Button>
                )}
            </div>
        </Panel>
    );
}
