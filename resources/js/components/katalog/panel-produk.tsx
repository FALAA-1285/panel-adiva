import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { Panel } from '@/components/ui/panel';
import { impor, simpan, tambah } from '@/routes/katalog/produk';

export type Tier = { min: string; max: string; harga: string };

export type IsianProduk = {
    jenis_produk: string;
    kategori_produk: string;
    brand: string;
    kategori_kemasan: string;
    gramasi: string;
    rasa: string;
    target_pasar: string;
    deskripsi: string;
    satuan: string;
    tier: Tier[];
};

const KOSONG: IsianProduk = {
    jenis_produk: '',
    kategori_produk: '',
    brand: '',
    kategori_kemasan: '',
    gramasi: '',
    rasa: '',
    target_pasar: '',
    deskripsi: '',
    satuan: 'pcs',
    tier: [{ min: '1', max: '', harga: '' }],
};

const ISIAN: [keyof IsianProduk, string][] = [
    ['kategori_produk', 'Kategori'],
    ['brand', 'Brand'],
    ['kategori_kemasan', 'Kemasan'],
    ['gramasi', 'Gramasi'],
    ['rasa', 'Rasa'],
    ['target_pasar', 'Target pasar'],
];

/**
 * Katalog, "Tambah produk" and clicking a row: the PRODUK columns and the tiered
 * prices Adiva quotes. Built from the shared form parts (no design for it).
 */
export function PanelProduk({
    id,
    nama,
    isian,
    onTutup,
}: {
    /** null: a new product. */
    id: number | null;
    nama?: string;
    isian: IsianProduk | null;
    onTutup: () => void;
}) {
    const form = useForm<IsianProduk>(isian ?? KOSONG);
    const galat = Object.values(form.errors)[0];

    const ubahTier = (i: number, isi: Partial<Tier>) =>
        form.setData(
            'tier',
            form.data.tier.map((t, j) => (j === i ? { ...t, ...isi } : t)),
        );

    const simpanProduk = () => {
        const pilihan = { preserveScroll: true, onSuccess: onTutup };

        if (id === null) {
            form.post(tambah.url(), pilihan);
        } else {
            form.put(simpan.url(id), pilihan);
        }
    };

    return (
        <Panel
            judul={id === null ? 'Produk baru' : `Ubah ${nama}`}
            sub="Kolom tabel PRODUK yang dibaca Adiva"
        >
            <div className="flex flex-col gap-3.5">
                <Field label="Nama produk" htmlFor="produk-nama">
                    <Input
                        id="produk-nama"
                        value={form.data.jenis_produk}
                        onChange={(e) =>
                            form.setData('jenis_produk', e.target.value)
                        }
                    />
                </Field>
                <div className="grid grid-cols-3 gap-3 max-md:grid-cols-1">
                    {ISIAN.map(([kunci, label]) => (
                        <Field
                            key={kunci}
                            label={label}
                            htmlFor={`produk-${kunci}`}
                        >
                            <Input
                                id={`produk-${kunci}`}
                                value={form.data[kunci] as string}
                                onChange={(e) =>
                                    form.setData(kunci, e.target.value)
                                }
                            />
                        </Field>
                    ))}
                </div>
                <Field label="Deskripsi" htmlFor="produk-deskripsi">
                    <Textarea
                        id="produk-deskripsi"
                        rows={3}
                        value={form.data.deskripsi}
                        onChange={(e) =>
                            form.setData('deskripsi', e.target.value)
                        }
                    />
                </Field>
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-3">
                        <span className="grow text-body font-semibold text-ink">
                            Harga bertingkat
                        </span>
                        <span className="text-body text-muted">Satuan</span>
                        <Input
                            aria-label="Satuan"
                            value={form.data.satuan}
                            onChange={(e) =>
                                form.setData('satuan', e.target.value)
                            }
                            className="h-8 w-20"
                        />
                    </div>
                    {form.data.tier.map((t, i) => (
                        <div
                            key={i}
                            className="grid grid-cols-[90px_90px_minmax(0,1fr)_28px] items-center gap-2"
                        >
                            <Input
                                aria-label={`Minimal tingkat ${i + 1}`}
                                inputMode="numeric"
                                placeholder="Dari"
                                value={t.min}
                                onChange={(e) =>
                                    ubahTier(i, { min: e.target.value })
                                }
                                className="h-8"
                            />
                            <Input
                                aria-label={`Maksimal tingkat ${i + 1}`}
                                inputMode="numeric"
                                placeholder="Sampai (kosong = ke atas)"
                                value={t.max}
                                onChange={(e) =>
                                    ubahTier(i, { max: e.target.value })
                                }
                                className="h-8"
                            />
                            <Input
                                aria-label={`Harga tingkat ${i + 1}`}
                                placeholder="[harga per tier]"
                                value={t.harga}
                                onChange={(e) =>
                                    ubahTier(i, { harga: e.target.value })
                                }
                                className="h-8"
                            />
                            <button
                                type="button"
                                aria-label={`Hapus tingkat ${i + 1}`}
                                onClick={() =>
                                    form.setData(
                                        'tier',
                                        form.data.tier.filter(
                                            (_, j) => j !== i,
                                        ),
                                    )
                                }
                                className="flex size-7 cursor-pointer items-center justify-center border-none bg-transparent text-muted"
                            >
                                <Ikon nama="tutup" ukuran={15} />
                            </button>
                        </div>
                    ))}
                    <div>
                        <Button
                            varian="link"
                            onClick={() =>
                                form.setData('tier', [
                                    ...form.data.tier,
                                    { min: '', max: '', harga: '' },
                                ])
                            }
                        >
                            <Ikon nama="tambah" ukuran={15} tebal={2} />
                            Tambah tingkat
                        </Button>
                    </div>
                </div>
                {galat && (
                    <span role="alert" className="text-caption text-red-text">
                        {galat}
                    </span>
                )}
                <div className="flex gap-2">
                    <Button
                        varian="primary"
                        aria-disabled={form.processing}
                        onClick={simpanProduk}
                    >
                        Simpan
                    </Button>
                    <Button onClick={onTutup}>Batal</Button>
                </div>
            </div>
        </Panel>
    );
}

/**
 * "Impor CSV": picks a file and shows the result on one line. The page reloads its
 * product list afterwards.
 */
export function useImporCsv(onSelesai: () => void) {
    const pilihFile = useRef<HTMLInputElement>(null);
    const [hasil, setHasil] = useState<{ teks: string; galat: boolean } | null>(
        null,
    );

    const unggah = async (file: File) => {
        const data = new FormData();
        data.append('file', file);
        const token = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );

        try {
            const jawaban = await fetch(impor.url(), {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
            });
            const isi = (await jawaban.json()) as {
                hasil?: string;
                errors?: Record<string, string[]>;
            };

            setHasil(
                jawaban.ok
                    ? { teks: isi.hasil ?? '', galat: false }
                    : {
                          teks:
                              Object.values(isi.errors ?? {})[0]?.[0] ??
                              'Impor gagal.',
                          galat: true,
                      },
            );

            if (jawaban.ok) {
                onSelesai();
            }
        } catch {
            setHasil({
                teks: 'Impor gagal: server tidak menjawab.',
                galat: true,
            });
        }
    };

    const input = (
        <input
            ref={pilihFile}
            type="file"
            accept=".csv,text/csv"
            className="hidden"
            aria-label="File CSV produk"
            onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = '';

                if (file) {
                    void unggah(file);
                }
            }}
        />
    );

    return { input, hasil, buka: () => pilihFile.current?.click() };
}
