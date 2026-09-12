<?php

declare(strict_types=1);

namespace WhatsAppMfa\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Libraries\CompletesPendingAction;
use WhatsAppMfa\Libraries\PhoneNumberStore;
use WhatsAppMfa\Sender\WhatsAppSenderInterface;

/**
 * Optional WhatsApp phone verification during registration. Register
 * this as the 'register' action if you want new users offered a
 * "verify your WhatsApp number" step as part of signup:
 *
 *   public array $actions = [
 *       'register' => \WhatsAppMfa\Authentication\Actions\WhatsAppActivator::class,
 *       'login'    => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
 *   ];
 *
 * THREE steps, not two - unlike TotpActivator/PasskeyActivator, there's
 * no existing secret/credential to enroll immediately; a phone number
 * has to be collected first:
 *   show()   - phone number entry form.
 *   handle() - receives the phone number, sends a code to it via
 *              PhoneNumberStore::beginVerification() and whichever
 *              sender WhatsAppMfa itself is configured with, then
 *              renders the "enter your code" form.
 *   verify() - checks the submitted code and completes registration.
 *
 * OPTIONAL BY DESIGN: the enrollment view includes a "skip for now"
 * link, handled by WhatsAppActivatorController::skip() (see
 * routes-snippet.php for its route) rather than a method on this
 * class - routes require a real Controller, which this Action class is
 * not (confirmed the hard way while building the shield-totp-mfa
 * package this one is modeled on: "Call to undefined method
 * ...::initController()"). A user who skips is activated without a
 * verified number, and can opt in later from the self-service settings
 * page (WhatsAppSettingsController, or shield-mfa-dispatcher's
 * equivalent) - or never, if they don't want to.
 *
 * Writes to the exact same permanent record WhatsAppMfa's
 * resolvePhoneNumber() reads from - see PhoneNumberStore, which is
 * shared between this class, the login action, and the self-service
 * settings page. Uses a THIRD identity type of its own
 * (PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE) purely so Shield's
 * pending-check has something to find before a phone number is even
 * known - see that class's doc comment for the full explanation.
 *
 * IMPLEMENTS ConditionalActionInterface - CARRIED OVER FROM
 * shield-passkey-mfa, where a confirmed, real user report showed a
 * user who had already enrolled still being routed into
 * PasskeyActivator's own enrollment flow on a later, ordinary login
 * (paired with shield-mfa-dispatcher: register=Activator,
 * login=MfaDispatcher) - despite shield-mfa-dispatcher's own
 * resolveRequiredMethod() correctly resolving the user as already
 * enrolled. Direct log tracing there confirmed Shield itself was
 * routing straight to the register slot's activator, never even
 * reaching the login slot's action for that request.
 *
 * Confirmed against Shield's own official documentation on Auth
 * Actions: a custom action can implement ConditionalActionInterface's
 * appliesTo(User $user): bool to tell Shield directly whether it
 * should be considered pending for a given user at all - "when
 * appliesTo() returns false, Shield does not start the action and
 * ignores stored identities for that action while the condition
 * remains false." Without this, Shield apparently keeps discovering a
 * "pending" register action for a user long after they've actually
 * finished registering. The most likely mechanism (not fully traced
 * through Shield's own source - see shield-passkey-mfa's own README
 * for the same honest caveat): ID_TYPE_PHONE_ACTIVATE is a temporary
 * marker created before a phone number is even known - if it's never
 * cleaned up once registration completes, Shield could keep finding a
 * match for the register slot's own type indefinitely. appliesTo()
 * below sidesteps the question of the exact mechanism entirely:
 * whatever the reason Shield might still consider this pending,
 * telling it directly not to once the user already has a verified
 * number is the documented, correct fix regardless.
 */
class WhatsAppActivator implements ActionInterface, ConditionalActionInterface
{
    use CompletesPendingAction;

    protected PhoneNumberStore $store;
    protected WhatsAppMfaConfig $config;

    public function __construct()
    {
        $this->store  = new PhoneNumberStore();
        $this->config = config('WhatsAppMfa');
    }

    /**
     * {@inheritDoc}
     *
     * Confirmed via Shield's own docs: "may be called more than once
     * while Shield checks for actions, so keep it deterministic, free
     * of side effects, and fail closed when the condition cannot be
     * determined." hasVerifiedPhoneNumber() is a plain, read-only DB
     * check - no side effects, deterministic for a given user's stored
     * state.
     */
    public function appliesTo(User $user): bool
    {
        return ! $this->store->hasVerifiedPhoneNumber($user);
    }

    public function show(): string
    {
        // Not actually needed for the form itself, but fails loudly
        // here (rather than silently later) if this is somehow reached
        // outside a real pending-registration context, matching every
        // other Action in this series.
        $this->getPendingUser();

        return view($this->config->views['whatsapp_activator_enroll']);
    }

