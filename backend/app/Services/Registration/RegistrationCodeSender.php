<?php

namespace App\Services\Registration;

use App\Enums\MessageTemplate;
use App\Enums\NotificationStatus;
use App\Mail\RegistrationCodeMail;
use App\Models\AlumniProfile;
use App\Services\Messaging\NotificationService;
use App\Services\Messaging\TransientGatewayException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a registration code to the contact details the Registrar holds for a record (FR-2): WhatsApp or
 * SMS to the phone on record (through the normal notification service, so opt-outs and the message log
 * apply), otherwise the email on record. Never to anything the registering person typed.
 */
class RegistrationCodeSender
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return array{channel: string, hint: string}|null null when the record holds no way to receive a code
     *
     * @throws RegistrationCodeNotSent when the record can receive codes but every route failed for now
     */
    public function send(AlumniProfile $record, string $code): ?array
    {
        $minutes = (int) config('sunates.registration.code_ttl_minutes');
        $temporaryFailure = false;

        if ($this->notifications->targets($record) !== []) {
            try {
                $log = $this->notifications->send($record, MessageTemplate::RegistrationCode, ['code' => $code, 'minutes' => (string) $minutes]);

                if (in_array($log->status, NotificationStatus::delivered(), true)) {
                    return ['channel' => $log->channel->value, 'hint' => self::maskPhone((string) $log->to_number)];
                }
            } catch (TransientGatewayException) {
                $temporaryFailure = true;
            }
        }

        if ($record->email && filter_var($record->email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($record->email)->send(new RegistrationCodeMail((string) $record->first_name, $code, $minutes));

                return ['channel' => 'email', 'hint' => self::maskEmail($record->email)];
            } catch (Throwable $e) {
                report($e);
                $temporaryFailure = true;
            }
        }

        if ($temporaryFailure) {
            throw new RegistrationCodeNotSent;
        }

        return null;
    }

    /** "+256700123456" → "•••••••456": enough for the person to recognise their own number, no more. */
    public static function maskPhone(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return str_repeat('•', 7).substr($digits, -3);
    }

    /** "amina.okello@gmail.com" → "a•••@gmail.com". */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
