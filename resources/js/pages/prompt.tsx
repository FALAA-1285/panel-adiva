import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, kelasTombol } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { cn } from '@/lib/utils';
import { prompt } from '@/routes';
import {
    pulihkan,
    teks as teksPenuh,
    terbitkan,
    uji as ujiDraf,
} from '@/routes/prompt';
import { ubah as ubahBagian } from '@/routes/prompt/bagian';
import { buang } from '@/routes/prompt/draf';

type Baris = {
    jenis: 'sama' | 'tambah' | 'hapus';
    no: number | null;
    teks: string;
};

type Terpilih = {
    no: number;
    judul: string;
    keterangan: string | null;
    /** The section's text, what "Ubah" starts from. */
    teks: string;
    baris: Baris[];
    /** Null without a draft: nothing to compare. */
    tambah: number | null;
    hapus: number | null;
};

type Uji = {
    pertanyaan: string;
    jawaban: string | null;
    butuhAdmin: boolean | null;
    tahap: string | null;
    alasan: string | null;
};

type Versi = {
    id: number;
    versi: number;
    ringkasan: string;
    keterangan: string;
    status: 'DRAF' | 'AKTIF' | 'ARSIP';
    label: string;
    nada: Nada;
};

type Props = {
    aktif: { versi: number; keterangan: string } | null;
    draf: { versi: number; ringkasan: string } | null;
    bagian: { no: number; judul: string; diubah: boolean }[];
    terpilih: Terpilih | null;
    uji: Uji | null;
    model: string;
    riwayat: Versi[];
};

/** What n8n sends along with the prompt (node Agen AI Penjawab). */
const DATA_CHAT = ['konteks_customer', 'nama_wa', 'waktu', 'pesan'];

const opsi = { preserveScroll: true };

