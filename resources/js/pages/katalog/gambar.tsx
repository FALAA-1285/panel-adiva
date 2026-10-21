import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import { cn } from '@/lib/utils';
import { file, hapus, ubah, unggah } from '@/routes/katalog/gambar';

type Gambar = {
    id: number;
    judul: string;
    tags: string | null;
    caption: string | null;
    produkId: number | null;
    aktif: boolean;
    adaFile: boolean;
    url: string | null;
    filename: string | null;
    lebar: number | null;
    tinggi: number | null;
    ukuranKb: number | null;
    dikirim: number;
};

type Varian = { judul: string; produkId: number };

type Props = {
    tab: TabKatalog;
    gambar: Gambar[];
    varian: Varian[];
};

const opsi = { preserveScroll: true, preserveState: true };

export default function KatalogGambar({ tab, gambar, varian }: Props) {
    const [pilih, setPilih] = useState(gambar[0]?.id ?? null);
    const terpilih = gambar.find((g) => g.id === pilih) ?? gambar[0];
    const inputFile = useRef<HTMLInputElement>(null);
    // null = new photo from the header button; a number = replace that photo's file
    const [tujuan, setTujuan] = useState<number | null>(null);

    const pilihFile = (id: number | null) => {
        setTujuan(id);
        inputFile.current?.click();
    };

    const unggahFile = (foto: File) => {
        const baru = tujuan === null;

        router.post(
            baru ? unggah.url() : file.url(tujuan),
            { foto },
            {
                ...opsi,
                forceFormData: true,
                onSuccess: (page) => {
                    if (baru) {
                        const semua = page.props.gambar as Gambar[];
                        setPilih(Math.max(...semua.map((g) => g.id)));
                    }
                },
            },
        );
    };

    return (
        <AppLayout active="katalog">
            <Head title="Gambar produk" />
            <input
                ref={inputFile}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                aria-label="Pilih foto"
                className="hidden"
                onChange={(e) => {
                    const foto = e.target.files?.[0];
                    e.target.value = '';

                    if (foto) {
                        unggahFile(foto);
                    }
                }}
            />
            <KatalogHeader
                aktif="gambar"
                tab={tab}
                aksi={
                    <Button varian="primary" onClick={() => pilihFile(null)}>
                        <Ikon nama="unggah" ukuran={15} tebal={2} />
                        Unggah foto
                    </Button>
                }
            />
            <IsiHalaman>
                <div className="grid grid-cols-[minmax(0,1fr)_360px] items-start gap-5 max-xl:grid-cols-[minmax(0,1fr)_300px] max-lg:grid-cols-1">
                    <div className="grid grid-cols-4 gap-3.5 max-xl:grid-cols-3 max-md:grid-cols-2">
                        {gambar.map((g) => (
                            <KartuFoto
                                key={g.id}
                                gambar={g}
                                dipilih={g.id === terpilih?.id}
                                onPilih={() => {
                                    setPilih(g.id);

                                    if (!g.adaFile) {
                                        pilihFile(g.id);
                                    }
                                }}
                            />
                        ))}
                    </div>
                    {terpilih && (
                        <DetailFoto
                            key={terpilih.id}
                            gambar={terpilih}
                            varian={varian}
                            onGanti={() => pilihFile(terpilih.id)}
                        />
                    )}
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function KartuFoto({
    gambar,
    dipilih,
    onPilih,
}: {
    gambar: Gambar;
    dipilih: boolean;
    onPilih: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={dipilih}
            onClick={onPilih}
            className={cn(
                'flex cursor-pointer flex-col gap-2 rounded-card bg-surface p-2 text-left',
                dipilih ? 'border-2 border-primary' : 'border border-line',
            )}
        >
            {gambar.url ? (
                <img
                    src={gambar.url}
                    alt={gambar.judul}
                    className="h-[150px] w-full rounded-chip object-cover"
                />
            ) : gambar.adaFile ? (
                <span className="flex h-[150px] flex-col items-center justify-center gap-1.5 self-stretch rounded-chip bg-line text-muted">
                    <Ikon nama="foto" ukuran={22} />
                    <span className="text-tag">[foto produk]</span>
                </span>
            ) : (
                // content-box as in the source: 150px + the dashed border
                <span className="box-content flex h-[150px] flex-col items-center justify-center gap-1.5 self-stretch rounded-chip border border-dashed border-line-strong text-muted">
                    <Ikon nama="unggah" ukuran={20} />
                    <span className="text-caption font-medium text-primary">
                        Unggah foto
                    </span>
                </span>
            )}
            <span className="flex flex-col gap-[3px] px-1 pb-1">
                <span className="text-body leading-[1.35] font-semibold">
                    {gambar.judul}
                </span>
                <span className="text-caption text-muted">{gambar.tags}</span>
                {gambar.adaFile ? (
                    <span className="text-caption text-muted">
                        Dikirim {gambar.dikirim}× bulan ini
                    </span>
                ) : (
                    <Status nada="amber" className="text-caption">
                        Belum ada foto
                    </Status>
                )}
            </span>
        </button>
    );
}

function DetailFoto({
    gambar,
    varian,
    onGanti,
}: {
    gambar: Gambar;
    varian: Varian[];
    onGanti: () => void;
}) {
    const [tags, setTags] = useState(gambar.tags ?? '');
    const [caption, setCaption] = useState(gambar.caption ?? '');
    const pilihan = varian.some((v) => v.judul === gambar.judul)
        ? varian
        : [{ judul: gambar.judul, produkId: gambar.produkId ?? 0 }, ...varian];

    // No save button in the design: every field saves on its own.
    const simpan = (data: Record<string, string | number | boolean | null>) =>
        router.patch(ubah.url(gambar.id), data, opsi);

    const info = [
        gambar.filename,
        gambar.lebar && gambar.tinggi
            ? `${gambar.lebar}×${gambar.tinggi}`
            : null,
        gambar.ukuranKb ? `${gambar.ukuranKb} KB` : null,
    ].filter(Boolean);

    return (
        <Panel judul="Detail foto">
            <div className="flex flex-col gap-3.5">
                {gambar.url ? (
                    <img
                        src={gambar.url}
                        alt={gambar.judul}
                        className="h-[220px] w-full rounded-control object-cover"
                    />
                ) : (
                    <div className="flex h-[220px] flex-col items-center justify-center gap-1.5 rounded-control bg-line text-muted">
                        <Ikon nama="foto" ukuran={26} />
                        <span className="text-caption">
                            [foto {gambar.judul}]
                        </span>
                    </div>
                )}
                <div className="flex gap-2">
                    <Button ukuran="sm" onClick={onGanti}>
                        <Ikon nama="unggah" ukuran={15} tebal={2} />
                        Ganti foto
                    </Button>
                    <Button
                        ukuran="sm"
                        varian="danger"
                        onClick={() => {
                            if (window.confirm(`Hapus foto ${gambar.judul}?`)) {
                                router.delete(hapus.url(gambar.id), opsi);
                            }
                        }}
                    >
                        Hapus
                    </Button>
                </div>
                <Field label="Produk" htmlFor="produk">
                    <Select
                        id="produk"
                        value={gambar.judul}
                        onChange={(e) => {
                            const v = pilihan.find(
                                (p) => p.judul === e.target.value,
                            );

                            if (v) {
                                simpan({
                                    title: v.judul,
                                    produk_id: v.produkId,
                                });
                            }
                        }}
                    >
                        {pilihan.map((v) => (
                            <option key={v.judul}>{v.judul}</option>
                        ))}
                    </Select>
                </Field>
                <Field
                    label="Kata kunci"
                    htmlFor="tags"
                    hint="Adiva mengirim foto ini bila pertanyaan customer mengandung salah satu kata kunci."
                >
                    <Input
                        id="tags"
                        value={tags}
                        onChange={(e) => setTags(e.target.value)}
                        onBlur={() =>
                            tags !== (gambar.tags ?? '') && simpan({ tags })
                        }
                    />
                </Field>
                <Field label="Keterangan yang ikut terkirim" htmlFor="caption">
                    <Textarea
                        id="caption"
                        rows={3}
                        value={caption}
                        onChange={(e) => setCaption(e.target.value)}
                        onBlur={() =>
                            caption !== (gambar.caption ?? '') &&
                            simpan({ caption })
                        }
                    />
                </Field>
                <div className="flex items-center gap-2.5 border-t border-line-soft pt-3">
                    <span className="grow text-body font-medium">
                        Boleh dikirim Adiva
                    </span>
                    <Toggle
                        aria-label="Boleh dikirim Adiva"
                        nyala={gambar.aktif}
                        onUbah={(aktif) => simpan({ aktif })}
                    />
                </div>
                {info.length > 0 && (
                    <span className="text-caption text-muted">
                        {info.join(' · ')}
                    </span>
                )}
            </div>
        </Panel>
    );
}
