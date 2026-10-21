<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Daftar, "Kirim lewat email": the sign-up code for a staff member who cannot get it on
 * WhatsApp. Sent right away (they are waiting on the page), as plain text.
 */
class KodeVerifikasiMail extends Mailable
{
    public function __construct(public string $kode) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kode verifikasi panel Adiva');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.kode-verifikasi');
    }
}
