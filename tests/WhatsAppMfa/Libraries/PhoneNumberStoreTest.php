<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests PhoneNumberStore's pending-verification-to-permanent-record
 * lifecycle in isolation - no sender/network involvement at all, since
 * this class only ever deals with the code once it already exists
 * (beginVerification() generates and stores it; the caller, e.g.
 * WhatsAppSettingsController, is what actually sends it).
 *
 * testTwoAccountsCanVerifyTheSamePhoneNumberWithoutCollision() and
 * testTwoUsersEnsuringAnActivationMarkerSimultaneouslyDoNotCollide()
 * are regression tests for real, confirmed bugs - see
 * PhoneNumberStore's own class doc comment (verified phone) and
 * ensureActivationMarker()'s own doc comment (activation marker) for
 * the full explanation of each.
 */
final class PhoneNumberStoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

    private PhoneNumberStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PhoneNumberStore();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'phone-test-' . uniqid() . '@example.com',
            'username' => 'phonetest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    public function testNoVerifiedPhoneByDefault(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($this->store->hasVerifiedPhoneNumber($user));
        $this->assertNull($this->store->getVerifiedPhoneNumber($user));
    }

    /**
     * Regression test for a real, confirmed bug: beginVerification()
     * used to call CI4's random_string() text helper, which isn't
     * autoloaded by default - this class never called helper('text')
     * to load it, causing "Call to undefined function
     * WhatsAppMfa\Libraries\random_string()" in production. Every
     * other test in this file calls beginVerification() too and
     * should, in principle, have already caught this via a fatal
     * error rather than needing a dedicated test - the fact none of
     * them did suggests something else in the shared PHPUnit process
     * happened to load the 'text' helper first (PHP function
     * definitions, once loaded, stay loaded for the rest of that
     * process), masking the bug here while it broke a real app
     * directly. Fixed by removing the dependency on that helper
     * entirely (see generateCode()'s own doc comment) rather than
     * just adding the missing helper() call, which would still leave
     * this fragile to load order.
     */
    public function testBeginVerificationReturnsAWellFormedCode(): void
    {
        $user = $this->makeUser();

        $code = $this->store->beginVerification($user, '+15551234567');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString('0', $code);
    }

    public function testBeginVerificationCreatesAPendingRecordButNotAVerifiedOne(): void
    {
        $user = $this->makeUser();

        $this->store->beginVerification($user, '+15551234567');

        $this->assertFalse($this->store->hasVerifiedPhoneNumber($user));
        $this->assertSame('+15551234567', $this->store->getPendingPhoneNumber($user));
        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_PENDING,
        ]);
    }

    public function testStartingASecondVerificationReplacesThePendingOne(): void
    {
        $user = $this->makeUser();

        $this->store->beginVerification($user, '+15551111111');
        $this->store->beginVerification($user, '+15552222222');

        $this->assertSame('+15552222222', $this->store->getPendingPhoneNumber($user));
        $this->seeNumRecords(1, 'auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_PENDING,
        ]);
    }

    public function testWrongCodeFailsGracefullyAndChangesNothing(): void
    {
        $user = $this->makeUser();
        $this->store->beginVerification($user, '+15551234567');

        $result = $this->store->confirmVerification($user, '000000');

        $this->assertFalse($result);
        $this->assertFalse($this->store->hasVerifiedPhoneNumber($user));
        // The pending attempt is still there - a wrong guess shouldn't
        // discard it, only a correct one or a fresh beginVerification() should.
        $this->assertSame('+15551234567', $this->store->getPendingPhoneNumber($user));
    }

    public function testCorrectCodePromotesToAPermanentVerifiedRecord(): void
    {
        $user = $this->makeUser();
        $code = $this->store->beginVerification($user, '+15551234567');

        $result = $this->store->confirmVerification($user, $code);

        $this->assertTrue($result);
        $this->assertTrue($this->store->hasVerifiedPhoneNumber($user));
        $this->assertSame('+15551234567', $this->store->getVerifiedPhoneNumber($user));
        $this->assertNull($this->store->getPendingPhoneNumber($user));
        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_PENDING,
        ]);
    }

    public function testVerifyingANewNumberReplacesThePreviousVerifiedOne(): void
    {
        $user      = $this->makeUser();
        $firstCode = $this->store->beginVerification($user, '+15551111111');
        $this->store->confirmVerification($user, $firstCode);

        $secondCode = $this->store->beginVerification($user, '+15552222222');
        $this->store->confirmVerification($user, $secondCode);

        // A settings context holds exactly one current value per key,
        // not a history of every value ever set - re-fetching via a
        // fresh call (not reusing any cached reference) confirms this
        // isn't just appearing correct because of in-memory state.
        $this->assertSame('+15552222222', $this->store->getVerifiedPhoneNumber($user));
    }

    public function testExpiredPendingCodeFailsGracefullyAndIsRemoved(): void
    {
        $user = $this->makeUser();
        $code = $this->store->beginVerification($user, '+15551234567');

        $identityModel = model(UserIdentityModel::class);
        $pending       = $identityModel
            ->where('user_id', $user->id)
            ->where('type', PhoneNumberStore::ID_TYPE_PHONE_PENDING)
            ->first();
        $identityModel->update($pending->id, ['expires' => date('Y-m-d H:i:s', time() - 60)]);

        $result = $this->store->confirmVerification($user, $code);

        $this->assertFalse($result);
        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_PENDING,
        ]);
    }

    public function testConfirmVerificationFailsGracefullyWithNoPendingAttempt(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($this->store->confirmVerification($user, '123456'));
    }

    public function testCancelVerificationRemovesThePendingAttempt(): void
    {
        $user = $this->makeUser();
        $this->store->beginVerification($user, '+15551234567');

        $this->store->cancelVerification($user);

        $this->assertNull($this->store->getPendingPhoneNumber($user));
    }

    public function testRemoveVerifiedPhoneNumberClearsIt(): void
    {
        $user = $this->makeUser();
        $code = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $code);

        $this->store->removeVerifiedPhoneNumber($user);

        $this->assertFalse($this->store->hasVerifiedPhoneNumber($user));
    }

    public function testVerifiedPhoneIsPerUser(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $codeA = $this->store->beginVerification($userA, '+15551111111');
        $this->store->confirmVerification($userA, $codeA);

        $this->assertTrue($this->store->hasVerifiedPhoneNumber($userA));
        $this->assertFalse($this->store->hasVerifiedPhoneNumber($userB));
    }

    // -------------------------------------------------------------------
    // Step-up challenges - a dedicated identity type
    // (ID_TYPE_PHONE_STEP_UP), deliberately separate from the phone
    // verification flow's own type. See this class's own doc comment
    // for why that separation matters.
    // -------------------------------------------------------------------

    public function testBeginStepUpChallengeReturnsNullWithNoVerifiedNumber(): void
    {
        $user = $this->makeUser(); // deliberately never verified

        $this->assertNull($this->store->beginStepUpChallenge($user));
    }

    public function testBeginStepUpChallengeReturnsAWellFormedCodeForAVerifiedUser(): void
    {
        $user = $this->makeUser();
        $verifyCode = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $verifyCode);

        $challengeCode = $this->store->beginStepUpChallenge($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $challengeCode);
        $this->assertStringNotContainsString('0', $challengeCode);
    }

    public function testCorrectStepUpCodeVerifiesAndConsumesTheChallenge(): void
    {
        $user       = $this->makeUser();
        $verifyCode = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $verifyCode);

        $challengeCode = $this->store->beginStepUpChallenge($user);

        $this->assertTrue($this->store->verifyStepUpChallenge($user, $challengeCode));
        // Consumed - the same code can't be replayed a second time.
        $this->assertFalse($this->store->verifyStepUpChallenge($user, $challengeCode));
    }

    public function testWrongStepUpCodeFailsGracefully(): void
    {
        $user       = $this->makeUser();
        $verifyCode = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $verifyCode);

        $this->store->beginStepUpChallenge($user);

        $this->assertFalse($this->store->verifyStepUpChallenge($user, '000000'));
    }

    public function testVerifyStepUpChallengeFailsGracefullyWithNoPendingChallenge(): void
    {
        $user       = $this->makeUser();
        $verifyCode = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $verifyCode);

        $this->assertFalse($this->store->verifyStepUpChallenge($user, '123456'));
    }

    public function testStepUpChallengeDoesNotAffectAnInProgressPhoneVerification(): void
    {
        $user = $this->makeUser();
        $verifyCode = $this->store->beginVerification($user, '+15551234567');
        $this->store->confirmVerification($user, $verifyCode);

        // A DIFFERENT, in-progress phone change attempt, started after
        // the number was already verified.
        $changeCode = $this->store->beginVerification($user, '+15559999999');

        $challengeCode = $this->store->beginStepUpChallenge($user);

        // The step-up challenge doesn't disturb the separate
        // in-progress phone-change attempt, or vice versa.
        $this->assertSame('+15559999999', $this->store->getPendingPhoneNumber($user));
        $this->assertTrue($this->store->verifyStepUpChallenge($user, $challengeCode));
        $this->assertSame('+15559999999', $this->store->getPendingPhoneNumber($user));
    }

    // -------------------------------------------------------------------
    // Regression tests for real, confirmed bugs - see PhoneNumberStore's
    // own doc comment (verified phone) and ensureActivationMarker()'s
    // own doc comment (activation marker) for the full explanation of
    // each.
    // -------------------------------------------------------------------

    /**
     * THE regression test for the verified-phone-number bug. Two
     * DIFFERENT accounts both verifying the SAME phone number (one
     * person with two accounts, or a shared family phone) used to
     * throw a duplicate-key database error on the second account's own
     * verification, since the old storage put the actual phone number
     * in Shield's own auth_identities.secret column, which has a
     * UNIQUE(type, secret) constraint - not (user_id, type, secret).
     */
    public function testTwoAccountsCanVerifyTheSamePhoneNumberWithoutCollision(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        // Neither of these should throw - a duplicate-key database
        // exception here would mean the regression is back.
        $codeA = $this->store->beginVerification($userA, '+15551234567');
        $this->store->confirmVerification($userA, $codeA);

        $codeB = $this->store->beginVerification($userB, '+15551234567');
        $this->store->confirmVerification($userB, $codeB);

        $this->assertSame('+15551234567', $this->store->getVerifiedPhoneNumber($userA));
        $this->assertSame('+15551234567', $this->store->getVerifiedPhoneNumber($userB));
    }

    /**
     * THE regression test for the activation-marker bug. Two DIFFERENT
     * users both mid-registration at the same time (neither having
     * finished yet) used to throw a duplicate-key database error on the
     * second one, since the old marker stored a FIXED literal string
     * ('n/a') as its secret, regardless of which user or phone number
     * was involved - this one needed no coincidence at all, unlike the
     * phone-number one above.
     */
    public function testTwoUsersEnsuringAnActivationMarkerSimultaneouslyDoNotCollide(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        // Neither of these should throw.
        $this->store->ensureActivationMarker($userA);
        $this->store->ensureActivationMarker($userB);

        $this->seeInDatabase('auth_identities', [
            'user_id' => $userA->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE,
        ]);
        $this->seeInDatabase('auth_identities', [
            'user_id' => $userB->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE,
        ]);
    }

    // -------------------------------------------------------------------
    // Testing WhatsApp delivery ahead of a $channel migration - see this
    // class's own doc comment for the full account of why this exists.
    // -------------------------------------------------------------------

    public function testHasNotConfirmedWhatsAppDeliveryByDefault(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($this->store->hasConfirmedWhatsAppDelivery($user));
        $this->assertNull($this->store->getConfirmedWhatsAppNumber($user));
    }

    public function testBeginWhatsAppTestCreatesAPendingRecordSeparateFromTheNormalOne(): void
    {
        $user = $this->makeUser();

        // A normal "change my number" attempt already in progress...
        $this->store->beginVerification($user, '+15551110000');

        // ...must not be disturbed by starting a channel test.
        $code = $this->store->beginWhatsAppTest($user, '+15552220000');

        $this->assertMatchesRegularExpression('/^[1-9]{6}$/', $code);
        $this->assertSame('+15552220000', $this->store->getWhatsAppTestPendingPhoneNumber($user));
        $this->assertSame('+15551110000', $this->store->getPendingPhoneNumber($user));
    }

    public function testCorrectCodeConfirmsWhatsAppDeliveryWithoutTouchingTheVerifiedNumber(): void
    {
        $user = $this->makeUser();

        // An existing, working verified number from a prior SMS
        // verification.
        $existingCode = $this->store->beginVerification($user, '+15551110000');
        $this->store->confirmVerification($user, $existingCode);

        $testCode = $this->store->beginWhatsAppTest($user, '+15551110000');
        $confirmed = $this->store->confirmWhatsAppTest($user, $testCode);

        $this->assertTrue($confirmed);
        $this->assertTrue($this->store->hasConfirmedWhatsAppDelivery($user));
        // THE regression point: the main verified number is completely
        // unaffected by a channel test, successful or not.
        $this->assertSame('+15551110000', $this->store->getVerifiedPhoneNumber($user));
    }

    /**
     * THE regression test for the actual point of storing the
     * confirmed NUMBER, not just a boolean: confirming WhatsApp works
     * for number A, then changing the verified number to B, must
     * correctly report "not confirmed" for B - it was never tested.
     */
    public function testConfirmationBecomesStaleWhenTheVerifiedNumberLaterChanges(): void
    {
        $user = $this->makeUser();

        $firstCode = $this->store->beginVerification($user, '+15551110000');
        $this->store->confirmVerification($user, $firstCode);

        $testCode = $this->store->beginWhatsAppTest($user, '+15551110000');
        $this->store->confirmWhatsAppTest($user, $testCode);

        $this->assertTrue($this->store->hasConfirmedWhatsAppDelivery($user));

        // User changes their verified number to something never tested.
        $secondCode = $this->store->beginVerification($user, '+15559990000');
        $this->store->confirmVerification($user, $secondCode);

        $this->assertFalse($this->store->hasConfirmedWhatsAppDelivery($user));
        // The stale confirmation is still visible via its own getter,
        // distinctly from "never tested at all" - used by the CLI
        // command to report accurately, not just collapse both into
        // the same "not confirmed" result.
        $this->assertSame('+15551110000', $this->store->getConfirmedWhatsAppNumber($user));
    }

    public function testWrongCodeFailsWhatsAppTestGracefullyAndChangesNothing(): void
    {
        $user = $this->makeUser();
        $this->store->beginWhatsAppTest($user, '+15551110000');

        $this->assertFalse($this->store->confirmWhatsAppTest($user, '000000'));
        $this->assertFalse($this->store->hasConfirmedWhatsAppDelivery($user));
    }

    public function testCancelWhatsAppTestRemovesThePendingAttempt(): void
    {
        $user = $this->makeUser();
        $this->store->beginWhatsAppTest($user, '+15551110000');

        $this->store->cancelWhatsAppTest($user);

        $this->assertNull($this->store->getWhatsAppTestPendingPhoneNumber($user));
    }

    public function testRemovingTheVerifiedNumberClearsAnyWhatsAppConfirmationToo(): void
    {
        $user = $this->makeUser();

        $verifyCode = $this->store->beginVerification($user, '+15551110000');
        $this->store->confirmVerification($user, $verifyCode);

        $testCode = $this->store->beginWhatsAppTest($user, '+15551110000');
        $this->store->confirmWhatsAppTest($user, $testCode);

        $this->assertTrue($this->store->hasConfirmedWhatsAppDelivery($user));

        $this->store->removeVerifiedPhoneNumber($user);

        $this->assertNull($this->store->getConfirmedWhatsAppNumber($user));
    }

    public function testListVerificationStatusesReflectsMultipleUsers(): void
    {
        $confirmedUser   = $this->makeUser();
        $unconfirmedUser = $this->makeUser();

        $code1 = $this->store->beginVerification($confirmedUser, '+15551110000');
        $this->store->confirmVerification($confirmedUser, $code1);
        $testCode = $this->store->beginWhatsAppTest($confirmedUser, '+15551110000');
        $this->store->confirmWhatsAppTest($confirmedUser, $testCode);

        $code2 = $this->store->beginVerification($unconfirmedUser, '+15552220000');
        $this->store->confirmVerification($unconfirmedUser, $code2);

        $statuses = $this->store->listVerificationStatuses();
        $byUserId = [];

        foreach ($statuses as $status) {
            $byUserId[$status['user_id']] = $status;
        }

        $this->assertArrayHasKey($confirmedUser->id, $byUserId);
        $this->assertArrayHasKey($unconfirmedUser->id, $byUserId);
        $this->assertTrue($byUserId[$confirmedUser->id]['whatsapp_confirmed']);
        $this->assertFalse($byUserId[$unconfirmedUser->id]['whatsapp_confirmed']);
        $this->assertSame('+15551110000', $byUserId[$confirmedUser->id]['verified_number']);
    }
}
