<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Filters;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use WhatsAppMfa\Filters\RequireFreshWhatsApp;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests RequireFreshWhatsApp::before() directly, rather than through a
 * full HTTP round-trip to a real protected route - mirrors
 * shield-totp-mfa's own RequireFreshTotpTest exactly. See that file's
 * doc comment for why this approach doesn't need your app to have a
 * dedicated test-only protected route wired up.
 */
final class RequireFreshWhatsAppTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AuthenticationTesting;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

    /**
     * Saved/restored around the one test that mutates a shared config
     * property - see tearDown().
     */
    private ?bool $originalStepUpRequiresEnrollment = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Makes this file self-contained rather than dependent on
        // WhatsAppMfaTest/WhatsAppActivatorTest happening to run first
        // in the same PHPUnit process: this filter's redirect()->route()
        // calls need a populated route collection, and a call to
        // resetServices() anywhere earlier in the same process (this
        // package's own tests call it - see WhatsAppActivatorTest's own
        // setUp() for why) wipes it. loadRoutes() is safe to call even
        // if routes are already loaded. See shield-totp-mfa's
        // RequireFreshTotpTest for the identical fix applied for the
        // identical reason.
        Services::routes()->loadRoutes();
    }

    protected function tearDown(): void
    {
        if ($this->originalStepUpRequiresEnrollment !== null) {
            config('WhatsAppMfa')->stepUpRequiresEnrollment = $this->originalStepUpRequiresEnrollment;
            $this->originalStepUpRequiresEnrollment          = null;
        }

        parent::tearDown();
    }

    private function makeUser(string $prefix): User
    {
        return fake(UserModel::class, [
            'email'    => $prefix . '-' . uniqid() . '@example.com',
            'username' => $prefix . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function verifyPhone(User $user): void
    {
        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);
    }

    public function testUnauthenticatedRequestIsIgnored(): void
    {
        $filter = new RequireFreshWhatsApp();

        $result = $filter->before(service('request'));

        // Not this filter's job - it defers to whatever login-required
        // filter runs alongside it.
        $this->assertNull($result);
    }

    public function testVerifiedUserWithoutRecentStepUpIsRedirectedToChallenge(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->verifyPhone($user);

        $result = (new RequireFreshWhatsApp())->before(service('request'));

        $this->assertNotNull($result);
    }

    public function testFreshStepUpSessionLetsTheRequestThrough(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->verifyPhone($user);

        session()->set(config('WhatsAppMfa')->stepUpSessionKey, time());

        $result = (new RequireFreshWhatsApp())->before(service('request'));

        $this->assertNull($result);
    }

    public function testExpiredStepUpSessionIsRedirectedAgain(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->verifyPhone($user);

        $config = config('WhatsAppMfa');
        session()->set($config->stepUpSessionKey, time() - $config->stepUpFreshnessSeconds - 60);

        $result = (new RequireFreshWhatsApp())->before(service('request'));

        $this->assertNotNull($result);
    }

    public function testUnverifiedUserPassesThroughByDefault(): void
    {
        $user = $this->makeUser('stepup-unverified');

        $this->actingAs($user);

        $result = (new RequireFreshWhatsApp())->before(service('request'));

        // Default policy: nothing to challenge them with, so let them
        // through rather than lock them out entirely - see
        // $config->stepUpRequiresEnrollment to change this.
        $this->assertNull($result);
    }

    public function testUnverifiedUserIsRedirectedToEnrollWhenRequired(): void
    {
        $user = $this->makeUser('stepup-forced');

        $this->actingAs($user);

        // Saved so tearDown() can restore it - config objects are
        // cached/shared by CodeIgniter's Factories, so mutating one
        // directly without restoring it would leak into every other
        // test that runs afterward in the same PHPUnit process,
        // regardless of which test class they're in.
        $config                                 = config('WhatsAppMfa');
        $this->originalStepUpRequiresEnrollment = $config->stepUpRequiresEnrollment;
        $config->stepUpRequiresEnrollment        = true;

        $result = (new RequireFreshWhatsApp())->before(service('request'));

        $this->assertNotNull($result);
    }
}
