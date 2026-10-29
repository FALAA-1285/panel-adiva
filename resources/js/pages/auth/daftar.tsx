import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, MouseEvent } from 'react';
import {
    AuthInput,
    AuthNomor,
    AuthPassword,
} from '@/components/auth/auth-input';
import { AuthLayout } from '@/components/auth/auth-layout';
import { GalatAuth, galatPertama } from '@/components/auth/galat-auth';
import type { SisiAuth } from '@/components/auth/auth-layout';
import { AuthTab } from '@/components/auth/auth-tab';
import { KembaliAuth } from '@/components/auth/kembali-auth';
import { KodeOtp } from '@/components/auth/kode-otp';
import { Langkah } from '@/components/auth/langkah';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/form';
import { Avatar } from '@/components/ui/teks';
import { useHitungMundur } from '@/hooks/use-hitung-mundur';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { kode as kirimKode, kodeEmail, verifikasi } from '@/routes/register';

const sisi: SisiAuth = {
    atas: 'Akses staf · CV TKT Bana Food',
    judul: 'Satu akun untuk tiap orang yang memegang WhatsApp toko.',
    paragraf:
        'Nomor Anda harus sudah ada di daftar staf (ADMIN_STAFF) — daftar yang sama dengan yang dipakai Adiva untuk mengenali perintah admin. Peran di panel diberikan supervisor.',
    baris: [
        {
            kode: 'SUPERVISOR',
            teks: 'Semua menu panel, termasuk pengaturan toko, broadcast, dan tim.',
        },
        {
            kode: 'STAF CS',
            teks: 'Percakapan, pesanan, jeda & lanjutkan bot di panel.',
        },
        {
            kode: 'PJ KARYAWAN',
            teks: 'Order program karyawan. Notifikasinya tetap masuk ke SARAH_WA.',
        },
    ],
    bawah: 'Kode verifikasi dikirim oleh bot lewat WAHA ke nomor yang sama dengan daftar staf.',
};

type Verifikasi = {
    nomor: string;
    staf: { nama: string; inisial: string; email: string; peran: string };
};

const JEDA_KIRIM_ULANG = 45;
const KODE_KOSONG = ['', '', '', '', '', ''];

const label = 'grow text-body font-semibold text-ink';
const h1 = 'mb-1.5 text-page font-semibold tracking-[-0.02em]';
const sub = 'm-0 text-panel leading-[1.5] text-muted';

