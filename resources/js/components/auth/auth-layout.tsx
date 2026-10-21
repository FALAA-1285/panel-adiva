import type { ReactNode } from 'react';
import { Ikon } from '@/components/ui/ikon';

type BarisSisi = { kode: string; teks: string };

export type SisiAuth = {
    /** Small line at the top of the blue panel. */
    atas: string;
    judul: ReactNode;
    paragraf: string;
    baris: BarisSisi[];
    bawah: string;
};

type AuthLayoutProps = {
    sisi: SisiAuth;
    children: ReactNode;
};

// Separator colour of the blue panel, rgba(255,255,255,0.18) in the source.
const garis = 'border-[rgba(255,255,255,0.18)]';

/**
 * Masuk, Daftar and Lupa sandi: 560px form column + 880px blue "Tentang panel" panel.
 * Wider than the design's 1440px the blue panel takes the rest; on a screen shorter than
 * the content the whole page scrolls (both columns keep the same height).
 */
export function AuthLayout({ sisi, children }: AuthLayoutProps) {
    return (
        <div className="flex h-screen w-full overflow-hidden bg-surface font-sans text-ink max-lg:h-auto max-lg:min-h-dvh max-lg:overflow-y-auto">
            <main className="box-border flex h-full w-[560px] shrink-0 [scrollbar-width:none] flex-col justify-between overflow-y-auto bg-surface px-20 py-6 max-lg:h-auto max-lg:min-h-dvh max-lg:w-full max-lg:shrink max-lg:px-6 max-lg:py-6 [&::-webkit-scrollbar]:hidden">
                <div className="flex shrink-0 items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        className="flex size-8 items-center justify-center rounded-control bg-brand text-caption font-bold tracking-[-0.02em] text-white"
                    >
                        BB
                    </span>
                    <span className="flex flex-col gap-px">
                        <span className="text-panel font-semibold">
                            Bang Bana
                        </span>
                        <span className="text-caption text-muted">
                            Panel admin Adiva
                        </span>
                    </span>
                </div>
                <div className="flex grow flex-col justify-start pt-5 pb-2 max-lg:items-center xl:pt-8">
                    <div className="flex w-[400px] flex-col max-md:w-full max-md:max-w-[400px]">
                        {children}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2 text-caption text-muted">
                    <Ikon nama="gembok" ukuran={14} tebal={1.9} />
                    <span>Keluar otomatis setelah 12 jam tidak aktif</span>
                </div>
            </main>
            <aside
                aria-label="Tentang panel"
                // any width other than the design's 1440px: the panel takes what is left; gone below 1024px
                className="box-border flex h-full w-[880px] shrink-0 grow flex-col justify-between overflow-hidden bg-brand px-[88px] py-10 text-white max-[1440px]:w-auto max-[1440px]:min-w-0 max-[1440px]:shrink max-[1440px]:px-14 max-lg:hidden"
            >
                <div className="shrink-0 text-body font-medium text-primary-line">
                    {sisi.atas}
                </div>
                <div className="flex grow flex-col justify-start pt-8 pb-4 xl:pt-14">
                    <h2 className="m-0 w-[640px] text-display leading-[1.1] font-semibold tracking-[-0.03em] text-white max-[1440px]:w-auto max-[1440px]:max-w-[640px] max-xl:text-display-sm">
                        {sisi.judul}
                    </h2>
                    <p className="mt-4 mb-6 w-[560px] text-judul leading-[1.55] text-[#DBEAFE] max-[1440px]:w-auto max-[1440px]:max-w-[560px] xl:mt-5 xl:mb-9">
                        {sisi.paragraf}
                    </p>
                    <div
                        className={`flex w-[640px] flex-col border-b max-[1440px]:w-auto max-[1440px]:max-w-[640px] ${garis}`}
                    >
                        {sisi.baris.map((b) => (
                            <div
                                key={b.kode}
                                className={`grid grid-cols-[150px_minmax(0,1fr)] items-baseline gap-5 border-t py-3 xl:py-4 ${garis}`}
                            >
                                <span className="font-mono text-caption font-medium tracking-[0.02em] text-primary-line">
                                    {b.kode}
                                </span>
                                <span className="text-panel leading-[1.5] text-white">
                                    {b.teks}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="shrink-0 text-caption leading-[1.55] text-primary-line">
                    {sisi.bawah}
                </div>
            </aside>
        </div>
    );
}
