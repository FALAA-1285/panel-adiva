import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Ikon } from '@/components/ui/ikon';

const kelas =
    'mt-[22px] flex items-center gap-1 self-start text-body font-medium text-ink-3 no-underline';

type KembaliAuthProps = {
    children: ReactNode;
    /** Link to another page ("Kembali ke halaman masuk")… */
    href?: string;
    /** …or go back a step on the same page ("Ubah data", "Ganti nomor"). */
    onClick?: () => void;
};

/** "‹ Ubah data" style back control above the heading of a step. */
export function KembaliAuth({ children, href, onClick }: KembaliAuthProps) {
    const isi = (
        <>
            <Ikon nama="kembali" tebal={2} />
            {children}
        </>
    );

    if (href) {
        return (
            <Link href={href} className={kelas}>
                {isi}
            </Link>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            className={`${kelas} cursor-pointer border-none bg-transparent p-0`}
        >
            {isi}
        </button>
    );
}
