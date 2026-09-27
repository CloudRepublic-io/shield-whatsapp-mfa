<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Authentication\Actions;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\WhatsAppMfa\Support\FakeWhatsAppSender;
use Tests\WhatsAppMfa\Support\TestableWhatsAppMfa;
use WhatsAppMfa\Authentication\Actions\WhatsAppMfa;

// Loaded explicitly rather than autoloaded: a typical CodeIgniter app's
// composer.json only maps Tests\Support\ (to tests/_support), so
// Tests\WhatsAppMfa\Support\* isn't autoloadable, and PHPUnit only
// loads *Test.php files itself.
require_once __DIR__ . '/../../Support/FakeWhatsAppSender.php';
require_once __DIR__ . '/../../Support/TestableWhatsAppMfa.php';

/**
 * Tests the WhatsApp MFA action's handle()/verify() directly, rather
 * than through a full HTTP round-trip.
 *
 * Follows the same pattern established (through a lot of trial and
 * error) in the shield-totp-mfa package's own test suite:
 *
 *   - Calls the action's methods directly rather than routing through
 *     Shield's own auth/a/* endpoints, since those returned
 *     PageNotFoundException through FeatureTestTrait for reasons never
 *     fully root-caused against the framework's own source.
 *   - Uses a real Session::attempt() call with real credentials to put
 *     the authenticator into a genuinely pending state, rather than
 *     actingAs() (fully logged in - the wrong state for
 *     getPendingUser()) or any other partial simulation of Shield's
 *     internals.
 *
 * TestableWhatsAppMfa (in Support/) overrides phone number resolution
 * so this doesn't depend on your app having added a 'phone' column to
 * the User entity - and FakeWhatsAppSender (also in Support/) replaces
 * the real Meta/Twilio sender so no network call ever actually happens
 * during a test run.
 *
 * setUp() forces Config\Auth::$actions['login'] to WhatsAppMfa::class
 * directly, and clears cached state left behind by whatever test ran
 * immediately before this one in the same PHPUnit process - both
 * confirmed necessary via the shield-totp-mfa package's own extensive
 * diagnostic work (its README and TotpMfaTest/TotpActivatorTest class
 * doc comments have the full account; summarized here since this
 * package hits the identical issues, not repeated in full):
 *
 *   - DatabaseTestTrait's own $refresh resets the DATABASE between
 *     test methods but does nothing to the SESSION or to CI4's own
 *     cached SERVICE instances (confirmed: a second attempt() in the
 *     same process, without clearing both, throws Shield's own
 *     "already logged in or in pending login state" LogicException).
 *   - resetServices() (which clears the cached, shared authenticator
 *     instance - the actual missing piece, not session()->destroy()
 *     alone) also wipes the route collection as a side effect
 *     (confirmed via CodeIgniter's own docs) - Services::routes()->loadRoutes()
 *     is required afterward or every url_to()/route_to() call in this
 *     package's own views throws "The route for ... cannot be found".
 *   - Only documenting "assumes WhatsAppMfa is registered as 'login'"
 *     in a comment, rather than forcing it, silently breaks if the
 *     test-running app's own app/Config/Auth.php points 'login'
 *     somewhere else (e.g. shield-mfa-dispatcher's MfaDispatcher).
 */
