import { Form, Head, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    AuthInput,
    AuthNomor,
    AuthPassword,
} from '@/components/auth/auth-input';
import { AuthLayout } from '@/components/auth/auth-layout';
import { GalatAuth, galatPertama } from '@/components/auth/galat-auth';
import type { SisiAuth } from '@/components/auth/auth-layout';
import { KembaliAuth } from '@/components/auth/kembali-auth';
import { KodeOtp } from '@/components/auth/kode-otp';
import { Langkah } from '@/components/auth/langkah';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/form';
import { useHitungMundur } from '@/hooks/use-hitung-mundur';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { cek, kode as kirimKode, simpan } from '@/routes/password';

const sisi: SisiAuth = {
    atas: 'Pemulihan akun',
    judul: 'Kode reset hanya dikirim ke WhatsApp yang terdaftar.',
    paragraf:
        'Panel ini memegang data order dan pelanggan. Karena itu reset tidak lewat email yang bisa dibaca siapa saja, tapi lewat nomor yang sudah diverifikasi supervisor.',
    baris: [
        {
            kode: '5 MENIT',
            teks: 'Masa berlaku setiap kode. Kode lama otomatis hangus saat Anda minta yang baru.',
        },
        {
            kode: '3 PERCOBAAN',
            teks: 'Setelah tiga kali salah, reset dikunci 15 menit dan supervisor dikabari.',
        },
        {
            kode: 'LOG',
            teks: 'Reset tercatat di Log aktivitas beserta perangkat dan waktunya.',
        },
    ],
    bawah: 'Butuh bantuan? Hubungi supervisor toko.',
};

type Terkirim = { nomor: string; sisaKirim: number };

const JEDA_KIRIM_ULANG = 52;
const KODE_KOSONG = ['', '', '', '', '', ''];

const label = 'grow text-body font-semibold text-ink';
const h1 = 'mb-1.5 text-page font-semibold tracking-[-0.02em]';
const sub = 'm-0 text-panel leading-[1.5] text-muted';

