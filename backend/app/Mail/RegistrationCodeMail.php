<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The registration code, for graduates whose Registrar record holds an email but no usable phone (FR-2). */
class RegistrationCodeMail extends Mailable
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Soroti University alumni registration code');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.registration-code');
    }
}