    /**
     * Receives the submitted phone number, starts a verification
     * attempt, and renders the code-entry form - the WhatsApp
     * equivalent of TotpActivator's show() rendering a QR code, just
     * one step later since a number has to be collected first.
     */
    public function handle(IncomingRequest $request): Response
    {
        $user  = $this->getPendingUser();
        $phone = trim((string) $request->getPost('phone'));

        if (! $this->looksLikeAPhoneNumber($phone)) {
            return redirect()->back()->withInput()->with('error', lang('WhatsAppMfa.invalidPhoneNumber'));
        }

        $code = $this->store->beginVerification($user, $phone);

        $senderClass = $this->config->sender;
        /** @var WhatsAppSenderInterface $sender */
        $sender = new $senderClass();

        // CONFIRMED, REAL BUG FIXED HERE: a real report traced an
        // orphaned ID_TYPE_PHONE_PENDING record back to exactly this
        // gap - beginVerification() above already creates that record
        // before send() is even attempted, and send() throwing (a real
        // Twilio API error, in the reported case) was previously
        // completely uncaught, crashing the whole request and leaving
        // that record behind indefinitely - nothing ever ran to clean
        // it up, since confirmVerification() (the only code that
        // deletes it) never got a chance to run at all. \Throwable
        // (not RuntimeException, which this file's own import above
        // aliases to Shield's OWN exception class, not the plain one
        // WhatsAppSenderInterface implementations actually throw) is
        // used specifically so this doesn't depend on which exception
        // class a given sender implementation happens to use.
        try {
            $sender->send($phone, $code, $this->config);
        } catch (\Throwable $e) {
            $this->store->cancelVerification($user);

            return redirect()->back()->withInput()->with('error', \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.sendFailedMessage'));
        }

        $body = view($this->config->views['whatsapp_activator_verify'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);

        return service('response')->setBody($body);
    }

    public function verify(IncomingRequest $request): Response
    {
        $user = $this->getPendingUser();
        $code = trim((string) $request->getPost('code'));

        if ($code === '' || ! $this->store->confirmVerification($user, $code)) {
            // Not back() - see shield-whatsapp-mfa's WhatsAppMfa class
            // for the full explanation of a confirmed bug this exact
            // pattern caused: the code-entry page is rendered by
            // handle() (a POST response, no redirect in between), so
            // the browser's address bar is still on auth/a/handle - a
            // POST-only route. back() would target that directly,
            // producing "Can't find a route for 'GET: auth/a/handle'"
            // the moment the browser follows the redirect.
            //
            // Known trade-off specific to THIS class (unlike
            // WhatsAppMfa, where auth-action-show is a reasonable
            // fallback): show() here renders the PHONE ENTRY form, not
            // a code-retry - so a wrong code sends the user all the
            // way back to entering their number again, discarding the
            // one already sent. Correctness over convenience: this is
            // still strictly better than a broken route, and a fresh
            // code is a safe, if slightly more friction, fallback.
            return redirect()->route('auth-action-show')->withInput()->with('error', lang('WhatsAppMfa.invalidCode'));
        }

        // Checked BEFORE completePendingAction()/activate() touch
        // anything - see the class doc comment for why this
        // distinguishes genuine registration from this same class
        // being reused as a forced-setup step during an existing
        // user's login (shield-mfa-dispatcher's
        // Config\MfaDispatcher::$requiredMethodsForGroups).
        $wasAlreadyActive = (bool) $user->active;

        $this->completePendingAction($user);

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        if (! $wasAlreadyActive) {
            $authenticator->getUser()->activate();

            return redirect()->to(config('Auth')->registerRedirect())
                ->with('message', lang('Auth.registerSuccess'));
        }

        // Reused as a login-time forced-setup step, not genuine
        // registration - the user was already active, so there's no
        // account to activate, and they should land wherever a normal
        // login sends them, not wherever a fresh registration does.
        return redirect()->to(config('Auth')->loginRedirect())
            ->with('message', lang('WhatsAppMfa.successMessage'));
    }

    public function getType(): string
    {
        return PhoneNumberStore::ID_TYPE_PHONE_ACTIVATE;
    }

    /**
     * Only ensures the activation marker exists - unlike
     * TotpActivator (which can generate a full secret without any user
     * input), there's no phone number to start a real verification
     * attempt with yet at this point; that happens once handle()
     * actually receives one.
     */
    public function createIdentity(User $user): string
    {
        $this->store->ensureActivationMarker($user);

        return 'whatsapp-verification-required';
    }

    protected function getPendingUser(): User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('WhatsAppActivator: cannot get the pending registration user.');
        }

        return $user;
    }

    /**
     * Minimal sanity check, not full E.164 validation - just enough to
     * catch empty/obviously-wrong input before sending a real WhatsApp
     * message on it. Duplicated from WhatsAppSettingsController rather
     * than shared - see that class's own doc comment on the same
     * duplication for why.
     */
    private function looksLikeAPhoneNumber(string $phone): bool
    {
        return (bool) preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone);
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
