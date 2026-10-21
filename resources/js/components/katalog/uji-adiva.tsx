import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/form';
import { Panel } from '@/components/ui/panel';
import { uji as rutUji } from '@/routes/katalog';

export type Uji = {
    pertanyaan: string;
    jawaban: string | null;
    catatan: string | null;
};

/** The question box, so "Uji jawaban" on Kebijakan can move the cursor here. */
export const ID_PERTANYAAN_UJI = 'uji-pertanyaan';

/**
 * "Uji tanya Adiva": try a customer question against the current data. n8n answers
 * in test mode; the page then shows the saved test.
 */
export function UjiAdiva({
    uji,
    bagian,
    kebijakanId = null,
    className,
}: {
    uji: Uji | null;
    bagian: 'KATALOG' | 'KEBIJAKAN';
    /** Kebijakan: the policy open in the editor, so Adiva is asked about it. */
    kebijakanId?: number | null;
    className?: string;
}) {
    const [pertanyaan, setPertanyaan] = useState(uji?.pertanyaan ?? '');
    const [menunggu, setMenunggu] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);

    const tanya = () => {
        if (menunggu || pertanyaan.trim() === '') {
            return;
        }

        router.post(
            rutUji.url(),
            { bagian, pertanyaan, kebijakan_id: kebijakanId },
            {
                preserveScroll: true,
                onStart: () => setMenunggu(true),
                onFinish: () => setMenunggu(false),
                onSuccess: () => setGalat(null),
                onError: (e) => setGalat(e.pertanyaan ?? null),
            },
        );
    };

    return (
        <Panel judul="Uji tanya Adiva" className={className}>
            <div className="flex flex-col gap-2.5">
                <span className="text-caption leading-[1.45] text-muted">
                    Coba pertanyaan customer setelah mengubah data. Tidak
                    dikirim ke WhatsApp dan tidak masuk CRM.
                </span>
                <Input
                    id={ID_PERTANYAAN_UJI}
                    type="text"
                    aria-label="Pertanyaan customer"
                    placeholder="Tulis pertanyaan customer"
                    value={pertanyaan}
                    onChange={(e) => setPertanyaan(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && tanya()}
                />
                <Button
                    className="w-full"
                    aria-disabled={menunggu}
                    onClick={tanya}
                >
                    Tanya Adiva
                </Button>
                {galat && (
                    <span role="alert" className="text-caption text-red-text">
                        {galat}
                    </span>
                )}
                {uji?.jawaban && (
                    <div className="rounded-[12px_12px_4px_12px] border border-[#DCE6FB] bg-[#EEF3FE] px-3 py-2.5 text-body leading-[1.5]">
                        {uji.jawaban}
                    </div>
                )}
                {uji?.catatan && (
                    <span className="text-caption leading-[1.45] text-muted">
                        {uji.catatan}
                    </span>
                )}
            </div>
        </Panel>
    );
}
