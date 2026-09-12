<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Support;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use RuntimeException;
use WhatsAppMfa\Sender\TwilioWhatsAppSender;

/**
 * A test double for TwilioWhatsAppSender SPECIFICALLY - not the more
 * general FakeWhatsAppSender (which only implements the plain
 * WhatsAppSenderInterface, and so can't pass
 * WhatsAppSettingsController::whatsAppTestIsRelevant()'s own
 * is_a($sender, TwilioWhatsAppSender::class, true) check).
 *
 * Overrides only send() itself (the actual network call) - inherits
 * sendViaWhatsAppRegardlessOfChannel() and forceWhatsAppChannel()
 * completely unchanged, so a test exercises the REAL config-forcing
 * logic, not a faked version of it, while never making a real HTTP
 * call.
 */
class FakeTwilioWhatsAppSender extends TwilioWhatsAppSender
{
    public static ?string $lastPhoneNumber = null;
    public static ?string $lastCode        = null;
    public static ?string $lastChannelUsed = null;
    public static bool $shouldFail         = false;

    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        if (self::$shouldFail) {
            throw new RuntimeException('FakeTwilioWhatsAppSender: simulated send failure.');
        }

        self::$lastPhoneNumber = $phoneNumber;
        self::$lastCode        = $code;
        // Records what $config->channel actually was at the moment
        // send() ran - the key thing a test needs to confirm
        // sendViaWhatsAppRegardlessOfChannel() genuinely forced it to
        // 'whatsapp', regardless of what the ORIGINAL config's channel
        // was set to.
        self::$lastChannelUsed = $config->channel;
    }

    public static function reset(): void
    {
        self::$lastPhoneNumber = null;
        self::$lastCode        = null;
        self::$lastChannelUsed = null;
        self::$shouldFail      = false;
    }
}
