<?php

declare(strict_types=1);

namespace WhatsAppMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Libraries\PhoneNumberStore;
use WhatsAppMfa\Sender\WhatsAppSenderInterface;

/**
 * The "please confirm it's you" flow shown by the RequireFreshWhatsApp
 * filter when a protected route is reached without a recent-enough
 * step-up verification.
 *
 * Distinct from WhatsAppMfa (the login action): this operates on an
 * already-fully-logged-in user (auth()->user()), not Shield's "pending
 * login" user - there is no Shield ActionInterface machinery involved
 * here at all, just an ordinary controller behind an ordinary filter.
 * See shield-totp-mfa's TotpStepUpController for the same pattern
 * applied to TOTP.
 *
 * THREE steps, not two - unlike TotpStepUpController/PasskeyStepUpController,
 * there's no way to verify without first sending a fresh code:
 *   show()   - "we're about to send a code to the number ending in..."
 *   send()   - generates + sends the code via PhoneNumberStore::beginStepUpChallenge()
 *              and whichever sender WhatsAppMfa itself is configured with,
 *              then renders the "enter your code" form.
 *   verify() - checks the submitted code and, on success, stamps the
 *              step-up session and returns to wherever the user was
 *              headed.
 *
 * Deliberately does NOT use redirect()->back() anywhere in verify()'s
 * error path - see WhatsAppMfa::verify()'s own comment (in the login
 * action) for the full explanation of a real, confirmed bug this
 * caused there: the code-entry page is rendered directly by send() (a
 * POST response, no redirect in between), so the browser's address bar
 * stays on that POST-only route while showing the form. back() would
 * target that same URL and break the moment a wrong code was
 * submitted. redirect()->route('whatsapp-step-up') is used instead,
 * matching the fix already applied to the login flow - built in here
 * from the start rather than repeating that bug in a new file.
 */
class WhatsAppStepUpController extends Controller
{
    protected PhoneNumberStore $store;
    protected WhatsAppMfaConfig $config;

    public function __construct()
    {
        $this->store  = new PhoneNumberStore();
        $this->config = config('WhatsAppMfa');
    }

    public function show(): string
    {
        $user  = auth()->user();
        $phone = $this->store->getVerifiedPhoneNumber($user) ?? '';

        return view($this->config->views['whatsapp_step_up_show'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    public function send(): string|RedirectResponse
    {
        $user = auth()->user();
        $code = $this->store->beginStepUpChallenge($user);

        if ($code === null) {
            // No verified number - the filter should never route here
            // in that case, but this keeps the controller safe on its
            // own rather than assuming the filter was definitely what
            // sent the request.
            return redirect()->route($this->config->stepUpEnrollRouteName)
                ->with('message', lang('WhatsAppMfa.stepUpNeedsEnrollment'));
        }

        $phone = $this->store->getVerifiedPhoneNumber($user);

        // Reuses whichever sender WhatsAppMfa itself is configured
        // with, rather than this controller having its own copy of
        // that wiring - same pattern as WhatsAppSettingsController.
        $senderClass = $this->config->sender;
        /** @var WhatsAppSenderInterface $sender */
        $sender = new $senderClass();
        $sender->send($phone, $code, $this->config);

        return view($this->config->views['whatsapp_step_up_verify'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    public function verify(): RedirectResponse
    {
        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->store->verifyStepUpChallenge($user, $code)) {
            return redirect()->route('whatsapp-step-up')->with('error', lang('WhatsAppMfa.stepUpFailed'));
        }

        session()->set($this->config->stepUpSessionKey, time());

        $redirectTo = session('whatsapp_step_up_redirect');
        session()->remove('whatsapp_step_up_redirect');

        // $redirectTo, when present, was captured by
        // RequireFreshWhatsApp from current_url() on this same site -
        // not user-supplied input - so this isn't an open-redirect
        // risk. Falls back to the app's normal post-login destination
        // if it's somehow missing (e.g. this page was reached directly
        // rather than via the filter).
        return redirect()->to($redirectTo ?: config('Auth')->loginRedirect());
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
