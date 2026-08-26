<?php

declare(strict_types=1);

namespace WhatsAppMfa\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Libraries\CompletesPendingAction;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * WhatsApp-based Two-Factor Authentication action for CodeIgniter Shield.
 *
 * Mirrors the lifecycle of Shield's built-in Email2FA action:
 *   show()   - landing page right after primary login, tells the user
 *              a code is about to be sent and offers a "Send code" step.
 *   handle() - generates the code, stores a hashed copy as a Shield
 *              "identity" record, sends it over WhatsApp, then renders
 *              the "enter your code" form.
 *   verify() - checks the submitted code against the stored hash and
 *              completes (or rejects) the login.
 *
 * Register it in app/Config/Auth.php:
 *
 *   public array $actions = [
 *       'register' => \WhatsAppMfa\Authentication\Actions\WhatsAppActivator::class, // optional
 *       'login'    => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
 *   ];
 *
 * As of this version, resolvePhoneNumber() checks PhoneNumberStore's
 * own verified-phone record first - see WhatsAppSettingsController,
 * which lets an already-logged-in user self-service add (or change, or
 * remove) a verified WhatsApp number, the same way TotpSettingsController
 * and PasskeySettingsController do for their own methods in this
 * series. This is what makes WhatsApp usable as an "add this later"
 * MFA option rather than something only usable if your app happens to
 * already store phone numbers on the User entity. WhatsAppActivator
 * (new in this version) offers the same verification as an optional
 * signup-time step instead, if you'd rather users set it up at
 * registration.
 */
class WhatsAppMfa implements ActionInterface
{
    use CompletesPendingAction;

    public const ID_TYPE_WHATSAPP_MFA = 'whatsapp_mfa';

    protected UserIdentityModel $identities;
    protected WhatsAppMfaConfig $config;

    public function __construct()
    {
        $this->identities = model(UserIdentityModel::class);
        $this->config     = config('WhatsAppMfa');
    }

    /**
     * {@inheritDoc}
     *
     * Instructions page. No code is sent yet - this just tells the user
     * what's about to happen and gives them a form/button that POSTs to
     * auth/a/handle (Shield's ActionController::handle route).
     */
    public function show(): string
    {
        $user  = $this->getPendingUser();
        $phone = $this->resolvePhoneNumber($user);

        if ($phone === null) {
            throw new RuntimeException(
                'WhatsAppMfa: no phone number found for user id ' . $user->id
                . '. See README for how to store a verified phone number.'
            );
        }

        return view($this->config->views['whatsapp_mfa_show'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Generates a fresh code, stores its hash, sends it over WhatsApp,
     * and renders the "enter code" form. Also used for "resend".
     *
     * CORRECTION: this originally declared a `: string` return type,
     * written before ActionInterface's actual current contract
     * (confirmed via the shield-totp-mfa package's real test suite) was
     * known: handle() must return Response, not a raw string. A `string`
     * declaration here is incompatible with the interface and would
     * fatal the moment this class is ever loaded in an app running a
     * Shield version with that contract - this package had never
     * actually been exercised end-to-end since being written, which is
     * how this went unnoticed until building tests against it directly.
     */
    public function handle(IncomingRequest $request): Response
    {
        $user  = $this->getPendingUser();
        $phone = $this->resolvePhoneNumber($user);

        if ($phone === null) {
            throw new RuntimeException('WhatsAppMfa: no phone number found for user id ' . $user->id);
        }

        $code = $this->createIdentity($user);

        $senderClass = $this->config->sender;
        /** @var \WhatsAppMfa\Sender\WhatsAppSenderInterface $sender */
        $sender = new $senderClass();
        $sender->send($phone, $code, $this->config);

        $body = view($this->config->views['whatsapp_mfa_verify'], [
            'phone_masked'   => $this->maskPhone($phone),
            'resend_seconds' => $this->config->resendCooldown,
        ]);

        return service('response')->setBody($body);
    }

    /**
     * {@inheritDoc}
     *
     * Newer Shield versions added getType() and createIdentity() to
     * ActionInterface after this package was first written against an
     * older copy of the interface - if you hit a "must implement
     * getType, createIdentity" fatal error, that's why. Both are
     * implemented below.
     */
    public function getType(): string
    {
        return self::ID_TYPE_WHATSAPP_MFA;
    }

    /**
     * {@inheritDoc}
     *
     * Generates a fresh code, stores its hash as this user's identity
     * (invalidating any earlier pending code first), and returns the
     * plaintext code so the caller (handle(), above) can send it. This
     * mirrors how Shield's own Email2FA action separates "create the
     * code" from "send the code" - the interface expects
     * createIdentity() to do the former and hand back the code, not to
     * do any sending itself.
     */
    public function createIdentity(User $user): string
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_MFA)
            ->delete();

        $code = $this->generateCode();

        $this->identities->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_WHATSAPP_MFA,
            'name'    => $this->resolvePhoneNumber($user) ?? '',
            'secret'  => password_hash($code, PASSWORD_DEFAULT),
            'extra'   => null,
            'expires' => date('Y-m-d H:i:s', time() + $this->config->codeLifetime),
        ]);

        return $code;
    }

