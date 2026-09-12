<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Support;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Sender\WhatsAppSenderInterface;

/**
 * Records what would have been sent instead of making a real network
 * call to Meta/Twilio - point Config\WhatsAppMfa::$sender at this
 * during tests so nothing actually leaves the machine.
 */
class FakeWhatsAppSender implements WhatsAppSenderInterface
{
    public static ?string $lastPhoneNumber = null;
    public static ?string $lastCode        = null;
    public static int $sendCount           = 0;

    /**
     * Set true to make send() throw instead of "sending" - simulates a
     * real Twilio/Meta API failure, for testing the rollback behavior
     * added specifically for that case (a confirmed, real report of an
     * orphaned pending/identity record left behind by an uncaught
     * sender failure - see WhatsAppActivator's own doc comment for the
     * full account).
     */
    public static bool $shouldFail = false;

    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        if (self::$shouldFail) {
            throw new \RuntimeException('FakeWhatsAppSender: simulated send failure.');
        }

        self::$lastPhoneNumber = $phoneNumber;
        self::$lastCode        = $code;
        self::$sendCount++;
    }

    public static function reset(): void
    {
        self::$lastPhoneNumber = null;
        self::$lastCode        = null;
        self::$sendCount       = 0;
        self::$shouldFail      = false;
    }
}
