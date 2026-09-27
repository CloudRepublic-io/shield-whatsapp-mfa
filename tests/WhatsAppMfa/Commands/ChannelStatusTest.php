<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Commands;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests `php spark whatsapp-mfa:channel-status` via CI4's own
 * documented StreamFilterTrait, capturing real CLI output rather than
 * just checking listVerificationStatuses() in isolation (already
 * covered directly in PhoneNumberStoreTest) - confirms the command
 * itself actually reads that data and renders it correctly.
 */
final class ChannelStatusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // only migrates that one namespace - not Shield's tables, so every
    // test here failed with "Table 'users' doesn't exist". null migrates
    // every registered namespace, like `php spark migrate --all`, which
    // is what every other DB-backed test in this package already uses.
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The Settings library's DatabaseHandler caches every value it has
        // read in memory on the shared 'settings' service - which is where
        // PhoneNumberStore keeps verified numbers. $refresh resets the
        // database between tests, but not that cache, and user ids restart
        // at 1 after each refresh - so a number verified for "user:1" in
        // one test was still returned for a brand-new user:1 in the next.
        // A fresh service per test reads the freshly-reset database.
        \CodeIgniter\Config\Services::resetSingle('settings');
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'channel-status-test-' . uniqid() . '@example.com',
            'username' => 'channelstatustest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    public function testReportsNoUsersWhenNoneHaveAVerifiedNumber(): void
    {
        command('whatsapp-mfa:channel-status');

        $this->assertStringContainsString('No users with a verified phone number', $this->getStreamFilterBuffer());
    }

    public function testListsAConfirmedUserCorrectly(): void
    {
        $user  = $this->makeUser();
        $store = new PhoneNumberStore();

        $verifyCode = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $verifyCode);
        $testCode = $store->beginWhatsAppTest($user, '+15551234567');
        $store->confirmWhatsAppTest($user, $testCode);

        command('whatsapp-mfa:channel-status');

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('+15551234567', $output);
        $this->assertStringContainsString('confirmed', $output);
        $this->assertStringContainsString('1 confirmed, 0 not yet confirmed, 1 total.', $output);
    }

    public function testListsAnUnconfirmedUserCorrectly(): void
    {
        $user  = $this->makeUser();
        $store = new PhoneNumberStore();

        $verifyCode = $store->beginVerification($user, '+15559876543');
        $store->confirmVerification($user, $verifyCode);

        command('whatsapp-mfa:channel-status');

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('+15559876543', $output);
        $this->assertStringContainsString('0 confirmed, 1 not yet confirmed, 1 total.', $output);
    }

    public function testUnconfirmedOnlyOptionExcludesConfirmedUsers(): void
    {
        $confirmedUser   = $this->makeUser();
        $unconfirmedUser = $this->makeUser();
        $store           = new PhoneNumberStore();

        $code1 = $store->beginVerification($confirmedUser, '+15551110000');
        $store->confirmVerification($confirmedUser, $code1);
        $testCode = $store->beginWhatsAppTest($confirmedUser, '+15551110000');
        $store->confirmWhatsAppTest($confirmedUser, $testCode);

        $code2 = $store->beginVerification($unconfirmedUser, '+15552220000');
        $store->confirmVerification($unconfirmedUser, $code2);

        command('whatsapp-mfa:channel-status --unconfirmed-only');

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('+15552220000', $output);
        $this->assertStringNotContainsString('+15551110000', $output);
        // The summary counts still reflect the TRUE totals, not just
        // what was filtered into the table itself.
        $this->assertStringContainsString('1 confirmed, 1 not yet confirmed, 2 total.', $output);
    }
}