    /**
     * A 6-digit numeric code, digits 1-9 only (no zero, to avoid any
     * "0 vs O" confusion when a user reads it off their phone) - see
     * PhoneNumberStore::generateCode()'s own doc comment (in this same
     * package) for why this is self-contained rather than using CI4's
     * random_string() text helper: that helper isn't autoloaded by
     * default, and this class never called helper('text') to load it -
     * a real bug that broke this exact method in practice, not a
     * hypothetical one.
     */
    private function generateCode(): string
    {
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= (string) random_int(1, 9);
        }

        return $code;
    }

    /**
     * {@inheritDoc}
     *
     * Validates the submitted code and completes the login.
     */
    public function verify(IncomingRequest $request): RedirectResponse
    {
        $user = $this->getPendingUser();
        $code = trim((string) $request->getPost('code'));

        if ($code === '') {
            // Not back() - see this class's own note on the confirmed
            // bug this caused: the code-entry page is rendered by
            // handle() (a POST response, no redirect in between), so
            // the browser's address bar is still on auth/a/handle - a
            // POST-only route. back() would target that directly,
            // producing "Can't find a route for 'GET: auth/a/handle'"
            // the moment the browser follows the redirect. Matches the
            // pattern already used correctly below for the other error
            // cases in this same method.
            return redirect()->route('auth-action-show')->withInput()->with('error', lang('WhatsAppMfa.enterCode'));
        }

        $identity = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_MFA)
            ->orderBy('id', 'desc')
            ->first();

        if ($identity === null) {
            return redirect()->route('auth-action-show')->with('error', lang('WhatsAppMfa.noPendingCode'));
        }

        if ($identity->expires !== null && $identity->expires->getTimestamp() < time()) {
            $this->identities->delete($identity->id);

            return redirect()->route('auth-action-show')->with('error', lang('WhatsAppMfa.codeExpired'));
        }

        if (! password_verify($code, $identity->secret)) {
            return redirect()->route('auth-action-show')->withInput()->with('error', lang('WhatsAppMfa.invalidCode'));
        }

        // Code is correct - consume it so it can't be replayed.
        $this->identities->delete($identity->id);

        $this->completePendingAction($user);

        return redirect()->to(config('Auth')->loginRedirect())
            ->with('message', lang('WhatsAppMfa.successMessage'));
    }

    /**
     * Shield's Session authenticator tracks the user who has passed
     * primary credentials but not yet finished the configured Action,
     * as the "pending" user - not the fully logged-in user.
     */
    protected function getPendingUser(): User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('WhatsAppMfa: cannot get the pending login user.');
        }

        return $user;
    }

    /**
     * Resolve a verified phone number for the user. Checks
     * PhoneNumberStore's own verified record first (populated via
     * WhatsAppSettingsController's self-service flow - see that
     * class's doc comment) - this is what makes WhatsApp MFA usable as
     * a self-service "add this later" option without your app needing
     * to add a phone column of its own. Falls back to a property on
     * the User entity (configurable via $config->phoneNumberField) if
     * your app already manages phone numbers itself and you'd rather
     * use that as the source of truth instead.
     */
    protected function resolvePhoneNumber(User $user): ?string
    {
        $verified = (new PhoneNumberStore())->getVerifiedPhoneNumber($user);

        if ($verified !== null) {
            return $verified;
        }

        $field = $this->config->phoneNumberField;

        return $user->{$field} ?? null;
    }

    protected function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
