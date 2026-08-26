<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Support;

use CodeIgniter\Shield\Entities\User;
use WhatsAppMfa\Authentication\Actions\WhatsAppMfa;

/**
 * A WhatsAppMfa subclass that returns a fixed phone number regardless
 * of the actual user, for testing purposes. Shield's stock User entity
 * has no 'phone' column, so resolvePhoneNumber() would return null for
 * a plain fake() user unless your own app has added one - overriding
 * it here keeps the test suite usable out of the box.
 */
class TestableWhatsAppMfa extends WhatsAppMfa
{
    public const TEST_PHONE_NUMBER = '+15551234567';

    protected function resolvePhoneNumber(User $user): ?string
    {
        return self::TEST_PHONE_NUMBER;
    }
}
