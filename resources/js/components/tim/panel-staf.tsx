import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/form';
import { Panel } from '@/components/ui/panel';
import { aktif, nonaktif, ubah, undang, undangan } from '@/routes/tim/staf';

export type IsianStaf = {
    nama: string;
    email: string;
    nomor_wa: string;
    peran: string;
    cabang: string;
};

export type StafDiubah = {
    id: number;
    nama: string;
    isian: IsianStaf;
    nonaktif: boolean;
    belumDaftar: boolean;
};

const KOSONG: IsianStaf = {
    nama: '',
    email: '',
    nomor_wa: '',
    peran: 'STAF_CS',
    cabang: '',
};

/**
 * Tim & akses: "Undang staf", "Ubah" and "Aksi lain". Built from the shared form
 * components (the design has no screen for it); shown above the staff table.
 */
export function PanelStaf({
    staf,
    bagian,
    peran,
    onTutup,
}: {
    /** null: a new staff member ("Undang staf"). */
    staf: StafDiubah | null;
    /** "tindakan": opened from "Aksi lain", straight to the actions. */
    bagian: 'isian' | 'tindakan';
    peran: { kode: string; label: string }[];
    onTutup: () => void;
}) {
    const form = useForm<IsianStaf>(staf?.isian ?? KOSONG);
    const [galat, setGalat] = useState<string | null>(null);
    const tindakan = useRef<HTMLDivElement>(null);
    const id = staf ? `staf-${staf.id}` : 'staf-baru';

    useEffect(() => {
        if (bagian === 'tindakan') {
            tindakan.current?.querySelector('button')?.focus();
        }
    }, [bagian]);

    const simpan = () => {
        const pilihan = {
            preserveScroll: true,
            onSuccess: onTutup,
            onError: (e: Record<string, string>) => setGalat(e.staf ?? null),
        };

        if (staf) {
            form.put(ubah.url(staf.id), pilihan);
        } else {
            form.post(undang.url(), pilihan);
        }
    };

    const jalankan = (url: string) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setGalat(null),
                onError: (e) => setGalat(e.staf ?? null),
            },
        );

    return (
        <Panel
            judul={staf ? `Ubah ${staf.nama}` : 'Undang staf'}
            sub={
                staf
                    ? undefined
                    : 'Staf menerima pesan WhatsApp untuk membuat akun di halaman Daftar.'
            }
        >
            <div className="flex flex-col gap-4">
                <div className="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-3">
                    <Field label="Nama" htmlFor={`${id}-nama`}>
                        <Input
                            id={`${id}-nama`}
                            value={form.data.nama}
                            onChange={(e) =>
                                form.setData('nama', e.target.value)
                            }
                        />
                        <Salah pesan={form.errors.nama} />
                    </Field>
                    <Field label="Email" htmlFor={`${id}-email`}>
                        <Input
                            id={`${id}-email`}
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        <Salah pesan={form.errors.email} />
                    </Field>
                    <Field label="Nomor WhatsApp" htmlFor={`${id}-nomor`}>
                        <Input
                            id={`${id}-nomor`}
                            mono
                            inputMode="tel"
                            value={form.data.nomor_wa}
                            onChange={(e) =>
                                form.setData('nomor_wa', e.target.value)
                            }
                        />
                        <Salah pesan={form.errors.nomor_wa} />
                    </Field>
                    <Field label="Peran" htmlFor={`${id}-peran`}>
                        <Select
                            id={`${id}-peran`}
                            value={form.data.peran}
                            onChange={(e) =>
                                form.setData('peran', e.target.value)
                            }
                        >
                            {peran.map((p) => (
                                <option key={p.kode} value={p.kode}>
                                    {p.label}
                                </option>
                            ))}
                        </Select>
                        <Salah pesan={form.errors.peran} />
                    </Field>
                    <Field label="Cabang" htmlFor={`${id}-cabang`}>
                        <Input
                            id={`${id}-cabang`}
                            value={form.data.cabang}
                            onChange={(e) =>
                                form.setData('cabang', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        varian="primary"
                        aria-disabled={form.processing}
                        onClick={simpan}
                    >
                        {staf ? 'Simpan' : 'Kirim undangan'}
                    </Button>
                    <Button onClick={onTutup}>Batal</Button>
                </div>
                {staf && (
                    <div
                        ref={tindakan}
                        className="flex flex-wrap items-center gap-2 border-t border-line-soft pt-3"
                    >
                        <span className="text-body font-semibold">
                            Tindakan
                        </span>
                        <span className="grow" />
                        {staf.belumDaftar && (
                            <Button
                                ukuran="sm"
                                onClick={() => jalankan(undangan.url(staf.id))}
                            >
                                Kirim ulang undangan
                            </Button>
                        )}
                        {staf.nonaktif ? (
                            <Button
                                ukuran="sm"
                                onClick={() => jalankan(aktif.url(staf.id))}
                            >
                                Aktifkan lagi
                            </Button>
                        ) : (
                            <Button
                                ukuran="sm"
                                varian="danger"
                                onClick={() => jalankan(nonaktif.url(staf.id))}
                            >
                                Nonaktifkan
                            </Button>
                        )}
                    </div>
                )}
                {galat && (
                    <span role="alert" className="text-caption text-red-text">
                        {galat}
                    </span>
                )}
            </div>
        </Panel>
    );
}

function Salah({ pesan }: { pesan?: string }) {
    return pesan ? (
        <span className="text-caption text-red-text">{pesan}</span>
    ) : null;
}
