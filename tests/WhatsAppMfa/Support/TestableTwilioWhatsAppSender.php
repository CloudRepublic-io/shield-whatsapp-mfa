<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Support;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Sender\TwilioWhatsAppSender;

/**
 * A thin subclass existing purely to expose
 * TwilioWhatsAppSender::buildFields() (protected, deliberately, so a
 * real subclass like this can call it directly) without needing to
 * trigger send()'s own real HTTP call at all.
 */
final class TestableTwilioWhatsAppSender extends TwilioWhatsAppSender
{
    public function exposeBuildFields(string $phoneNumber, string $code, WhatsAppMfaConfig $config): array
    {
        return $this->buildFields($phoneNumber, $code, $config);
    }
}
