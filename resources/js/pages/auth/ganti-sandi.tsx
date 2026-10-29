import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AuthInput, AuthPassword } from '@/components/auth/auth-input';
import { AuthLayout } from '@/components/auth/auth-layout';
import type { SisiAuth } from '@/components/auth/auth-layout';
import { GalatAuth } from '@/components/auth/galat-auth';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { simpan } from '@/routes/sandi';

type Props = {
    /** the password is older than the limit: nothing else opens until it is replaced */
    wajib: boolean;
    hari: number;
};

const sisi = (hari: number): SisiAuth => ({
    atas: 'Keamanan akun',
    judul: `Kata sandi diganti setiap ${hari} hari.`,
    paragraf:
        'Panel ini memegang data order dan pelanggan. Mengganti kata sandi secara berkala memperkecil akibatnya kalau kata sandi lama pernah terlihat orang lain.',
    baris: [
        {
            kode: 'ATURAN',
            teks: 'Minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol.',
        },
        {
            kode: 'BARU',
            teks: 'Kata sandi baru tidak boleh sama dengan yang sekarang.',
        },
        {
            kode: 'LOG',
            teks: 'Penggantian tercatat di Log aktivitas beserta waktunya.',
        },
    ],
    bawah: 'Lupa kata sandi yang sekarang? Keluar, lalu pakai "Lupa kata sandi".',
});

const label = 'text-body font-semibold text-ink';
const h1 = 'mb-1.5 text-page font-semibold tracking-[-0.02em]';
const sub = 'm-0 text-panel leading-[1.5] text-muted';

export default function GantiSandi({ wajib, hari }: Props) {
    const { errors } = usePage().props;

    return (
        <AuthLayout sisi={sisi(hari)}>
            <Head title="Ganti kata sandi" />
            <Form {...simpan.form()} className="flex flex-col">
                <h1 className={cn(h1, 'mt-[22px]')}>Ganti kata sandi</h1>
                <p className={sub}>
                    {wajib
                        ? `Kata sandi Anda sudah dipakai lebih dari ${hari} hari. Buat yang baru untuk melanjutkan.`
                        : 'Buat kata sandi baru untuk akun Anda.'}
                </p>
                <div className="mt-6 flex flex-col gap-1.5">
                    <label htmlFor="sandi-lama" className={label}>
                        Kata sandi sekarang
                    </label>
                    <AuthPassword
                        id="sandi-lama"
                        name="sandi_lama"
                        autoComplete="current-password"
                    />
                    <GalatAuth pesan={errors.sandi_lama} />
                </div>
                <div className="mt-3 flex flex-col gap-1.5">
                    <label htmlFor="sandi-baru" className={label}>
                        Kata sandi baru
                    </label>
                    <AuthPassword
                        id="sandi-baru"
                        name="password"
                        placeholder="Min. 8: huruf besar, kecil, angka, simbol"
                        autoComplete="new-password"
                    />
                </div>
                <div className="mt-3 flex flex-col gap-1.5">
                    <label htmlFor="sandi-ulang" className={label}>
                        Ulangi kata sandi baru
                    </label>
                    <AuthInput
                        ikon="gembok"
                        id="sandi-ulang"
                        name="password_confirmation"
                        type="password"
                        placeholder="Ketik ulang"
                        autoComplete="new-password"
                    />
                </div>
                <GalatAuth pesan={errors.password} />
                <Button
                    type="submit"
                    varian="primary"
                    ukuran="lg"
                    className="mt-5 gap-2"
                >
                    Simpan kata sandi baru
                </Button>
                <div className="mt-4 flex justify-center gap-4 text-body">
                    {!wajib && (
                        <Link href={home.url()} className="font-medium">
                            Kembali ke Dashboard
                        </Link>
                    )}
                    <Link
                        href={logout()}
                        as="button"
                        className="cursor-pointer border-none bg-transparent p-0 font-medium text-muted"
                    >
                        Keluar
                    </Link>
                </div>
            </Form>
        </AuthLayout>
    );
}
