<?php

declare(strict_types=1);

namespace WhatsAppMfa\Sender;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use RuntimeException;

/**
 * Sends the OTP using Twilio's Messages API
 * (https://www.twilio.com/docs/whatsapp), via either the WhatsApp
 * channel or plain SMS, per Config\WhatsAppMfa::$channel - an app-wide
 * toggle, not a per-user choice.
 *
 * Env vars used:
 *   whatsAppMfa.twilioSid        = "ACxxxxxxxx"
 *   whatsAppMfa.twilioAuthToken  = "xxxxxxxx"
 *   whatsAppMfa.twilioFromNumber = "whatsapp:+14155238886" (or plain "+14155238886" - see below)
 *
 * Note: like Meta, Twilio requires a pre-approved Content Template for
 * proactive/authentication WhatsApp messages sent outside a 24h
 * user-initiated session window. Set the template SID via
 * whatsAppMfa.twilioContentSid, or leave it blank to send free-form
 * (only works inside the 24h window). SMS has no equivalent
 * restriction at all - it can always send free-form text - so
 * twilioContentSid is simply ignored whenever $channel is 'sms',
 * regardless of whether it's set.
 *
 * $twilioFromNumber is used for BOTH channels - this class strips or
 * adds the 'whatsapp:' prefix on that same configured value as needed,
 * rather than requiring a second, separate number configured
 * specifically for SMS. This assumes the same underlying Twilio number
 * is capable of both channels, which is common (many Twilio numbers
 * are both WhatsApp-enabled and SMS-capable) but not universal - if
 * yours genuinely are two different numbers, update
 * $twilioFromNumber itself to the SMS-capable one before switching
 * $channel to 'sms', rather than expecting this class to know about a
 * second number it was never given.
 */
class TwilioWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        if ($config->twilioSid === '' || $config->twilioAuthToken === '') {
            throw new RuntimeException(
                'TwilioWhatsAppSender: set whatsAppMfa.twilioSid and whatsAppMfa.twilioAuthToken in your .env file.'
            );
        }

        $url    = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $config->twilioSid);
        $fields = $this->buildFields($phoneNumber, $code, $config);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $config->twilioSid . ':' . $config->twilioAuthToken,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('TwilioWhatsAppSender: cURL error - ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                sprintf('TwilioWhatsAppSender: Twilio API returned HTTP %d - %s', $httpCode, $response)
            );
        }
    }

    /**
     * Builds the exact POST fields for Twilio's Messages API, entirely
     * separately from actually sending them - deliberately `protected`
     * (not `private`) specifically so a test can call this directly via
     * a thin subclass, verifying the channel-dependent To/From/Body
     * logic without needing a real or mocked HTTP call at all. `send()`
     * above is the only part of this class that genuinely needs a real
     * network dependency; this method has none.
     */
    protected function buildFields(string $phoneNumber, string $code, WhatsAppMfaConfig $config): array
    {
        $isWhatsApp = $config->channel !== 'sms';
        $to         = $this->normalizeNumber($phoneNumber);
        $from       = $this->normalizeFromNumber($config->twilioFromNumber);

        if ($isWhatsApp) {
            $to   = 'whatsapp:' . $to;
            $from = 'whatsapp:' . $from;
        }

        $fields = [
            'From' => $from,
            'To'   => $to,
        ];

        // WhatsApp's own Content Template requirement has no SMS
        // equivalent - SMS always sends a plain Body, regardless of
        // whether twilioContentSid happens to be configured, since
        // it's meaningless (and unsupported by Twilio's own SMS API)
        // outside the WhatsApp channel.
        if ($isWhatsApp && $config->twilioContentSid !== '') {
            $fields['ContentSid']       = $config->twilioContentSid;
            $fields['ContentVariables'] = json_encode(['1' => $code], JSON_THROW_ON_ERROR);
        } else {
            $fields['Body'] = sprintf($config->plainMessageTemplate, $code);
        }

        return $fields;
    }

    private function normalizeNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phoneNumber);

        return str_starts_with($digits, '+') ? $digits : '+' . ltrim($digits, '0');
    }

    /**
     * $config->twilioFromNumber may already carry a 'whatsapp:' prefix
     * (this package's own established convention, predating $channel)
     * - stripped here unconditionally, then re-added by buildFields()
     * above only for the WhatsApp channel specifically. This means the
     * same configured value works correctly regardless of whether it
     * was originally written with or without the prefix, and
     * regardless of which channel is currently active.
     */
    private function normalizeFromNumber(string $fromNumber): string
    {
        return preg_replace('/^whatsapp:/', '', $fromNumber);
    }
}
