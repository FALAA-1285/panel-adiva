import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { ID_PERTANYAAN_UJI, UjiAdiva } from '@/components/katalog/uji-adiva';
import type { Uji } from '@/components/katalog/uji-adiva';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, IconButton } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { InputKata } from '@/components/ui/input-kata';
import { Panel } from '@/components/ui/panel';
import { cn } from '@/lib/utils';
import { simpan, tambah } from '@/routes/katalog/kebijakan';

type Kebijakan = {
    id: number;
    topik: string;
    isi: string | null;
    kataPemicu: string[];
    dipakai: number;
    diubah: string | null;
    pertanyaan: string[];
};

type Props = {
    tab: TabKatalog;
    /** A policy just added, opened in the editor. */
    pilih: number | null;
    kebijakan: Kebijakan[];
    uji: (Uji & { kebijakanId: number | null }) | null;
};

export default function KatalogKebijakan({
    tab,
    pilih: baru,
    kebijakan,
    uji,
}: Props) {
    // the policy the last test was answered with ("KB_Kebijakan (Ongkir & ekspedisi)"); 'baru': an empty editor
    const [pilih, setPilih] = useState<number | 'baru' | null>(
        baru ?? uji?.kebijakanId ?? kebijakan[0]?.id ?? null,
    );
    // after "Simpan" on a new one the server sends ?pilih=; the page is reused, so follow it here
    const [pilihServer, setPilihServer] = useState(baru);

    if (baru !== pilihServer) {
        setPilihServer(baru);

        if (baru !== null) {
            setPilih(baru);
        }
    }
    const terpilih =
        pilih === 'baru'
            ? undefined
            : (kebijakan.find((k) => k.id === pilih) ?? kebijakan[0]);
    const bolehUbah = usePage().props.panel?.akses.katalog ?? false;
    const tambahBaru = () => bolehUbah && setPilih('baru');

    return (
        <AppLayout active="katalog">
            <Head title="Kebijakan" />
            <KatalogHeader
                aktif="kebijakan"
                tab={tab}
                aksi={
                    <Button
                        varian="primary"
                        aria-disabled={!bolehUbah}
                        title={bolehUbah ? undefined : 'Hanya supervisor'}
                        onClick={tambahBaru}
                    >
                        <Ikon nama="tambah" ukuran={15} tebal={2} />
                        Tambah kebijakan
                    </Button>
                }
            />
            <IsiHalaman>
                <div className="grid grid-cols-[300px_minmax(0,1fr)_320px] items-start gap-5 max-xl:grid-cols-[260px_minmax(0,1fr)] max-lg:grid-cols-1">
                    <Panel
                        judul={`${kebijakan.length} kebijakan`}
                        aksi={
                            <IconButton
                                aria-label="Tambah kebijakan"
                                aria-disabled={!bolehUbah}
                                onClick={tambahBaru}
                            >
                                <Ikon nama="tambah" />
                            </IconButton>
                        }
                        isiClassName="p-0"
                    >
                        {kebijakan.map((k) => {
                            const aktif = k.id === terpilih?.id;

                            return (
                                <button
                                    key={k.id}
                                    type="button"
                                    aria-pressed={aktif}
                                    onClick={() => setPilih(k.id)}
                                    className={cn(
                                        'flex w-full cursor-pointer flex-col gap-[3px] border-0 border-b border-line-soft px-4 py-[11px] text-left text-ink',
                                        aktif
                                            ? 'bg-selected shadow-[inset_3px_0_0_var(--color-primary)]'
                                            : 'bg-surface',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'text-body',
                                            aktif
                                                ? 'font-semibold'
                                                : 'font-medium',
                                        )}
                                    >
                                        {k.topik}
                                    </span>
                                    <span className="text-caption text-muted">
                                        Dipakai {k.dipakai}× · 30 hari
                                        {k.diubah && ` · diubah ${k.diubah}`}
                                    </span>
                                </button>
                            );
                        })}
                    </Panel>
                    {pilih === 'baru' ? (
                        <PenyuntingKebijakan
                            key="baru"
                            kebijakan={null}
                            onBatal={() => setPilih(kebijakan[0]?.id ?? null)}
                        />
                    ) : (
                        terpilih && (
                            <PenyuntingKebijakan
                                key={terpilih.id}
                                kebijakan={terpilih}
                            />
                        )
                    )}
                    <UjiAdiva
                        uji={uji}
                        bagian="KEBIJAKAN"
                        kebijakanId={terpilih?.id ?? null}
                        className="max-xl:col-span-2 max-lg:col-span-1"
                    />
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function PenyuntingKebijakan({
    kebijakan,
    onBatal,
}: {
    /** null: "Tambah kebijakan". */
    kebijakan: Kebijakan | null;
    onBatal?: () => void;
}) {
    const form = useForm({
        topik: kebijakan?.topik ?? '',
        jawaban_resmi: kebijakan?.isi ?? '',
        kata_pemicu: kebijakan?.kataPemicu ?? [],
    });
    const galat = Object.values(form.errors)[0];

    const simpanKebijakan = () =>
        kebijakan
            ? form.put(simpan.url(kebijakan.id), { preserveScroll: true })
            : form.post(tambah.url(), { preserveScroll: true });

    return (
        <Panel
            judul={kebijakan?.topik ?? 'Kebijakan baru'}
            sub={
                kebijakan
                    ? `Dibaca lewat tool KB_Kebijakan · dipakai ${kebijakan.dipakai}× dalam 30 hari`
                    : 'Kode KB berikutnya diberikan otomatis saat disimpan.'
            }
            aksi={
                <>
                    {onBatal && (
                        <Button ukuran="sm" onClick={onBatal}>
                            Batal
                        </Button>
                    )}
                    {/* the question goes in the Uji tanya Adiva panel, asked about this policy */}
                    <Button
                        ukuran="sm"
                        onClick={() =>
                            document.getElementById(ID_PERTANYAAN_UJI)?.focus()
                        }
                    >
                        <Ikon nama="putar" ukuran={15} tebal={2} />
                        Uji jawaban
                    </Button>
                    <Button
                        ukuran="sm"
                        varian="primary"
                        aria-disabled={form.processing}
                        onClick={simpanKebijakan}
                    >
                        Simpan
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-4">
                <Field label="Judul" htmlFor="topik">
                    <Input
                        id="topik"
                        value={form.data.topik}
                        onChange={(e) => form.setData('topik', e.target.value)}
                    />
                </Field>
                <Field
                    label="Isi kebijakan"
                    htmlFor="isi"
                    hint="Ditulis seperti menjelaskan ke staf baru. Adiva merangkumnya dengan bahasa sendiri, jadi tidak perlu kalimat siap kirim."
                >
                    <Textarea
                        id="isi"
                        rows={7}
                        value={form.data.jawaban_resmi}
                        onChange={(e) =>
                            form.setData('jawaban_resmi', e.target.value)
                        }
                    />
                </Field>
                <Field label="Kata pemicu" htmlFor="kata_pemicu">
                    <InputKata
                        id="kata_pemicu"
                        kata={form.data.kata_pemicu}
                        onUbah={(kata) => form.setData('kata_pemicu', kata)}
                    />
                </Field>
                {galat && (
                    <span role="alert" className="text-caption text-red-text">
                        {galat}
                    </span>
                )}
                {kebijakan && kebijakan.pertanyaan.length > 0 && (
                    <div className="flex flex-col gap-1">
                        <span className="text-body font-semibold text-ink">
                            Pertanyaan customer terbaru yang dijawab dengan
                            kebijakan ini
                        </span>
                        {kebijakan.pertanyaan.map((p) => (
                            <div
                                key={p}
                                className="border-b border-line-soft py-[7px] text-body text-ink-2"
                            >
                                “{p}”
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </Panel>
    );
}