export default function Prompt({
    aktif,
    draf,
    bagian,
    terpilih,
    uji,
    model,
    riwayat,
}: Props) {
    return (
        <AppLayout active="prompt">
            <Head title="Prompt Adiva" />
            <PageHeader
                judul="Prompt Adiva"
                sub="Instruksi yang dibaca Adiva sebelum menjawab setiap chat. Ubah di draf, uji, lalu terbitkan."
                aksi={
                    <>
                        {aktif && (
                            <span className="pr-1 text-caption whitespace-nowrap text-muted">
                                Versi aktif v{aktif.versi} · {aktif.keterangan}
                            </span>
                        )}
                        {draf && (
                            <>
                                <Button
                                    varian="ghost"
                                    onClick={() =>
                                        router.delete(buang.url(), opsi)
                                    }
                                >
                                    Buang draf
                                </Button>
                                <Button
                                    varian="primary"
                                    onClick={() =>
                                        router.post(terbitkan.url(), {}, opsi)
                                    }
                                >
                                    Terbitkan v{draf.versi}
                                </Button>
                            </>
                        )}
                    </>
                }
            />
            <IsiHalaman>
                <div className="grid grid-cols-[220px_minmax(0,1fr)_300px] items-start gap-5 max-xl:grid-cols-[200px_minmax(0,1fr)] max-lg:grid-cols-1">
                    <Panel
                        judul={`${bagian.length} bagian`}
                        sub="Titik oranye = diubah di draf"
                        isiClassName="p-2"
                    >
                        {bagian.length === 0 ? (
                            <div className="p-4 text-center text-caption text-muted">
                                Belum ada bagian prompt yang dimuat.
                            </div>
                        ) : (
                            <div className="flex flex-col gap-px">
                                {bagian.map((b) => (
                                    <ItemBagian
                                        key={b.no}
                                        {...b}
                                        terpilih={b.no === terpilih?.no}
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>

                    {terpilih ? (
                        <PanelBagian
                            key={terpilih.no}
                            bagian={terpilih}
                            dibanding={draf ? aktif : null}
                            ringkasanDraf={draf?.ringkasan ?? ''}
                        />
                    ) : (
                        <Panel
                            judul="Pilih Bagian Prompt"
                            sub="Instruksi sistem Adiva"
                        >
                            <div className="flex flex-col items-center justify-center p-12 text-center text-muted">
                                <Ikon
                                    nama="prompt"
                                    ukuran={36}
                                    className="mb-3 text-line-strong"
                                />
                                <p className="m-0 text-body font-medium text-ink">
                                    Belum ada bagian prompt yang dipilih
                                </p>
                                <p className="mt-1 max-w-[360px] text-caption text-muted">
                                    Pilih salah satu bagian dari daftar di
                                    sebelah kiri untuk melihat isi instruksi
                                    atau menguji perubahan draf.
                                </p>
                            </div>
                        </Panel>
                    )}

                    <div className="flex flex-col gap-5 max-xl:col-span-2 max-xl:grid max-xl:grid-cols-2 max-xl:items-start max-lg:col-span-1 max-md:grid-cols-1">
                        <UjiDraf uji={uji} model={model} draf={draf} />
                        <RiwayatVersi riwayat={riwayat} />
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function ItemBagian({
    no,
    judul,
    diubah,
    terpilih,
}: {
    no: number;
    judul: string;
    diubah: boolean;
    terpilih: boolean;
}) {
    return (
        <Link
            href={prompt.url({ query: { bagian: no } })}
            preserveScroll
            aria-current={terpilih ? 'true' : undefined}
            className={cn(
                'flex h-8 items-center gap-2 rounded-chip px-2.5 text-body no-underline',
                terpilih
                    ? 'bg-nav-active font-semibold text-primary-ink'
                    : 'font-medium text-ink-2',
            )}
        >
            <span
                className={cn(
                    'w-[18px] text-caption tabular-nums',
                    terpilih ? 'text-primary-ink' : 'text-faint',
                )}
            >
                {no}
            </span>
            <span className="grow">{judul}</span>
            {diubah && (
                <span
                    title="Diubah di draf"
                    className="size-1.5 shrink-0 rounded-full bg-amber-dot"
                />
            )}
        </Link>
    );
}

const kelasBaris: Record<Baris['jenis'], string> = {
    sama: '',
    tambah: 'bg-ok-bg',
    hapus: 'bg-note-red-bg',
};

const tanda: Record<Baris['jenis'], { teks: string; kelas: string }> = {
    sama: { teks: '', kelas: 'text-line-strong' },
    tambah: { teks: '+', kelas: 'text-green-text' },
    hapus: { teks: '-', kelas: 'text-red-text' },
};

function PanelBagian({
    bagian,
    dibanding,
    ringkasanDraf,
}: {
    bagian: Terpilih;
    dibanding: { versi: number } | null;
    ringkasanDraf: string;
}) {
    const [mengubah, setMengubah] = useState(false);
    // "Ubah": the section's new text goes into the draft (a new draft when there is none)
    const form = useForm({ teks: bagian.teks, ringkasan: ringkasanDraf });

    const simpan = () =>
        form.put(ubahBagian.url(bagian.no), {
            preserveScroll: true,
            onSuccess: () => setMengubah(false),
        });

    return (
        <Panel
            judul={`${bagian.no} · ${bagian.judul}`}
            sub={[
                'Diambil dari prompt sistem node Agen AI Penjawab',
                bagian.keterangan,
            ]
                .filter(Boolean)
                .join(' · ')}
            aksi={
                <div className="flex items-center gap-3">
                    {!mengubah && (
                        <Button varian="link" onClick={() => setMengubah(true)}>
                            Ubah
                        </Button>
                    )}
                    <a
                        href={teksPenuh.url()}
                        target="_blank"
                        rel="noreferrer"
                        className={kelasTombol('link', 'md')}
                    >
                        Teks penuh
                    </a>
                </div>
            }
        >
            {mengubah ? (
                <div className="flex flex-col gap-3">
                    <Textarea
                        aria-label={`Teks bagian ${bagian.no}`}
                        rows={18}
                        className="font-mono text-caption leading-[1.7]"
                        value={form.data.teks}
                        onChange={(e) => form.setData('teks', e.target.value)}
                    />
                    <Field label="Ringkasan perubahan" htmlFor="ringkasan-draf">
                        <Input
                            id="ringkasan-draf"
                            placeholder={`Ubah bagian ${bagian.no}. ${bagian.judul}`}
                            value={form.data.ringkasan}
                            onChange={(e) =>
                                form.setData('ringkasan', e.target.value)
                            }
                        />
                    </Field>
                    {(form.errors.teks || form.errors.ringkasan) && (
                        <span
                            role="alert"
                            className="text-caption text-red-text"
                        >
                            {form.errors.teks ?? form.errors.ringkasan}
                        </span>
                    )}
                    <div className="flex gap-2">
                        <Button
                            varian="primary"
                            aria-disabled={form.processing}
                            onClick={simpan}
                        >
                            Simpan ke draf
                        </Button>
                        <Button
                            onClick={() => {
                                form.reset();
                                setMengubah(false);
                            }}
                        >
                            Batal
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="rounded-control border border-line bg-surface py-2.5 pr-3 pl-0 font-mono text-caption leading-[1.8]">
                    {bagian.baris.map((b, i) => (
                        <div
                            key={i}
                            className={cn(
                                'grid min-h-[22px] grid-cols-[36px_16px_minmax(0,1fr)] items-center',
                                kelasBaris[b.jenis],
                            )}
                        >
                            <span className="pr-2 text-right text-faint">
                                {b.no}
                            </span>
                            <span className={tanda[b.jenis].kelas}>
                                {tanda[b.jenis].teks}
                            </span>
                            <span
                                className={cn(
                                    'whitespace-pre-wrap',
                                    b.jenis === 'hapus'
                                        ? 'text-note-red-text line-through decoration-[#FCA5A5]'
                                        : 'text-ink',
                                )}
                            >
                                {/* an empty line keeps its height, as in the design */}
                                {b.teks || '​'}
                            </span>
                        </div>
                    ))}
                </div>
            )}
            <div className="flex flex-col gap-1.5 pt-3 text-caption text-muted">
                {dibanding &&
                    bagian.tambah !== null &&
                    bagian.hapus !== null && (
                        <div className="flex gap-2.5">
                            <span className="whitespace-nowrap text-green-text">
                                +{bagian.tambah} baris
                            </span>
                            <span className="whitespace-nowrap text-red-text">
                                −{bagian.hapus} baris
                            </span>
                            <span>
                                dibanding versi aktif v{dibanding.versi}
                            </span>
                        </div>
                    )}
                <div>
                    Data yang ikut dikirim tiap chat:{' '}
                    {DATA_CHAT.map((d, i) => (
                        <span key={d}>
                            {i > 0 && ' · '}
                            <Kode>{d}</Kode>
                        </span>
                    ))}
                </div>
            </div>
        </Panel>
    );
}

function Kode({ children }: { children: ReactNode }) {
    return (
        <span className="font-mono text-caption font-normal whitespace-nowrap text-primary-ink">
            {children}
        </span>
    );
}

function UjiDraf({
    uji,
    model,
    draf,
}: {
    uji: Uji | null;
    model: string;
    draf: { versi: number } | null;
}) {
    const [pesan, setPesan] = useState('');
    const [menunggu, setMenunggu] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);

    // answered by n8n with the draft prompt, nothing goes to WhatsApp
    const kirim = () => {
        if (menunggu || pesan.trim() === '') {
            return;
        }

        router.post(
            ujiDraf.url(),
            { pertanyaan: pesan },
            {
                preserveScroll: true,
                onStart: () => setMenunggu(true),
                onFinish: () => setMenunggu(false),
                onSuccess: () => {
                    setGalat(null);
                    setPesan('');
                },
                onError: (e) => setGalat(e.pertanyaan ?? null),
            },
        );
    };

    return (
        <Panel
            judul="Uji draf"
            sub={[model, 'tidak terkirim ke WhatsApp']
                .filter(Boolean)
                .join(' · ')}
        >
            {uji && (
                <>
                    <div className="flex flex-col gap-2.5 rounded-control border border-line bg-subtle p-3">
                        <div className="box-content max-w-[240px] self-start rounded-[12px_12px_12px_4px] border border-line bg-surface px-2.5 py-2 text-body leading-[1.45]">
                            {uji.pertanyaan}
                        </div>
                        {uji.jawaban && (
                            <div className="flex flex-col items-end gap-[3px] self-end">
                                <span className="text-tag font-semibold text-primary-ink">
                                    Adiva{draf && ` · draf v${draf.versi}`}
                                </span>
                                <div className="box-content max-w-[250px] rounded-[12px_12px_4px_12px] border border-[#DCE6FB] bg-[#EEF3FE] px-2.5 py-2 text-body leading-[1.45]">
                                    {uji.jawaban}
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="flex flex-col gap-1.5 pt-3 text-caption">
                        {uji.butuhAdmin !== null && (
                            <HasilUji label="Butuh admin">
                                {uji.butuhAdmin ? (
                                    <Status nada="amber">
                                        Ya · supervisor dikabari
                                    </Status>
                                ) : (
                                    <span>Tidak</span>
                                )}
                            </HasilUji>
                        )}
                        {uji.tahap && (
                            <HasilUji label="Tahap">
                                <span>{uji.tahap}</span>
                            </HasilUji>
                        )}
                        {uji.alasan && (
                            <HasilUji label="Alasan">
                                <span className="text-ink-2">{uji.alasan}</span>
                            </HasilUji>
                        )}
                    </div>
                </>
            )}
            <div className={cn('flex gap-2', uji && 'pt-3')}>
                <Input
                    type="text"
                    aria-label="Pesan uji"
                    placeholder="Tulis pesan uji…"
                    className="h-[34px]"
                    value={pesan}
                    onChange={(e) => setPesan(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && kirim()}
                />
                <Button
                    ukuran="sm"
                    aria-disabled={menunggu || !draf}
                    onClick={kirim}
                >
                    Kirim
                </Button>
            </div>
            {galat && (
                <span
                    role="alert"
                    className="block pt-2 text-caption text-red-text"
                >
                    {galat}
                </span>
            )}
        </Panel>
    );
}

function HasilUji({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex">
            <span className="grow text-muted">{label}</span>
            {children}
        </div>
    );
}

function RiwayatVersi({ riwayat }: { riwayat: Versi[] }) {
    return (
        <Panel judul="Riwayat versi" isiClassName="px-[18px] pt-0.5 pb-1.5">
            {riwayat.map((v) => (
                <div
                    key={v.id}
                    className="grid grid-cols-[36px_minmax(0,1fr)_auto] items-start gap-2.5 border-b border-line-soft py-2.5"
                >
                    <span className="font-mono text-caption font-semibold whitespace-nowrap text-ink">
                        v{v.versi}
                    </span>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-body">{v.ringkasan}</span>
                        <span className="text-caption text-muted">
                            {v.keterangan}
                        </span>
                    </div>
                    <div className="flex flex-col items-end gap-1">
                        {v.status === 'ARSIP' ? (
                            <Button
                                varian="link"
                                onClick={() =>
                                    router.post(pulihkan.url(v.id), {}, opsi)
                                }
                            >
                                Pulihkan
                            </Button>
                        ) : (
                            <Status nada={v.nada} className="text-caption">
                                {v.label}
                            </Status>
                        )}
                    </div>
                </div>
            ))}
        </Panel>
    );
}
