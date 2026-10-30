import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

export type InfoTinjauan = {
    terakhir: string | null;
    oleh: string | null;
    /** the next review is due (or there has never been one) */
    jatuhTempo: boolean;
    setiapHari: number;
};

/**
 * "Terakhir ditinjau … · Tandai sudah ditinjau": a periodic review the BCA assessment asks
 * for, kept in Log aktivitas as proof (Tim & akses: access, Log aktivitas: the log).
 */
export function Tinjauan({
    tinjauan,
    url,
    apa,
}: {
    tinjauan: InfoTinjauan;
    url: string;
    /** what is reviewed, in "Tinjauan {apa}" */
    apa: string;
}) {
    return (
        <div className="flex items-center gap-2 max-md:flex-wrap">
            <span
                className={cn(
                    'text-caption',
                    tinjauan.jatuhTempo ? 'text-amber-text' : 'text-muted',
                )}
                title={`Ditinjau setiap ${tinjauan.setiapHari} hari`}
            >
                {tinjauan.terakhir
                    ? `Tinjauan ${apa} terakhir: ${tinjauan.terakhir} · ${tinjauan.oleh}`
                    : `Tinjauan ${apa} belum pernah dicatat`}
                {tinjauan.jatuhTempo &&
                    tinjauan.terakhir &&
                    ' · sudah waktunya ditinjau lagi'}
            </span>
            <Button
                className="px-3"
                onClick={() => router.post(url, {}, { preserveScroll: true })}
            >
                <Ikon nama="centang" ukuran={15} tebal={2} />
                Tandai sudah ditinjau
            </Button>
        </div>
    );
}
