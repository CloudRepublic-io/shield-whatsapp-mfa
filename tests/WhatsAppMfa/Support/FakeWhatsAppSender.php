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

    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        self::$lastPhoneNumber = $phoneNumber;
        self::$lastCode        = $code;
        self::$sendCount++;
    }

    public static function reset(): void
    {
        self::$lastPhoneNumber = null;
        self::$lastCode        = null;
        self::$sendCount       = 0;
    }
}
