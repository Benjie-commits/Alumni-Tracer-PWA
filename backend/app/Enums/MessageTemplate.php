<?php

namespace App\Enums;

/**
 * Every message the system can send. SMS text is rendered here; WhatsApp sends the pre-approved
 * template named in config (Meta requires approval for business-initiated messages), filled with the
 * same parameters in the order given by whatsappParams().
 */
enum MessageTemplate: string
{
    case SurveyInvite = 'survey_invite';
    case SurveyReminder = 'survey_reminder';
    case ProfileNudge = 'profile_nudge';
    case RegisterInvite = 'register_invite';
    case Test = 'test';

    /**
     * @param  array<string, string>  $p  first_name, url, stop_url, period (e.g. "6 months")
     */
    public function sms(array $p): string
    {
        $name = $p['first_name'] ?? 'there';

        return match ($this) {
            self::SurveyInvite => "Soroti University: Hi {$name}, please tell us how you are doing {$p['period']} after graduating (2 min): {$p['url']} Stop: {$p['stop_url']}",
            self::SurveyReminder => "Soroti University: {$name}, your graduate survey is still open. It takes 2 minutes: {$p['url']} Stop: {$p['stop_url']}",
            self::ProfileNudge => "Soroti University: Hi {$name}, please confirm your alumni details are up to date: {$p['url']} Stop: {$p['stop_url']}",
            self::RegisterInvite => "Soroti University: Hi {$name}, join the alumni network and keep your details current: {$p['url']} Stop: {$p['stop_url']}",
            self::Test => 'Soroti University SUN-ATES test message. If you can read this, SMS delivery is working.',
        };
    }

    /**
     * Ordered body parameters for the WhatsApp template.
     *
     * @param  array<string, string>  $p
     * @return list<string>
     */
    public function whatsappParams(array $p): array
    {
        return match ($this) {
            self::SurveyInvite => [$p['first_name'] ?? 'there', $p['period'], $p['url']],
            self::SurveyReminder, self::ProfileNudge, self::RegisterInvite => [$p['first_name'] ?? 'there', $p['url']],
            self::Test => [],
        };
    }

    public function whatsappTemplateName(): string
    {
        return (string) config("sunates.messaging.whatsapp_templates.{$this->value}");
    }

    /** Templates that count as a "please update your details" nudge for frequency limits. */
    public static function nudges(): array
    {
        return [self::ProfileNudge, self::RegisterInvite];
    }
}
