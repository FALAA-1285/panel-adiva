import { Form, Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { AuthInput, AuthPassword } from '@/components/auth/auth-input';
import { AuthLayout } from '@/components/auth/auth-layout';
import type { SisiAuth } from '@/components/auth/auth-layout';
import { AuthTab } from '@/components/auth/auth-tab';
import { GalatAuth, galatPertama } from '@/components/auth/galat-auth';
import { KembaliAuth } from '@/components/auth/kembali-auth';
import { KodeOtp } from '@/components/auth/kode-otp';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { useHitungMundur } from '@/hooks/use-hitung-mundur';
import { cn } from '@/lib/utils';
import { register } from '@/routes';
import { kode as kirimKode, store } from '@/routes/login';
import { verifikasi } from '@/routes/login/kode';
import { request } from '@/routes/password';

const sisi: SisiAuth = {
    atas: 'Panel internal · CV TKT Bana Food',
    judul: (
        <>
            Adiva yang membalas.
            <br />
            Tim Bang Bana yang memutuskan.
        </>
    ),
    paragraf:
        'Bot menjawab chat WhatsApp, menghitung ongkir, dan mencatat order. ACC, pembayaran, dan pengiriman tetap di tangan admin — dari panel ini atau langsung dari WhatsApp.',
    baris: [
        {
            kode: 'ACC · TOLAK',
            teks: 'Order dari chat masuk antrean. Sekali tekan, invoice PDF terkirim ke customer.',
        },
        {
            kode: 'JEDA · LANJUT',
            teks: 'Ambil alih satu customer, balas sendiri, lalu serahkan lagi ke Adiva dengan catatan.',
        },
        {
            kode: 'PIPELINE',
            teks: 'Setiap balasan memperbarui tahap customer — dari baru menyapa sampai pesan ulang.',
        },
    ],
    bawah: 'Sawojajar, Kota Malang · Setiap login dan perubahan data dicatat atas nama akun.',
};

const label = 'grow text-body font-semibold text-ink';
const JEDA_KIRIM_ULANG = 45;
const KODE_KOSONG = ['', '', '', '', '', ''];

export default function Masuk({
    kodePerangkat = null,
}: {
    /** "Kode WhatsApp di perangkat baru": the password was right, the code is awaited. */
    kodePerangkat?: { nomor: string } | null;
}) {
    const [ingat, setIngat] = useState(true);
    const [email, setEmail] = useState('');
    const { errors } = usePage().props;
    // "Kirim kode masuk lewat WhatsApp": the code goes to the number of the email typed above
    const minta = useHttp<{ email: string }, { nomor: string }>({ email: '' });
    const [terkirim, setTerkirim] = useState<{ nomor: string } | null>(
        kodePerangkat,
    );
    // after the password the server sends kodePerangkat; the page is reused, so follow it here
    const [perangkatServer, setPerangkatServer] = useState(kodePerangkat);

    if (kodePerangkat !== perangkatServer) {
        setPerangkatServer(kodePerangkat);

        if (kodePerangkat) {
            setTerkirim(kodePerangkat);
        }
    }
    const [kode, setKode] = useState(KODE_KOSONG);
    const [memverifikasi, setMemverifikasi] = useState(false);
    const kirimUlang = useHitungMundur(JEDA_KIRIM_ULANG, terkirim);
    const lengkap = !kode.includes('');

    const mintaKode = () => {
        if (minta.processing) {
            return;
        }

        minta.transform(() => ({ email }));
        void minta.post(kirimKode.url(), {
            onSuccess: (balasan) => {
                setKode(KODE_KOSONG);
                setTerkirim(balasan);
            },
        });
    };

    const masukDenganKode = (e: FormEvent) => {
        e.preventDefault();

        if (!lengkap || memverifikasi) {
            return;
        }

        router.post(
            verifikasi.url(),
            { kode: kode.join(''), remember: ingat },
            {
                preserveState: true,
                onStart: () => setMemverifikasi(true),
                onFinish: () => setMemverifikasi(false),
            },
        );
    };

    if (terkirim) {
        return (
            <AuthLayout sisi={sisi}>
                <Head title="Masuk" />
                <form onSubmit={masukDenganKode} className="flex flex-col">
                    <KembaliAuth onClick={() => setTerkirim(null)}>
                        Kembali
                    </KembaliAuth>
                    <h1 className="mt-3.5 mb-1.5 text-page font-semibold tracking-[-0.02em]">
                        Masukkan kode dari WhatsApp
                    </h1>
                    <p className="m-0 text-panel leading-[1.5] text-muted">
                        {kodePerangkat && 'Perangkat ini belum dikenal. '}
                        Adiva mengirim 6 digit ke{' '}
                        <strong className="font-semibold text-ink">
                            {terkirim.nomor}
                        </strong>
                        . Berlaku 5 menit.
                    </p>
                    <KodeOtp kode={kode} onUbah={setKode} />
                    <div className="mt-3 text-body text-muted">
                        {kirimUlang === '0:00' ? (
                            <a
                                href="#"
                                className="font-medium no-underline"
                                onClick={(e) => {
                                    e.preventDefault();
                                    mintaKode();
                                }}
                            >
                                Kirim ulang kode
                            </a>
                        ) : (
                            <>
                                Kirim ulang dalam{' '}
                                <strong className="font-semibold text-ink">
                                    {kirimUlang}
                                </strong>
                            </>
                        )}
                    </div>
                    <GalatAuth
                        pesan={errors.kode ?? galatPertama(minta.errors)}
                    />
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        aria-disabled={!lengkap || memverifikasi}
                        className={cn(
                            'mt-[22px] gap-2',
                            (!lengkap || memverifikasi) &&
                                'pointer-events-none opacity-45',
                        )}
                    >
                        Masuk
                    </Button>
                </form>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout sisi={sisi}>
            <Head title="Masuk" />
            <h1 className="m-0 mb-1 text-page leading-[1.2] font-semibold tracking-[-0.02em]">
                Masuk ke panel
            </h1>
            <p className="m-0 text-panel leading-[1.5] text-muted">
                Pakai email kerja, atau minta kode lewat WhatsApp.
            </p>
            <div className="mt-3.5">
                <AuthTab aktif="masuk" />
            </div>
            <Form {...store.form()} className="flex flex-col">
                <div className="mt-3 flex flex-col gap-1">
                    <div className="flex items-baseline">
                        <label htmlFor="email" className={label}>
                            Email kerja
                        </label>
                    </div>
                    <AuthInput
                        ikon="email"
                        id="email"
                        name="email"
                        type="text"
                        placeholder="nama@bangbana.id"
                        autoComplete="username"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                </div>
                <div className="mt-2.5 flex flex-col gap-1">
                    <div className="flex items-baseline">
                        <label htmlFor="pw" className={label}>
                            Kata sandi
                        </label>
                        <Link
                            href={request.url()}
                            className="text-body font-medium no-underline"
                        >
                            Lupa kata sandi?
                        </Link>
                    </div>
                    <AuthPassword
                        id="pw"
                        name="password"
                        placeholder="Kata sandi Anda"
                        autoComplete="current-password"
                    />
                </div>
                <Checkbox
                    name="remember"
                    checked={ingat}
                    onChange={() => setIngat(!ingat)}
                    label="Ingat perangkat ini selama 30 hari"
                    className="mt-3 items-center gap-2 text-ink-2 xl:mt-4"
                    inputClassName="mt-0"
                />
                {/* An <a> in the design: 44px + 1px borders in content-box = 46px. */}
                <Button
                    type="submit"
                    varian="primary"
                    ukuran="lg"
                    className="mt-3.5 box-content gap-2 xl:mt-[22px]"
                >
                    Masuk
                </Button>
                <GalatAuth pesan={errors.email ?? errors.password} />
            </Form>
            <div className="my-3.5 flex items-center gap-3 text-caption text-muted xl:my-5">
                <span className="h-px grow bg-line" />
                atau
                <span className="h-px grow bg-line" />
            </div>
            <Button
                varian="secondary"
                ukuran="lg"
                className="gap-2"
                aria-disabled={minta.processing}
                onClick={mintaKode}
            >
                <Ikon nama="whatsapp" ukuran={17} tebal={1.9} />
                Kirim kode masuk lewat WhatsApp
            </Button>
            <GalatAuth pesan={galatPertama(minta.errors)} />
            <p className="mt-2 mb-0 text-caption leading-[1.5] text-muted">
                Kode 6 digit dikirim Adiva ke nomor Anda yang terdaftar di
                daftar staf.
            </p>
            <p className="mt-4 mb-0 text-body leading-[1.5] text-muted xl:mt-7">
                Belum punya akun?{' '}
                <Link
                    href={register.url()}
                    className="font-semibold no-underline"
                >
                    Daftar sebagai staf
                </Link>
            </p>
        </AuthLayout>
    );
}
