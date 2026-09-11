<?php

declare(strict_types=1);

namespace WhatsAppMfa\Libraries;

/**
 * Resolves the current Config\WhatsAppMfa::$channel ('whatsapp' or
 * 'sms') to a human-readable label, and substitutes it into a
 * {channel} placeholder in a language string - so every user-facing
 * string can say "we'll send a code to your {channel} number" once,
 * rather than hardcoding "WhatsApp" and becoming wrong the moment
 * $channel is switched to 'sms'.
 *
 * Deliberately a plain, PSR-4-autoloaded class - NOT a global helper
 * function requiring an explicit helper() call before use. This
 * package has already hit two real bugs from exactly that pattern (see
 * WhatsAppMfa::verify()'s and PhoneNumberStore's own doc comments on
 * the random_string()/helper('text') incident) - a class needs no such
 * step at all, sidestepping the entire failure mode.
 */
class ChannelLabel
{
    /**
     * The current channel's own display label, translated - "WhatsApp"
     * or whatever Config\WhatsAppMfa.channelLabel_sms is set to (a
     * generic "text message" by default, since "SMS" itself reads as
     * slightly technical to some users).
     */
    public static function current(): string
    {
        $channel = config('WhatsAppMfa')->channel;
        $key     = $channel === 'sms' ? 'channelLabel_sms' : 'channelLabel_whatsapp';

        return lang('WhatsAppMfa.' . $key);
    }

    /**
     * Looks up $langKey and replaces any {channel} placeholder in it
     * with current()'s own value. Any OTHER placeholder in that same
     * string (e.g. {phone}) is untouched - still the caller's own
     * responsibility to substitute, exactly as before this class
     * existed.
     */
    public static function inject(string $langKey): string
    {
        return str_replace('{channel}', self::current(), lang($langKey));
    }
}
