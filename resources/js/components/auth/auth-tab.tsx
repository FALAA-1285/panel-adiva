import { Link } from '@inertiajs/react';
import { login, register } from '@/routes';

const tab =
    'box-content flex h-[34px] items-center justify-center rounded-nav border text-body no-underline';

/**
 * "Masuk | Daftar staf" switch at the top of the Masuk and Daftar forms.
 */
export function AuthTab({ aktif }: { aktif: 'masuk' | 'daftar' }) {
    const item = (kode: 'masuk' | 'daftar', label: string, href: string) =>
        kode === aktif ? (
            <span
                role="tab"
                aria-selected="true"
                className={`${tab} border-line bg-surface font-semibold text-ink`}
            >
                {label}
            </span>
        ) : (
            <Link
                role="tab"
                aria-selected="false"
                href={href}
                className={`${tab} border-transparent font-medium text-ink-3`}
            >
                {label}
            </Link>
        );

    return (
        <div
            role="tablist"
            aria-label="Masuk atau daftar"
            className="grid grid-cols-2 gap-1 rounded-[9px] bg-line-softer p-[3px]"
        >
            {item('masuk', 'Masuk', login.url())}
            {item('daftar', 'Daftar staf', register.url())}
        </div>
    );
}