export default function Daftar() {
    const form = useHttp<
        {
            nama: string;
            email: string;
            nomor_wa: string;
            password: string;
            setuju: boolean;
        },
        Verifikasi
    >({ nama: '', email: '', nomor_wa: '', password: '', setuju: true });
    const [hasil, setHasil] = useState<Verifikasi | null>(null);
    const [kode, setKode] = useState(KODE_KOSONG);
    const kirimUlang = useHitungMundur(JEDA_KIRIM_ULANG, hasil);
    const [memverifikasi, setMemverifikasi] = useState(false);
    const lewatEmail = useHttp<Record<string, never>, { tujuan: string }>({});
    const { errors } = usePage().props;
    const lengkap = !kode.includes('');

    const minta = () => {
        if (form.processing) {
            return;
        }

        void form.post(kirimKode.url(), {
            onSuccess: (balasan) => {
                setKode(KODE_KOSONG);
                setHasil(balasan);
            },
        });
    };

    const kirim = (e: FormEvent) => {
        e.preventDefault();
        minta();
    };

    // "Kirim lewat email": a new code to the staff member's email; the WhatsApp one stops working
    const kirimEmail = (e: MouseEvent) => {
        e.preventDefault();

        if (!hasil || lewatEmail.processing) {
            return;
        }

        void lewatEmail.post(kodeEmail.url(), {
            onSuccess: (balasan) => {
                setKode(KODE_KOSONG);
                setHasil({ ...hasil, nomor: balasan.tujuan });
            },
        });
    };

    const verifikasiKode = (e: FormEvent) => {
        e.preventDefault();

        if (!lengkap) {
            return;
        }

        router.post(
            verifikasi.url(),
            { kode: kode.join('') },
            {
                preserveState: true,
                onStart: () => setMemverifikasi(true),
                onFinish: () => setMemverifikasi(false),
            },
        );
    };

    return (
        <AuthLayout sisi={sisi}>
            <Head title="Daftar" />
            {hasil && (
                <Langkah
                    label={['Data akun', 'Verifikasi WhatsApp']}
                    aktif={1}
                />
            )}
            {!hasil ? (
                <form onSubmit={kirim} className="flex flex-col">
                    <h1 className="m-0 mb-1 text-page leading-[1.2] font-semibold tracking-[-0.02em]">
                        Buat akun staf
                    </h1>
                    <p className="m-0 text-panel leading-[1.5] text-muted">
                        Hanya untuk nomor yang sudah didaftarkan supervisor.
                    </p>
                    <div className="mt-3.5">
                        <AuthTab aktif="daftar" />
                    </div>
                    <div className="mt-3 flex flex-col gap-1">
                        <label htmlFor="nama" className={label}>
                            Nama
                        </label>
                        <AuthInput
                            ikon="pengguna"
                            id="nama"
                            type="text"
                            placeholder="Nama sesuai daftar staf"
                            autoComplete="name"
                            value={form.data.nama}
                            onChange={(e) =>
                                form.setData('nama', e.target.value)
                            }
                        />
                    </div>
                    <div className="mt-2 flex flex-col gap-1">
                        <label htmlFor="email" className={label}>
                            Email kerja
                        </label>
                        <AuthInput
                            ikon="email"
                            id="email"
                            type="email"
                            placeholder="nama@bangbana.id"
                            autoComplete="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </div>
                    <div className="mt-2 flex flex-col gap-1">
                        <label htmlFor="wa" className={label}>
                            Nomor WhatsApp
                        </label>
                        <AuthNomor
                            id="wa"
                            placeholder="812 xxxx xxxx"
                            autoComplete="tel-national"
                            value={form.data.nomor_wa}
                            onChange={(e) =>
                                form.setData('nomor_wa', e.target.value)
                            }
                        />
                    </div>
                    <div className="mt-2 flex flex-col gap-1">
                        <label htmlFor="pw" className={label}>
                            Kata sandi
                        </label>
                        <AuthPassword
                            id="pw"
                            placeholder="Min. 8: huruf besar, kecil, angka, simbol"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                    </div>
                    <Checkbox
                        checked={form.data.setuju}
                        onChange={() =>
                            form.setData('setuju', !form.data.setuju)
                        }
                        label="Saya setuju aksi dicatat atas nama akun saya"
                        className="mt-2 items-center gap-2 text-body text-ink-2"
                        inputClassName="mt-0"
                    />
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        className="mt-2.5 gap-2"
                    >
                        Kirim kode verifikasi lewat WhatsApp
                    </Button>
                    <GalatAuth pesan={galatPertama(form.errors)} />
                    <p className="mt-2 mb-0 text-body text-muted">
                        Sudah punya akun?{' '}
                        <Link
                            href={login.url()}
                            className="font-semibold no-underline"
                        >
                            Masuk
                        </Link>
                    </p>
                </form>
            ) : (
                <form onSubmit={verifikasiKode} className="flex flex-col">
                    <KembaliAuth onClick={() => setHasil(null)}>
                        Ubah data
                    </KembaliAuth>
                    <h1 className={cn(h1, 'mt-3.5')}>
                        Masukkan kode dari WhatsApp
                    </h1>
                    <p className={sub}>
                        Adiva mengirim 6 digit ke{' '}
                        <strong className="font-semibold text-ink">
                            {hasil.nomor}
                        </strong>
                        . Berlaku 5 menit.
                    </p>
                    <KodeOtp kode={kode} onUbah={setKode} />
                    <div className="mt-3 flex items-center text-body text-muted">
                        {kirimUlang === '0:00' ? (
                            <a
                                href="#"
                                className="font-medium no-underline"
                                onClick={(e) => {
                                    e.preventDefault();
                                    minta();
                                }}
                            >
                                Kirim ulang kode
                            </a>
                        ) : (
                            <span>
                                Kirim ulang dalam{' '}
                                <strong className="font-semibold text-ink">
                                    {kirimUlang}
                                </strong>
                            </span>
                        )}
                        <span className="grow" />
                        <a
                            href="#"
                            className="font-medium no-underline"
                            onClick={kirimEmail}
                        >
                            Kirim lewat email
                        </a>
                    </div>
                    <GalatAuth
                        pesan={
                            errors.kode ??
                            galatPertama(lewatEmail.errors) ??
                            galatPertama(form.errors)
                        }
                    />
                    <div className="mt-3.5 flex items-center gap-3 border-y border-line py-2.5 xl:mt-[22px] xl:py-3.5">
                        <Avatar inisial={hasil.staf.inisial} />
                        <div className="flex grow flex-col gap-px">
                            <span className="text-panel font-semibold">
                                {hasil.staf.nama}
                            </span>
                            <span className="text-caption text-muted">
                                Cocok dengan daftar staf · {hasil.staf.email}
                            </span>
                        </div>
                        <span className="text-caption font-medium text-ink">
                            {hasil.staf.peran}
                        </span>
                    </div>
                    {/* An <a> in the design: 44px + 1px borders in content-box = 46px. */}
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        aria-disabled={!lengkap || memverifikasi}
                        className={cn(
                            'mt-3.5 box-content gap-2 xl:mt-[22px]',
                            (!lengkap || memverifikasi) &&
                                'pointer-events-none opacity-45',
                        )}
                    >
                        Verifikasi &amp; buka panel
                    </Button>
                    <p className="mt-2.5 mb-0 text-caption leading-[1.5] text-muted xl:mt-3.5">
                        Nomor salah? Minta supervisor memperbaikinya di Tim
                        &amp; akses.
                    </p>
                </form>
            )}
        </AuthLayout>
    );
}
