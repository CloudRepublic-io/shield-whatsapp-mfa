<?php

declare(strict_types=1);

namespace WhatsAppMfa\Sender;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use RuntimeException;

/**
 * Sends the OTP using Twilio's WhatsApp API
 * (https://www.twilio.com/docs/whatsapp).
 *
 * Env vars used:
 *   whatsAppMfa.twilioSid        = "ACxxxxxxxx"
 *   whatsAppMfa.twilioAuthToken  = "xxxxxxxx"
 *   whatsAppMfa.twilioFromNumber = "whatsapp:+14155238886"
 *
 * Note: like Meta, Twilio requires a pre-approved Content Template for
 * proactive/authentication messages sent outside a 24h user-initiated
 * session window. Set the template SID via whatsAppMfa.twilioContentSid,
 * or leave it blank to send free-form (only works inside the 24h window).
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

        $url = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            $config->twilioSid
        );

        $to = 'whatsapp:' . $this->normalizeNumber($phoneNumber);

        $fields = [
            'From' => $config->twilioFromNumber,
            'To'   => $to,
        ];

        if ($config->twilioContentSid !== '') {
            $fields['ContentSid']       = $config->twilioContentSid;
            $fields['ContentVariables'] = json_encode(['1' => $code], JSON_THROW_ON_ERROR);
        } else {
            $fields['Body'] = sprintf($config->plainMessageTemplate, $code);
        }

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

    private function normalizeNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phoneNumber);

        return str_starts_with($digits, '+') ? $digits : '+' . ltrim($digits, '0');
    }
}
