/**
 * The server's answer when a step did not go through ("Kode salah.", "Kode gagal
 * dikirim ke WhatsApp…"). Nothing is shown while there is no error.
 */
export function GalatAuth({ pesan }: { pesan?: string | null }) {
    return pesan ? (
        <p role="alert" className="mt-3 mb-0 text-body text-red-text">
            {pesan}
        </p>
    ) : null;
}

/** The first message of an errors object. */
export function galatPertama(
    errors: Partial<Record<string, string>>,
): string | undefined {
    return Object.values(errors).find(Boolean);
}