final class WhatsAppMfaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`, picking up every registered
    // namespace's migrations.
    protected $namespace = null;

    private const PASSWORD = 'secret123456';

    private $originalLoginAction;

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

        $this->resetServices();
        session()->destroy();
        Services::routes()->loadRoutes();

        $authConfig                   = config('Auth');
        $this->originalLoginAction    = $authConfig->actions['login'] ?? null;
        $authConfig->actions['login'] = WhatsAppMfa::class;

        config('WhatsAppMfa')->sender = FakeWhatsAppSender::class;
        FakeWhatsAppSender::reset();
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['login'] = $this->originalLoginAction;

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'whatsapp-test-' . uniqid() . '@example.com',
            'username' => 'whatsapptest' . uniqid(),
            'password' => self::PASSWORD,
        ]);
    }

    private function requestWithPost(array $post): IncomingRequest
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);
        // CodeIgniter 4.7+ reads POST from a shared 'superglobals' snapshot
        // taken the first time anything touches the request, so the
        // $_POST assignment above is invisible to it - setGlobal() works
        // on 4.6 and 4.7 alike.
        $request->setGlobal('post', $post);

        return $request;
    }

    /**
     * Real login attempt with real credentials - see this class doc
     * comment (and the shield-totp-mfa package's TotpMfaTest, where
     * this pattern was worked out) for why this is necessary rather
     * than actingAs() or any partial simulation of pending state.
     */
    private function attemptLogin(User $user): void
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $result        = $authenticator->attempt([
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertTrue($result->isOK(), 'attempt() did not succeed with the test user\'s real credentials.');
    }

    public function testHandleGeneratesAndSendsACode(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));

        $this->assertSame(1, FakeWhatsAppSender::$sendCount);
        $this->assertSame(TestableWhatsAppMfa::TEST_PHONE_NUMBER, FakeWhatsAppSender::$lastPhoneNumber);
        $this->assertNotNull(FakeWhatsAppSender::$lastCode);
        $this->assertMatchesRegularExpression('/^\d{6}$/', FakeWhatsAppSender::$lastCode);

        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => WhatsAppMfa::ID_TYPE_WHATSAPP_MFA,
        ]);
    }

    /**
     * THE regression test for a real, confirmed report - see
     * WhatsAppActivator's own identical fix (in this same package) for
     * the fuller account: a sender failure previously crashed the
     * whole request uncaught, leaving the just-created
     * ID_TYPE_WHATSAPP_MFA identity orphaned indefinitely.
     */
    public function testHandleRollsBackTheIdentityWhenTheSenderFails(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        FakeWhatsAppSender::$shouldFail = true;

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));

        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => WhatsAppMfa::ID_TYPE_WHATSAPP_MFA,
        ]);
        $this->assertNotEmpty(session('error'));
    }

    public function testCorrectCodeCompletesLogin(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));
        $code = FakeWhatsAppSender::$lastCode;

        $response = (new TestableWhatsAppMfa())->verify($this->requestWithPost(['code' => $code]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty(session('message'));
    }

    public function testWrongCodeIsRejected(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));

        $response = (new TestableWhatsAppMfa())->verify($this->requestWithPost(['code' => '000000']));

        $this->assertNotEmpty(session('error'));
        $this->assertRedirectsToShowNotBack($response);
    }

    /**
     * Regression test for a real, confirmed bug: wrong/empty code
     * errors used to call redirect()->back(), but the code-entry page
     * they were rendered from is itself the direct output of a POST to
     * auth/a/handle (handle() renders it inline, no redirect in
     * between) - meaning the browser's address bar is still on that
     * POST-only route when it submits the code. back() would target
     * that same URL, and the browser following the resulting redirect
     * via GET produced "Can't find a route for 'GET: auth/a/handle'"
     * in a real app. Fixed by redirecting to the named 'auth-action-show'
     * route explicitly instead - see WhatsAppMfa::verify()'s own
     * comment for the full explanation, including why TotpMfa/PasskeyMfa
     * don't have this problem (their verify forms are rendered by
     * show() itself, a GET route, not by a separate handle() step).
     */
    private function assertRedirectsToShowNotBack($response): void
    {
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $response);
        $this->assertStringContainsString('/auth/a/show', $response->getHeaderLine('Location'));
    }

    public function testEmptyCodeRedirectsToShowNotBack(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));

        $response = (new TestableWhatsAppMfa())->verify($this->requestWithPost(['code' => '']));

        $this->assertRedirectsToShowNotBack($response);
    }

    public function testEmptyCodeIsRejected(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->verify($this->requestWithPost(['code' => '']));

        $this->assertNotEmpty(session('error'));
    }

    public function testExpiredCodeIsRejectedAndRemoved(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new TestableWhatsAppMfa())->handle($this->requestWithPost([]));
        $code = FakeWhatsAppSender::$lastCode;

        $identityModel = model(UserIdentityModel::class);
        $identity      = $identityModel
            ->where('user_id', $user->id)
            ->where('type', WhatsAppMfa::ID_TYPE_WHATSAPP_MFA)
            ->first();
        $identityModel->update($identity->id, ['expires' => date('Y-m-d H:i:s', time() - 60)]);

        (new TestableWhatsAppMfa())->verify($this->requestWithPost(['code' => $code]));

        $this->assertNotEmpty(session('error'));
        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => WhatsAppMfa::ID_TYPE_WHATSAPP_MFA,
        ]);
    }
}