export default function LupaSandi() {
    const nomor = useHttp<{ nomor_wa: string }, Terkirim>({ nomor_wa: '' });
    const pemeriksa = useHttp<{ kode: string }, { email: string }>({
        kode: '',
    });
    const [terkirim, setTerkirim] = useState<Terkirim | null>(null);
    const [kode, setKode] = useState(KODE_KOSONG);
    const [email, setEmail] = useState<string | null>(null);
    const [keluarkan, setKeluarkan] = useState(true);
    const kirimUlang = useHitungMundur(JEDA_KIRIM_ULANG, terkirim);
    const lengkap = !kode.includes('');
    const langkah = email ? 2 : terkirim ? 1 : 0;
    const { errors } = usePage().props;

    const kirim = (e: FormEvent) => {
        e.preventDefault();
        minta();
    };

    const minta = () => {
        if (nomor.processing) {
            return;
        }

        void nomor.post(kirimKode.url(), {
            onSuccess: (balasan) => {
                setKode(KODE_KOSONG);
                setTerkirim(balasan);
            },
        });
    };

    const lanjut = (e: FormEvent) => {
        e.preventDefault();

        if (!lengkap || pemeriksa.processing) {
            return;
        }

        pemeriksa.transform(() => ({ kode: kode.join('') }));
        void pemeriksa.post(cek.url(), {
            onSuccess: (balasan) => setEmail(balasan.email),
        });
    };

    return (
        <AuthLayout sisi={sisi}>
            <Head title="Lupa kata sandi" />
            <Langkah
                label={['Nomor WhatsApp', 'Kode', 'Sandi baru']}
                aktif={langkah}
            />
            {langkah === 0 && (
                <form onSubmit={kirim} className="flex flex-col">
                    <KembaliAuth href={login.url()}>
                        Kembali ke halaman masuk
                    </KembaliAuth>
                    <h1 className={cn(h1, 'mt-3.5')}>Atur ulang kata sandi</h1>
                    <p className={sub}>
                        Masukkan nomor WhatsApp yang terdaftar. Kode reset
                        dikirim ke sana, bukan ke email.
                    </p>
                    <div className="mt-6 flex flex-col gap-1.5">
                        <div className="flex items-baseline">
                            <label htmlFor="wa" className={label}>
                                Nomor WhatsApp
                            </label>
                        </div>
                        <AuthNomor
                            id="wa"
                            placeholder="812 xxxx xxxx"
                            autoComplete="tel-national"
                            value={nomor.data.nomor_wa}
                            onChange={(e) =>
                                nomor.setData('nomor_wa', e.target.value)
                            }
                        />
                    </div>
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        className="mt-5 gap-2"
                    >
                        Kirim kode reset
                    </Button>
                    <GalatAuth pesan={galatPertama(nomor.errors)} />
                    <p className="mt-4 mb-0 text-caption leading-[1.5] text-muted">
                        Tidak pegang nomor itu lagi? Supervisor bisa mengganti
                        nomor Anda di Tim &amp; akses, lalu coba lagi.
                    </p>
                </form>
            )}
            {langkah === 1 && terkirim && (
                <form onSubmit={lanjut} className="flex flex-col">
                    <KembaliAuth onClick={() => setTerkirim(null)}>
                        Ganti nomor
                    </KembaliAuth>
                    <h1 className={cn(h1, 'mt-3.5')}>Cek WhatsApp Anda</h1>
                    <p className={sub}>
                        Kode reset dikirim ke{' '}
                        <strong className="font-semibold text-ink">
                            {terkirim.nomor}
                        </strong>
                        . Berlaku 5 menit.
                    </p>
                    <KodeOtp kode={kode} onUbah={setKode} />
                    <div className="mt-3 text-body text-muted">
                        {kirimUlang === '0:00' && terkirim.sisaKirim > 0 ? (
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
                            <>
                                Kirim ulang dalam{' '}
                                <strong className="font-semibold text-ink">
                                    {kirimUlang}
                                </strong>
                            </>
                        )}{' '}
                        · sisa {terkirim.sisaKirim} percobaan
                    </div>
                    <GalatAuth
                        pesan={
                            galatPertama(pemeriksa.errors) ??
                            galatPertama(nomor.errors)
                        }
                    />
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        aria-disabled={!lengkap}
                        className={cn(
                            'mt-[22px] gap-2',
                            !lengkap && 'opacity-45',
                        )}
                    >
                        Lanjut
                    </Button>
                </form>
            )}
            {langkah === 2 && (
                <Form {...simpan.form()} className="flex flex-col">
                    <h1 className={cn(h1, 'mt-[22px]')}>
                        Buat kata sandi baru
                    </h1>
                    <p className={sub}>
                        Untuk akun{' '}
                        <strong className="font-semibold text-ink">
                            {email}
                        </strong>
                        .
                    </p>
                    <div className="mt-6 flex flex-col gap-1.5">
                        <div className="flex items-baseline">
                            <label htmlFor="pw" className={label}>
                                Kata sandi baru
                            </label>
                        </div>
                        <AuthPassword
                            id="pw"
                            name="password"
                            placeholder="Min. 8: huruf besar, kecil, angka, simbol"
                            autoComplete="new-password"
                        />
                    </div>
                    <div className="mt-3 flex flex-col gap-1.5">
                        <div className="flex items-baseline">
                            <label htmlFor="pw2" className={label}>
                                Ulangi kata sandi
                            </label>
                        </div>
                        <AuthInput
                            ikon="gembok"
                            id="pw2"
                            name="password_confirmation"
                            type="password"
                            placeholder="Ketik ulang"
                            autoComplete="new-password"
                        />
                    </div>
                    <Checkbox
                        name="keluarkan"
                        value="1"
                        checked={keluarkan}
                        onChange={() => setKeluarkan(!keluarkan)}
                        label="Keluarkan akun ini dari semua perangkat lain"
                        className="mt-3.5 gap-2 text-body leading-[1.45] text-ink-2"
                        inputClassName="mt-px"
                    />
                    {/* An <a> in the design: 44px + 1px borders in content-box = 46px. */}
                    <Button
                        type="submit"
                        varian="primary"
                        ukuran="lg"
                        className="mt-5 box-content gap-2"
                    >
                        Simpan &amp; masuk lagi
                    </Button>
                    <GalatAuth pesan={errors.password} />
                </Form>
            )}
        </AuthLayout>
    );
}
