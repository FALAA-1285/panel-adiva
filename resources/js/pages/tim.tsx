import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PanelStaf } from '@/components/tim/panel-staf';
import type { IsianStaf } from '@/components/tim/panel-staf';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, IconButton } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Select, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { Avatar, Mono, Note, T1, T2 } from '@/components/ui/teks';
import { cn } from '@/lib/utils';
import { keamanan } from '@/routes/tim';
import { keluarkan } from '@/routes/tim/sesi';

type Staf = {
    id: number;
    nama: string;
    email: string;
    inisial: string;
    nomor: string | null;
    peran: string;
    akses: { label: string; nada: Nada };
    terakhirMasuk: string;
    isian: IsianStaf;
    nonaktif: boolean;
    belumDaftar: boolean;
};

type HakAkses = {
    aksi: string;
    label: string;
    peran: Record<string, boolean>;
};

type Sesi = {
    kunci: string;
    jenis: 'web' | 'whatsapp';
    userId: number;
    perangkat: string | null;
    judul: string;
    keterangan: string;
    ini: boolean;
};

type Props = {
    staf: Staf[];
    peran: { kode: string; label: string }[];
    hakAkses: HakAkses[];
    sesi: Sesi[];
    keamanan: {
        kodePerangkatBaru: boolean;
        /** production: always on, cannot be switched off */
        kodePerangkatWajib: boolean;
        keluarOtomatis: number;
        pilihan: number[];
    };
};

/** The open staff panel: a new invitation, or one staff member's form or actions. */
type Terbuka = { id: number | null; bagian: 'isian' | 'tindakan' } | null;

const buatKolom = (
    buka: (id: number, bagian: 'isian' | 'tindakan') => void,
): KolomTabel<Staf>[] => [
    {
        id: 'nama',
        header: 'Nama',
        cell: ({ row }) => (
            <div className="flex items-center gap-2.5">
                <Avatar inisial={row.original.inisial} ukuran={32} />
                <div className="flex flex-col gap-px">
                    <T1 className="font-semibold">{row.original.nama}</T1>
                    <T2>{row.original.email}</T2>
                </div>
            </div>
        ),
    },
    {
        id: 'nomor',
        header: 'Nomor WhatsApp',
        cell: ({ row }) => (
            <Mono className="font-normal text-ink-2">{row.original.nomor}</Mono>
        ),
    },
    {
        id: 'peran',
        header: 'Peran',
        cell: ({ row }) => <T1>{row.original.peran}</T1>,
    },
    {
        id: 'akses',
        header: 'Akses panel',
        cell: ({ row }) => (
            <Status nada={row.original.akses.nada}>
                {row.original.akses.label}
            </Status>
        ),
    },
    {
        id: 'terakhir',
        header: 'Terakhir masuk',
        cell: ({ row }) => <T2>{row.original.terakhirMasuk}</T2>,
    },
    {
        id: 'aksi',
        header: '',
        meta: { kanan: true },
        cell: ({ row }) => (
            <div className="flex gap-1.5">
                <IconButton
                    aria-label={`Ubah ${row.original.nama}`}
                    onClick={() => buka(row.original.id, 'isian')}
                >
                    <Ikon nama="ubah" />
                </IconButton>
                <IconButton
                    aria-label="Aksi lain"
                    onClick={() => buka(row.original.id, 'tindakan')}
                >
                    <Ikon nama="lainnya" />
                </IconButton>
            </div>
        ),
    },
];

