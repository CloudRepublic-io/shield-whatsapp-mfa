<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Authentication\Actions;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionObject;
use Tests\WhatsAppMfa\Support\FakeWhatsAppSender;
use WhatsAppMfa\Authentication\Actions\WhatsAppActivator;
use WhatsAppMfa\Controllers\WhatsAppActivatorController;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests WhatsAppActivator's show()/handle()/verify()/getType()/createIdentity()
 * and WhatsAppActivatorController::skip() directly, rather than
 * through a full HTTP round-trip - the same approach used throughout
 * this series of packages. Unlike shield-passkey-mfa's activator,
 * WhatsApp's code verification is plain 6-digit matching, not real
 * cryptography, so (unlike that package) the $wasAlreadyActive branch
 * in verify() CAN be exercised directly here - see
 * testVerifyForAnAlreadyActiveUserRedirectsToLoginNotRegistration.
 *
 * Uses a real Session::attempt() call with real credentials to put the
 * authenticator into a genuinely pending state - see
 * shield-totp-mfa's TotpMfaTest for where this pattern was originally
 * worked out, and why actingAs()/startLogin() alone don't work for
 * this.
 *
 * FULL FIX, mirroring shield-totp-mfa's TotpActivatorTest exactly
 * (see that class's own doc comment for the complete, diagnostic-backed
 * account - summarized here since this package hits the identical
 * issues):
 *
 *   - setUp() forces Config\Auth::$actions['register'] directly,
 *     rather than only documenting it as a prerequisite - a real gap
 *     that silently breaks if the test-running app's own
 *     app/Config/Auth.php doesn't happen to match.
 *   - resetServices() + session()->destroy() clear cached state left
 *     behind by whatever test ran immediately before this one in the
 *     same PHPUnit process (confirmed necessary: DatabaseTestTrait's
 *     own $refresh resets the database but not the session or CI4's
 *     own cached service instances).
 *   - Services::routes()->loadRoutes() undoes resetServices()'s own
 *     side effect of wiping the route collection (confirmed via
 *     CodeIgniter's own docs).
 *   - simulateRegistrationStartup(), called by every test whose
 *     getPendingUser()-dependent method (show()/handle()/verify()/skip())
 *     needs a genuinely pending user, sets the authenticator's own
 *     private $userState/$user properties directly via reflection.
 *     CONFIRMED (via a real, multi-round diagnostic effort on
 *     shield-totp-mfa's identical architecture) that nothing short of
 *     this works once 'login' points at something other than this
 *     package's own action (e.g. shield-mfa-dispatcher's
 *     MfaDispatcher) - not creating the database identity alone, and
 *     not writing session('user')['auth_action'] directly either.
 *     getPendingUser() checks $userState directly, not something
 *     re-derived from session data on each call.
 */
final class WhatsAppActivatorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

    private const PASSWORD = 'secret123456';

    private $originalRegisterAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetServices();
        session()->destroy();
        Services::routes()->loadRoutes();

        $authConfig                      = config('Auth');
        $this->originalRegisterAction    = $authConfig->actions['register'] ?? null;
        $authConfig->actions['register'] = WhatsAppActivator::class;
        // 'login' deliberately left untouched - forcing it to null was
        // tried for shield-totp-mfa's equivalent file and caused a
        // regression there, confirming the real 'login' action can be
        // load-bearing for some tests even in a 'register'-focused
        // file.

        config('WhatsAppMfa')->sender = FakeWhatsAppSender::class;
        FakeWhatsAppSender::reset();
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['register'] = $this->originalRegisterAction;

        parent::tearDown();
    }

    private function makeUser(bool $active = false): User
    {
        return fake(UserModel::class, [
            'email'    => 'whatsapp-activator-test-' . uniqid() . '@example.com',
            'username' => 'whatsappactivatortest' . uniqid(),
            'password' => self::PASSWORD,
            'active'   => $active,
        ]);
    }

    private function requestWithPost(array $post): IncomingRequest
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);

        return $request;
    }

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

    /**
     * Sets the authenticator's own private $userState/$user properties
     * directly via reflection - confirmed, via shield-totp-mfa's own
     * multi-round diagnostic effort against its identical architecture,
     * to be the only way to correctly simulate a real registration
     * request's pending state once 'login' points at something other
     * than this package's own action (e.g. shield-mfa-dispatcher's
     * MfaDispatcher). See TotpActivatorTest's class doc comment (in
     * shield-totp-mfa) for the full, diagnostic-backed account of why
     * creating the database identity alone, and separately writing
     * session('user')['auth_action'] directly, were both tried and
     * confirmed NOT sufficient before this was found.
     *
     * 2 is the confirmed-working $userState value, observed directly
     * via a reflection dump of a genuinely working scenario, not
     * guessed or derived from documentation.
     */
    private function simulateRegistrationStartup(User $user): void
    {
        (new WhatsAppActivator())->createIdentity($user);

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $reflection    = new ReflectionObject($authenticator);

        $userStateProperty = $reflection->getProperty('userState');
        $userStateProperty->setAccessible(true);
        $userStateProperty->setValue($authenticator, 2);

        $userProperty = $reflection->getProperty('user');
        $userProperty->setAccessible(true);
        $userProperty->setValue($authenticator, $user);

        $field = setting('Auth.sessionConfig')['field'];
        $data  = session($field) ?? [];

        $data['id']                  = $user->id;
        $data['auth_action']         = WhatsAppActivator::class;
        $data['auth_action_message'] = null;

        session()->set($field, $data);
    }

    public function testGetTypeReturnsTheActivationMarkerType(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        $this->assertSame(PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE, (new WhatsAppActivator())->getType());
    }

    public function testCreateIdentityEnsuresTheActivationMarkerExists(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        (new WhatsAppActivator())->createIdentity($user);

        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE,
        ]);
    }

    public function testShowRendersThePhoneEntryForm(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        $body = (new WhatsAppActivator())->show();

        $this->assertStringContainsString(lang('WhatsAppMfa.phoneLabel'), $body);
    }

    public function testHandleRejectsAnInvalidPhoneNumber(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => 'not a phone number']));

        $this->assertNotEmpty(session('error'));
        $this->assertSame(0, FakeWhatsAppSender::$sendCount);
    }

    public function testHandleSendsACodeForAValidPhoneNumber(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));

        $this->assertSame(1, FakeWhatsAppSender::$sendCount);
        $this->assertSame('+15551234567', FakeWhatsAppSender::$lastPhoneNumber);
    }

    /**
     * THE regression test for a real, confirmed report: a sender
     * failure (a real Twilio API error, in the reported case) crashed
     * the whole request uncaught, leaving the pending record
     * beginVerification() had already created orphaned indefinitely -
     * confirmVerification() (the only code that would otherwise delete
     * it) never got a chance to run at all, since the code was never
     * actually delivered for the user to enter.
     */
    public function testHandleRollsBackThePendingRecordWhenTheSenderFails(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        FakeWhatsAppSender::$shouldFail = true;

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));

        $store = new PhoneNumberStore();
        $this->assertNull($store->getPendingPhoneNumber($user));
        $this->assertNotEmpty(session('error'));
    }

    public function testCorrectCodeActivatesUserAndVerifiesThePhone(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));
        $code = FakeWhatsAppSender::$lastCode;

        $response = (new WhatsAppActivator())->verify($this->requestWithPost(['code' => $code]));

        $this->assertSame(302, $response->getStatusCode());

        $store = new PhoneNumberStore();
        $this->assertTrue($store->hasVerifiedPhoneNumber($user));
        $this->assertSame('+15551234567', $store->getVerifiedPhoneNumber($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertTrue((bool) $fresh->active);
    }

    public function testWrongCodeDoesNotActivateOrVerify(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));

        $response = (new WhatsAppActivator())->verify($this->requestWithPost(['code' => '000000']));

        $store = new PhoneNumberStore();
        $this->assertFalse($store->hasVerifiedPhoneNumber($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertFalse((bool) $fresh->active);

        // Regression test for a real, confirmed bug: this error path
        // used to call redirect()->back(), but the code-entry page it
        // was rendered from is itself the direct output of a POST to
        // auth/a/handle - meaning the browser's address bar is still
        // on that POST-only route when it submits the code. back()
        // targeted that same URL, producing "Can't find a route for
        // 'GET: auth/a/handle'" in a real app the moment the browser
        // followed the redirect. See this class's own comment on
        // verify() for the full explanation, including the known UX
        // trade-off of using auth-action-show here specifically (it
        // re-renders the PHONE entry form, not a code retry).
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $response);
        $this->assertStringContainsString('/auth/a/show', $response->getHeaderLine('Location'));
    }

    public function testSkipActivatesUserWithoutVerifyingAPhone(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        $store    = new PhoneNumberStore();
        $response = (new WhatsAppActivatorController())->skip();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertFalse($store->hasVerifiedPhoneNumber($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertTrue((bool) $fresh->active);
    }

    public function testSkipRemovesAnyInProgressVerificationAttempt(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));

        $store = new PhoneNumberStore();
        $this->assertNotNull($store->getPendingPhoneNumber($user));

        (new WhatsAppActivatorController())->skip();

        $this->assertNull($store->getPendingPhoneNumber($user));
        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE,
        ]);
    }

    /**
     * Confirms the fix that makes this class safe to reuse for
     * shield-mfa-dispatcher's forced-setup flow (an already-active
     * user forced to verify a WhatsApp number mid-login, not a
     * genuinely new registration) - see the class doc comment. Unlike
     * shield-passkey-mfa's equivalent activator, this one CAN be
     * tested directly, since WhatsApp's code verification is plain
     * 6-digit matching, not real cryptography.
     */
    public function testVerifyForAnAlreadyActiveUserRedirectsToLoginNotRegistration(): void
    {
        $user = $this->makeUser(active: true); // unlike the other tests here, which default to false
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        (new WhatsAppActivator())->handle($this->requestWithPost(['phone' => '+15551234567']));
        $code = FakeWhatsAppSender::$lastCode;

        $response = (new WhatsAppActivator())->verify($this->requestWithPost(['code' => $code]));

        $this->assertSame(302, $response->getStatusCode());
        // The registration branch sets lang('Auth.registerSuccess') as
        // the flash message; the already-active branch sets
        // lang('WhatsAppMfa.successMessage') instead - checking which
        // one landed is a more reliable signal of which branch
        // actually ran than comparing raw redirect URLs would be.
        $this->assertSame(lang('WhatsAppMfa.successMessage'), session('message'));
    }

    // -------------------------------------------------------------------
    // appliesTo() - THE confirmed fix, carried over from
    // shield-passkey-mfa's own confirmed resolution of a real bug: a
    // user with an already-enrolled method was still routed into that
    // method's own enrollment flow on later, ordinary logins. See this
    // class's own doc comment for the full account.
    // -------------------------------------------------------------------

    public function testAppliesToReturnsTrueForAUserWithNoVerifiedNumber(): void
    {
        $user      = $this->makeUser();
        $activator = new WhatsAppActivator();

        $this->assertTrue($activator->appliesTo($user));
    }

    /**
     * THE regression test for the actual bug. beginVerification()
     * returns the real code directly, so this doesn't need
     * FakeWhatsAppSender at all - no message actually needs to be
     * "sent" to complete a real verification here.
     */
    public function testAppliesToReturnsFalseForAUserWithAVerifiedNumber(): void
    {
        $user  = $this->makeUser();
        $store = new PhoneNumberStore();

        $code = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);

        $activator = new WhatsAppActivator();

        $this->assertFalse($activator->appliesTo($user));
    }
}
