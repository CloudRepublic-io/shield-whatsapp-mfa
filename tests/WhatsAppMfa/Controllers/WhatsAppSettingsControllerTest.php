<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\WhatsAppMfa\Support\FakeTwilioWhatsAppSender;
use Tests\WhatsAppMfa\Support\FakeWhatsAppSender;
use WhatsAppMfa\Controllers\WhatsAppSettingsController;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests the settings controller by calling its methods directly, via
 * initController() (as CodeIgniter's own Controller lifecycle would),
 * rather than through a full HTTP round-trip - the same approach used
 * throughout this series of packages, given the unresolved issue
 * documented in shield-totp-mfa's own tests where Shield's registered
 * routes returned PageNotFoundException through FeatureTestTrait for
 * reasons never fully root-caused.
 *
 * Uses actingAs() - this controller is for an already-fully-logged-in
 * user managing their own settings, not someone mid-login, so
 * auth()->user() (what actingAs() sets up) is the correct state here,
 * not getPendingUser().
 */
final class WhatsAppSettingsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AuthenticationTesting;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Defensive: this controller's own views use url_to(), which
        // needs a populated route collection - a call to resetServices()
        // anywhere earlier in the same PHPUnit process (this package's
        // own WhatsAppActivatorTest calls it) wipes it. loadRoutes() is
        // safe to call even if routes are already loaded. See
        // shield-totp-mfa's RequireFreshTotpTest for the identical fix
        // applied for the identical reason.
        Services::routes()->loadRoutes();

        config('WhatsAppMfa')->sender = FakeWhatsAppSender::class;
        FakeWhatsAppSender::reset();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'whatsapp-settings-test-' . uniqid() . '@example.com',
            'username' => 'whatsappsettingstest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function makeController(array $post = []): WhatsAppSettingsController
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);

        $controller = new WhatsAppSettingsController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    public function testIndexRendersWithNoVerifiedNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $body = $this->makeController()->index();

        $this->assertStringContainsString(lang('WhatsAppMfa.noPhoneSet'), $body);
    }

    public function testIndexRendersTheVerifiedNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);

        $body = $this->makeController()->index();

        $this->assertStringContainsString('+15551234567', $body);
    }

    public function testSendRejectsAnObviouslyInvalidNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => 'not a phone number'])->send();

        $this->assertNotEmpty(session('error'));
        $this->assertSame(0, FakeWhatsAppSender::$sendCount);
    }

    public function testSendDispatchesACodeViaTheConfiguredSender(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->send();

        $this->assertSame(1, FakeWhatsAppSender::$sendCount);
        $this->assertSame('+15551234567', FakeWhatsAppSender::$lastPhoneNumber);
        $this->assertNotNull(FakeWhatsAppSender::$lastCode);
    }

    /**
     * THE regression test for a real, confirmed report - see
     * WhatsAppActivator's own identical fix (in this same package) for
     * the fuller account.
     */
    public function testSendRollsBackThePendingRecordWhenTheSenderFails(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        FakeWhatsAppSender::$shouldFail = true;

        $this->makeController(['phone' => '+15551234567'])->send();

        $store = new PhoneNumberStore();
        $this->assertNull($store->getPendingPhoneNumber($user));
        $this->assertNotEmpty(session('error'));
    }

    public function testVerifyRedirectsToEnrollWithNoPendingAttempt(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $result = $this->makeController()->verify();

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testVerifyRendersOnceASendHasHappened(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->send();
        $body = $this->makeController()->verify();

        $this->assertIsString($body);
        $this->assertStringContainsString('1234567', $body);
    }

    public function testConfirmWithCorrectCodeVerifiesTheNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->send();
        $code = FakeWhatsAppSender::$lastCode;

        $this->makeController(['code' => $code])->confirm();

        $store = new PhoneNumberStore();
        $this->assertTrue($store->hasVerifiedPhoneNumber($user));
        $this->assertSame('+15551234567', $store->getVerifiedPhoneNumber($user));
    }

    public function testConfirmWithWrongCodeDoesNotVerify(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->send();

        $this->makeController(['code' => '000000'])->confirm();

        $store = new PhoneNumberStore();
        $this->assertFalse($store->hasVerifiedPhoneNumber($user));
    }

    public function testDisableRemovesTheVerifiedNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);

        $this->makeController()->disable();

        $this->assertFalse($store->hasVerifiedPhoneNumber($user));
    }

    // -------------------------------------------------------------------
    // Testing WhatsApp delivery ahead of a $channel migration - see
    // PhoneNumberStore's own doc comment for the full account of why
    // this flow exists. FakeTwilioWhatsAppSender (not the more general
    // FakeWhatsAppSender used everywhere above) is used throughout
    // this section specifically because it's the only fake that
    // passes whatsAppTestIsRelevant()'s own is_a() check.
    // -------------------------------------------------------------------

    private function makeTestFlowRelevant(): void
    {
        config('WhatsAppMfa')->channel = 'sms';
        config('WhatsAppMfa')->sender  = FakeTwilioWhatsAppSender::class;
        FakeTwilioWhatsAppSender::reset();
    }

    public function testIndexDoesNotOfferTheTestFlowWhenChannelIsAlreadyWhatsApp(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('WhatsAppMfa')->channel = 'whatsapp';
        config('WhatsAppMfa')->sender  = FakeTwilioWhatsAppSender::class;

        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);

        $body = $this->makeController()->index();

        $this->assertStringNotContainsString(lang('WhatsAppMfa.testButton'), $body);
    }

    public function testTestEnrollRedirectsAwayWhenNotRelevant(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('WhatsAppMfa')->channel = 'whatsapp'; // not relevant - already on WhatsApp

        $response = $this->makeController()->testEnroll();

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testTestSendDispatchesViaWhatsAppRegardlessOfConfiguredChannel(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeTestFlowRelevant();

        $this->makeController(['phone' => '+15551234567'])->testSend();

        $this->assertSame('+15551234567', FakeTwilioWhatsAppSender::$lastPhoneNumber);
        $this->assertNotNull(FakeTwilioWhatsAppSender::$lastCode);
        // THE regression point: even though $channel is 'sms', the
        // forced-WhatsApp send must still have used 'whatsapp'.
        $this->assertSame('whatsapp', FakeTwilioWhatsAppSender::$lastChannelUsed);
    }

    public function testTestSendRollsBackThePendingTestWhenTheSenderFails(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeTestFlowRelevant();
        FakeTwilioWhatsAppSender::$shouldFail = true;

        $this->makeController(['phone' => '+15551234567'])->testSend();

        $store = new PhoneNumberStore();
        $this->assertNull($store->getWhatsAppTestPendingPhoneNumber($user));
        $this->assertNotEmpty(session('error'));
    }

    public function testTestConfirmWithCorrectCodeMarksWhatsAppDeliveryConfirmed(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeTestFlowRelevant();

        $store = new PhoneNumberStore();
        $verifyCode = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $verifyCode);

        $testCode = $store->beginWhatsAppTest($user, '+15551234567');

        $this->makeController(['code' => $testCode])->testConfirm();

        $this->assertTrue($store->hasConfirmedWhatsAppDelivery($user));
    }

    public function testTestConfirmWithWrongCodeFailsGracefully(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeTestFlowRelevant();

        $store = new PhoneNumberStore();
        $store->beginWhatsAppTest($user, '+15551234567');

        $this->makeController(['code' => '000000'])->testConfirm();

        $this->assertFalse($store->hasConfirmedWhatsAppDelivery($user));
    }

    /**
     * THE regression test for a real, confirmed gap: a sender failure
     * previously showed only a generic message with no way to tell
     * what actually went wrong (an invalid sender number, a missing
     * Content Template, an un-joined WhatsApp sandbox recipient, etc.)
     * without adding temporary debugging code first. Confirms the base,
     * safe behavior specifically - this test's own environment is
     * 'testing', not 'development' (PHP's ENVIRONMENT constant can't be
     * changed at runtime to test the development-only branch directly
     * in this same process), so this confirms no diagnostic detail
     * leaks into the message shown outside a development environment,
     * which is the part that actually matters for production safety.
     */
    public function testTestSendFailureMessageDoesNotLeakDiagnosticDetailOutsideDevelopment(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeTestFlowRelevant();
        FakeTwilioWhatsAppSender::$shouldFail = true;

        $this->makeController(['phone' => '+15551234567'])->testSend();

        $this->assertSame(lang('WhatsAppMfa.testSendFailedMessage'), session('error'));
        $this->assertStringNotContainsString('[diagnostic:', (string) session('error'));
    }
}
