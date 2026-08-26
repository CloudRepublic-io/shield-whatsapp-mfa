<?php

declare(strict_types=1);

namespace WhatsAppMfa\Sender;

use Config\WhatsAppMfa as WhatsAppMfaConfig;

/**
 * Contract for anything that can deliver a one-time code over WhatsApp.
 *
 * Implement this once per provider (Meta Cloud API, Twilio, 360dialog,
 * Vonage, an in-house gateway, etc.) and point $config->sender at your
 * class. This keeps the Shield Action itself provider-agnostic.
 */
interface WhatsAppSenderInterface
{
    /**
     * Send a one-time code to a phone number over WhatsApp.
     *
     * @param string             $phoneNumber E.164 format, e.g. +15551234567
     * @param string             $code        The plaintext OTP to deliver
     * @param WhatsAppMfaConfig  $config      Plugin config (credentials, template name, etc.)
     *
     * @throws \RuntimeException on delivery failure
     */
    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void;
}
