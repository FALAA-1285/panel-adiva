import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';
import { lanjut, simpan as simpanJeda } from '@/routes/jeda';
import { penilaian, tangani } from '@/routes/pelanggan';
import { resetMemori } from '@/routes/percakapan';
import { catat } from '@/routes/pesanan';

export type IsianPenilaian = {
    tipe_customer: string;
    tahap_funnel: string;
    skor_prospek: string;
    kota: string;
    minat_produk: string;
    lokasi_jualan: string;
    estimasi_modal: string;
    butuh_admin: boolean;
    alasan_butuh_admin: string;
};

type Opsi = { nilai: string; label: string };

const SKOR = ['HOT', 'WARM', 'COLD'];

/**
 * "Penilaian Adiva", Ubah: what Adiva concluded about the customer, corrected by an
 * admin. A different stage counts as a manual move, like on Pipeline.
 */
export function UbahPenilaian({
    id,
    isian,
    pilihan,
    onTutup,
}: {
    id: number;
    isian: IsianPenilaian;
    pilihan: { tipe: Opsi[]; tahap: Opsi[] };
    onTutup: () => void;
}) {
    const form = useForm<IsianPenilaian>(isian);
    const galat = Object.values(form.errors)[0];
    const teks: [keyof IsianPenilaian, string][] = [
        ['kota', 'Kota'],
        ['minat_produk', 'Minat produk'],
        ['lokasi_jualan', 'Lokasi jualan'],
        ['estimasi_modal', 'Estimasi modal'],
    ];

    return (
        <div className="flex flex-col gap-3">
            <div className="grid grid-cols-3 gap-2.5">
                <Field label="Tipe" htmlFor="penilaian-tipe">
                    <Select
                        id="penilaian-tipe"
                        value={form.data.tipe_customer}
                        onChange={(e) =>
                            form.setData('tipe_customer', e.target.value)
                        }
                    >
                        {pilihan.tipe.map((o) => (
                            <option key={o.nilai} value={o.nilai}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Tahap" htmlFor="penilaian-tahap">
                    <Select
                        id="penilaian-tahap"
                        value={form.data.tahap_funnel}
                        onChange={(e) =>
                            form.setData('tahap_funnel', e.target.value)
                        }
                    >
                        {pilihan.tahap.map((o) => (
                            <option key={o.nilai} value={o.nilai}>
                                {o.label}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Skor prospek" htmlFor="penilaian-skor">
                    <Select
                        id="penilaian-skor"
                        value={form.data.skor_prospek}
                        onChange={(e) =>
                            form.setData('skor_prospek', e.target.value)
                        }
                    >
                        {SKOR.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </Select>
                </Field>
            </div>
            <div className="grid grid-cols-2 gap-2.5">
                {teks.map(([kunci, label]) => (
                    <Field
                        key={kunci}
                        label={label}
                        htmlFor={`penilaian-${kunci}`}
                    >
                        <Input
                            id={`penilaian-${kunci}`}
                            inputMode={
                                kunci === 'estimasi_modal'
                                    ? 'numeric'
                                    : undefined
                            }
                            value={form.data[kunci] as string}
                            onChange={(e) =>
                                form.setData(kunci, e.target.value)
                            }
                        />
                    </Field>
                ))}
            </div>
            <div className="flex items-center gap-2">
                <Toggle
                    aria-label="Butuh admin"
                    nyala={form.data.butuh_admin}
                    onUbah={(nyala) => form.setData('butuh_admin', nyala)}
                />
                <span className="text-body">Butuh admin</span>
            </div>
            {form.data.butuh_admin && (
                <Field label="Alasan butuh admin" htmlFor="penilaian-alasan">
                    <Input
                        id="penilaian-alasan"
                        value={form.data.alasan_butuh_admin}
                        onChange={(e) =>
                            form.setData('alasan_butuh_admin', e.target.value)
                        }
                    />
                </Field>
            )}
            {galat && (
                <span role="alert" className="text-caption text-red-text">
                    {galat}
                </span>
            )}
            <div className="flex gap-2">
                <Button
                    varian="primary"
                    ukuran="sm"
                    aria-disabled={form.processing}
                    onClick={() =>
                        form.patch(penilaian.url(id), {
                            preserveScroll: true,
                            onSuccess: onTutup,
                        })
                    }
                >
                    Simpan
                </Button>
                <Button ukuran="sm" onClick={onTutup}>
                    Batal
                </Button>
            </div>
        </div>
    );
}

/**
 * "Aksi lain": a short menu of what can be done for this customer, using the same
 * actions as Percakapan and Jeda. Closes on a click outside or Esc.
 */
export function MenuAksi({
    id,
    nama,
    nomorWa,
    jedaId,
    butuhAdmin,
}: {
    id: number;
    nama: string;
    nomorWa: string | null;
    jedaId: number | null;
    butuhAdmin: boolean;
}) {
    const [buka, setBuka] = useState(false);
    const wadah = useRef<HTMLDivElement>(null);
    const akses = usePage().props.panel?.akses;

    useEffect(() => {
        if (!buka) {
            return;
        }

        const tutup = (e: MouseEvent | KeyboardEvent) => {
            if (
                e instanceof KeyboardEvent
                    ? e.key === 'Escape'
                    : !wadah.current?.contains(e.target as Node)
            ) {
                setBuka(false);
            }
        };

        document.addEventListener('mousedown', tutup);
        document.addEventListener('keydown', tutup);

        return () => {
            document.removeEventListener('mousedown', tutup);
            document.removeEventListener('keydown', tutup);
        };
    }, [buka]);

    const jalankan = (url: string, data: Record<string, string> = {}) => {
        setBuka(false);
        router.post(url, data, { preserveScroll: true });
    };

    const item =
        'flex w-full cursor-pointer items-center gap-2 border-none bg-transparent px-3 py-2 text-left text-body text-ink no-underline hover:bg-subtle';

    return (
        <div ref={wadah} className="relative">
            <button
                type="button"
                aria-label="Aksi lain"
                aria-haspopup="menu"
                aria-expanded={buka}
                onClick={() => setBuka(!buka)}
                className="flex size-9 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-ink"
            >
                <Ikon nama="lainnyaPenuh" ukuran={18} />
            </button>
            {buka && (
                <div
                    role="menu"
                    className="absolute top-10 right-0 z-10 flex w-60 flex-col rounded-control border border-line bg-surface py-1 motion-safe:animate-[muncul_120ms_ease-out]"
                >
                    {akses?.balas && (
                        <>
                            {jedaId ? (
                                <button
                                    type="button"
                                    role="menuitem"
                                    className={item}
                                    onClick={() => jalankan(lanjut.url(jedaId))}
                                >
                                    Lanjutkan bot
                                </button>
                            ) : (
                                nomorWa && (
                                    <button
                                        type="button"
                                        role="menuitem"
                                        className={item}
                                        onClick={() =>
                                            jalankan(simpanJeda.url(), {
                                                customer: nomorWa,
                                                durasi: '2 jam',
                                                alasan: 'Dari halaman Pelanggan',
                                            })
                                        }
                                    >
                                        Jeda bot 2 jam
                                    </button>
                                )
                            )}
                            <button
                                type="button"
                                role="menuitem"
                                className={item}
                                onClick={() => jalankan(resetMemori.url(id))}
                            >
                                Reset memori chat
                            </button>
                            {butuhAdmin && (
                                <button
                                    type="button"
                                    role="menuitem"
                                    className={item}
                                    onClick={() => {
                                        setBuka(false);
                                        router.patch(
                                            tangani.url(id),
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Tandai sudah ditangani
                                </button>
                            )}
                        </>
                    )}
                    {akses?.catat && (
                        <Link
                            role="menuitem"
                            href={catat.url({ query: { cari: nama } })}
                            className={cn(item, 'text-ink')}
                        >
                            Catat order untuk customer ini
                        </Link>
                    )}
                    {!akses?.balas && !akses?.catat && (
                        <span className="px-3 py-2 text-body text-muted">
                            Tidak ada aksi untuk peran Anda.
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