export default function Tim({
    staf,
    peran,
    hakAkses,
    sesi,
    keamanan: pengaturan,
}: Props) {
    const [terbuka, setTerbuka] = useState<Terbuka>(null);
    const kolom = useMemo(
        () => buatKolom((id, bagian) => setTerbuka({ id, bagian })),
        [],
    );
    // read from the latest props, so Nonaktifkan / Aktifkan lagi show at once
    const dipilih =
        terbuka?.id != null ? staf.find((s) => s.id === terbuka.id) : null;

    return (
        <AppLayout active="tim">
            <Head title="Tim & akses" />
            <PageHeader
                judul="Tim & akses"
                sub="Siapa yang bisa masuk panel, dan nomor mana yang dianggap admin oleh Adiva."
                aksi={
                    <Button
                        varian="primary"
                        onClick={() =>
                            setTerbuka({ id: null, bagian: 'isian' })
                        }
                    >
                        <Ikon nama="tambah" ukuran={15} tebal={2} />
                        Undang staf
                    </Button>
                }
            />
            <IsiHalaman>
                {terbuka && (terbuka.id === null || dipilih) && (
                    <PanelStaf
                        key={`${terbuka.id ?? 'baru'}-${terbuka.bagian}`}
                        staf={dipilih ?? null}
                        bagian={terbuka.bagian}
                        peran={peran}
                        onTutup={() => setTerbuka(null)}
                    />
                )}
                <Panel
                    judul={`${staf.length} orang`}
                    sub="Nomor WhatsApp di sini dikenali bot sebagai staf: pesannya tidak dijawab Adiva. Semua perintah dijalankan dari panel."
                    isiClassName="p-0"
                >
                    <DataTable
                        kolom={kolom}
                        data={staf}
                        template="minmax(0, 1.3fr) 150px 120px 150px minmax(0, 1.2fr) 80px"
                        idBaris={(s) => String(s.id)}
                    />
                </Panel>
                <div className="grid grid-cols-[minmax(0,1fr)_420px] items-start gap-5 max-lg:grid-cols-1">
                    <Panel
                        judul="Hak akses per peran di panel"
                        sub="Pembatasan ini berlaku saat staf memakai panel"
                    >
                        <div className="grid grid-cols-[minmax(0,1fr)_90px_90px_90px] gap-2 pb-1.5 text-caption font-medium text-muted max-md:grid-cols-[minmax(0,1fr)_64px_64px_64px]">
                            <span>Aksi</span>
                            {peran.map((p) => (
                                <span key={p.kode} className="text-center">
                                    {p.label}
                                </span>
                            ))}
                        </div>
                        {hakAkses.map((baris) => (
                            <div
                                key={baris.aksi}
                                className="grid grid-cols-[minmax(0,1fr)_90px_90px_90px] items-center gap-2 border-b border-line-soft py-[9px] text-body max-md:grid-cols-[minmax(0,1fr)_64px_64px_64px]"
                            >
                                <span>{baris.label}</span>
                                {peran.map((p) => (
                                    <span
                                        key={p.kode}
                                        className="flex justify-center"
                                    >
                                        {baris.peran[p.kode] ? (
                                            <Ikon
                                                nama="centang"
                                                tebal={2.2}
                                                className="text-primary"
                                            />
                                        ) : (
                                            <span className="text-line-strong">
                                                —
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        ))}
                        <Note nada="amber">
                            Perintah yang diketik di WhatsApp (ACC, JEDA, CATAT,
                            …) tidak dijalankan; staf diarahkan ke panel. Jadi
                            hak akses di atas berlaku untuk semua perintah.
                        </Note>
                    </Panel>
                    <Panel
                        judul="Sesi & keamanan"
                        isiClassName="px-[18px] pt-1 pb-4"
                    >
                        <div className="flex flex-col">
                            {sesi.map((s) => (
                                <BarisSesi key={s.kunci} sesi={s} />
                            ))}
                        </div>
                        <Keamanan pengaturan={pengaturan} />
                    </Panel>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function BarisSesi({ sesi }: { sesi: Sesi }) {
    const keluar = () => {
        router.delete(keluarkan.url(sesi.userId), {
            data: { perangkat: sesi.perangkat },
            preserveScroll: true,
        });
    };

    return (
        <div className="flex items-center gap-2.5 border-b border-line-soft py-2.5">
            <span className="text-muted">
                {/* inline on the text baseline, like the <svg> in the source */}
                <Ikon
                    nama={sesi.jenis === 'web' ? 'laptop' : 'ponsel'}
                    ukuran={18}
                    className="inline align-baseline"
                />
            </span>
            <div className="flex grow flex-col gap-px">
                <span className="text-body font-medium">{sesi.judul}</span>
                <span
                    className={cn(
                        'text-caption',
                        sesi.ini ? 'text-green-text' : 'text-muted',
                    )}
                >
                    {sesi.keterangan}
                </span>
            </div>
            {!sesi.ini && (
                <Button varian="link" onClick={keluar}>
                    Keluarkan
                </Button>
            )}
        </div>
    );
}

function Keamanan({ pengaturan }: { pengaturan: Props['keamanan'] }) {
    const [kode, setKode] = useState(pengaturan.kodePerangkatBaru);
    const [jam, setJam] = useState(pengaturan.keluarOtomatis);

    const simpan = (data: Record<string, boolean | number>) =>
        router.patch(keamanan.url(), data, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <div className="flex flex-col gap-3 pt-3.5">
            <div className="flex items-center gap-3">
                <div className="flex grow flex-col gap-0.5">
                    <span className="text-body font-medium">
                        Kode WhatsApp di perangkat baru
                    </span>
                    <span className="text-caption text-muted">
                        Adiva mengirim kode 6 digit saat login dari perangkat
                        yang belum dikenal
                        {pengaturan.kodePerangkatWajib &&
                            ' · wajib menyala di panel produksi'}
                    </span>
                </div>
                <Toggle
                    aria-label="Kode WhatsApp di perangkat baru"
                    disabled={pengaturan.kodePerangkatWajib}
                    title={
                        pengaturan.kodePerangkatWajib
                            ? 'Wajib menyala di panel produksi'
                            : undefined
                    }
                    nyala={kode}
                    onUbah={(nyala) => {
                        setKode(nyala);
                        simpan({ kode_perangkat_baru: nyala });
                    }}
                />
            </div>
            <div className="flex items-center gap-3">
                <div className="flex grow flex-col gap-0.5">
                    <span className="text-body font-medium">
                        Keluar otomatis
                    </span>
                    <span className="text-caption text-muted">
                        Setelah tidak aktif
                    </span>
                </div>
                <Select
                    aria-label="Keluar otomatis"
                    value={jam}
                    onChange={(e) => {
                        const nilai = Number(e.target.value);
                        setJam(nilai);
                        simpan({ keluar_otomatis: nilai });
                    }}
                    className="h-[34px] w-[110px]"
                >
                    {pengaturan.pilihan.map((j) => (
                        <option key={j} value={j}>
                            {j} jam
                        </option>
                    ))}
                </Select>
            </div>
        </div>
    );
}
