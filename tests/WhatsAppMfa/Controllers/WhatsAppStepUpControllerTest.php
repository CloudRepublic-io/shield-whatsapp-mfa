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
use Tests\WhatsAppMfa\Support\FakeWhatsAppSender;
use WhatsAppMfa\Controllers\WhatsAppStepUpController;
use WhatsAppMfa\Libraries\PhoneNumberStore;

// Loaded explicitly rather than autoloaded: a typical CodeIgniter app's
// composer.json only maps Tests\Support\ (to tests/_support), so
// Tests\WhatsAppMfa\Support\* isn't autoloadable, and PHPUnit only
// loads *Test.php files itself.
require_once __DIR__ . '/../Support/FakeWhatsAppSender.php';

/**
 * Tests the step-up controller by calling its methods directly, via
 * initController(), rather than through a full HTTP round-trip - the
 * same approach used throughout this series of packages.
 *
 * Uses actingAs() - this controller is for an already-fully-logged-in
 * user, not someone mid-login, so auth()->user() (what actingAs() sets
 * up) is the correct state here, not getPendingUser().
 */
final class WhatsAppStepUpControllerTest extends CIUnitTestCase
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

        // The Settings library's DatabaseHandler caches every value it has
        // read in memory on the shared 'settings' service - which is where
        // PhoneNumberStore keeps verified numbers. $refresh resets the
        // database between tests, but not that cache, and user ids restart
        // at 1 after each refresh - so a number verified for "user:1" in
        // one test was still returned for a brand-new user:1 in the next.
        // A fresh service per test reads the freshly-reset database.
        \CodeIgniter\Config\Services::resetSingle('settings');

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
            'email'    => 'whatsapp-stepup-test-' . uniqid() . '@example.com',
            'username' => 'wasutest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function verifyPhone(User $user): void
    {
        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);
    }

    private function makeController(array $post = []): WhatsAppStepUpController
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);
        // CodeIgniter 4.7+ reads POST from a shared 'superglobals' snapshot
        // taken the first time anything touches the request, so the
        // $_POST assignment above is invisible to it - setGlobal() works
        // on 4.6 and 4.7 alike.
        $request->setGlobal('post', $post);

        $controller = new WhatsAppStepUpController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    public function testShowRendersForAVerifiedUser(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        $body = $this->makeController()->show();

        // The page shows the number masked to its last four digits
        // (+15551234567 -> ********4567), never the full number.
        $this->assertStringContainsString('********4567', $body);
        $this->assertStringNotContainsString('5551234567', $body);
    }

    public function testSendDispatchesACodeViaTheConfiguredSender(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        $this->makeController()->send();

        $this->assertSame(1, FakeWhatsAppSender::$sendCount);
        $this->assertSame('+15551234567', FakeWhatsAppSender::$lastPhoneNumber);
    }

    /**
     * THE regression test for a real, confirmed report - see
     * WhatsAppActivator's own identical fix (in this same package) for
     * the fuller account. cancelStepUp() (PhoneNumberStore) was added
     * specifically to support this rollback.
     */
    public function testSendRollsBackTheStepUpChallengeWhenTheSenderFails(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        FakeWhatsAppSender::$shouldFail = true;

        $this->makeController()->send();

        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_STEP_UP,
        ]);
        $this->assertNotEmpty(session('error'));
    }

    public function testVerifyWithCorrectCodeStampsTheStepUpSession(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        $this->makeController()->send();
        $code = FakeWhatsAppSender::$lastCode;

        $this->makeController(['code' => $code])->verify();

        $this->assertNotNull(session(config('WhatsAppMfa')->stepUpSessionKey));
    }

    public function testVerifyRedirectsToTheOriginallyRequestedUrl(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        session()->set('whatsapp_step_up_redirect', 'https://example.com/admin/billing');

        $this->makeController()->send();
        $code = FakeWhatsAppSender::$lastCode;

        $response = $this->makeController(['code' => $code])->verify();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.com/admin/billing', $response->getHeaderLine('Location'));
        // Consumed, not left lingering for a future unrelated request.
        $this->assertNull(session('whatsapp_step_up_redirect'));
    }

    public function testWrongCodeDoesNotStampTheStepUpSession(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        $this->makeController()->send();

        $this->makeController(['code' => '000000'])->verify();

        $this->assertNull(session(config('WhatsAppMfa')->stepUpSessionKey));
    }

    /**
     * Regression coverage for the same bug class fixed in the login
     * flow (WhatsAppMfa::verify()) - this controller was written with
     * the fix already in place (redirect()->route(), never back()),
     * but this test exists so a future edit that reintroduces back()
     * here gets caught. See the class doc comment on
     * WhatsAppStepUpController for the full explanation.
     */
    public function testWrongCodeRedirectsToTheNamedStepUpRouteNotBack(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyPhone($user);

        $this->makeController()->send();

        $response = $this->makeController(['code' => '000000'])->verify();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/account/whatsapp/step-up', $response->getHeaderLine('Location'));
    }

    public function testSendWithNoVerifiedNumberRedirectsToEnroll(): void
    {
        $user = $this->makeUser(); // deliberately never verified

        $this->actingAs($user);

        $response = $this->makeController()->send();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(0, FakeWhatsAppSender::$sendCount);
    }
}
