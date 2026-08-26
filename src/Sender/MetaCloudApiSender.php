<?php

declare(strict_types=1);

namespace WhatsAppMfa\Sender;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use RuntimeException;

/**
 * Sends the OTP using Meta's official WhatsApp Cloud API
 * (https://developers.facebook.com/docs/whatsapp/cloud-api).
 *
 * Requires an approved authentication-category message template (Meta
 * requires OTP codes to go through a pre-approved template, not free-form
 * text). The template just needs one body variable ({{1}}) for the code.
 *
 * Env vars used (set these in .env, never hardcode secrets):
 *   whatsAppMfa.phoneNumberId = "1234567890"
 *   whatsAppMfa.accessToken  = "EAAG..."
 */
class MetaCloudApiSender implements WhatsAppSenderInterface
{
    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        $phoneNumberId = $config->metaPhoneNumberId;
        $accessToken   = $config->metaAccessToken;

        if ($phoneNumberId === '' || $accessToken === '') {
            throw new RuntimeException(
                'MetaCloudApiSender: set whatsAppMfa.phoneNumberId and whatsAppMfa.accessToken in your .env file.'
            );
        }

        $url = sprintf('https://graph.facebook.com/v20.0/%s/messages', $phoneNumberId);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizeNumber($phoneNumber),
            'type'              => 'template',
            'template'          => [
                'name'     => $config->metaTemplateName,
                'language' => ['code' => $config->metaTemplateLanguage],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $code],
                        ],
                    ],
                    // Some approved OTP templates also require the code
                    // repeated in a "quick reply" / URL button component.
                    // Uncomment and adjust to match your approved template:
                    // [
                    //     'type'    => 'button',
                    //     'sub_type' => 'url',
                    //     'index'   => '0',
                    //     'parameters' => [
                    //         ['type' => 'text', 'text' => $code],
                    //     ],
                    // ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT    => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('MetaCloudApiSender: cURL error - ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                sprintf('MetaCloudApiSender: WhatsApp API returned HTTP %d - %s', $httpCode, $response)
            );
        }
    }

    /**
     * Meta expects numbers without a leading "+" and without spaces/dashes.
     */
    private function normalizeNumber(string $phoneNumber): string
    {
        return ltrim(preg_replace('/[^\d]/', '', $phoneNumber), '0');
    }
}
