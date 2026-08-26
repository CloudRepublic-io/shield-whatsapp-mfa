<?php

declare(strict_types=1);

namespace WhatsAppMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Libraries\PhoneNumberStore;
use WhatsAppMfa\Sender\WhatsAppSenderInterface;

/**
 * Self-service phone number verification for an already-logged-in
 * user - lets WhatsApp MFA be one of the "add this later" options
 * alongside TOTP and passkeys (see shield-totp-mfa's
 * TotpSettingsController and shield-passkey-mfa's
 * PasskeySettingsController for the same shape applied to those
 * methods), rather than something only usable if your app already has
 * a phone number on the User entity, or only reachable via the
 * dispatcher package's own settings page.
 *
 * Deliberately doesn't touch WhatsAppMfa::createIdentity()/getType() -
 * this is entirely about PhoneNumberStore's own verified-phone record,
 * a separate concern from the per-login OTP code. See
 * PhoneNumberStore's class doc comment for the full explanation.
 */
class WhatsAppSettingsController extends Controller
{
    protected PhoneNumberStore $store;
    protected WhatsAppMfaConfig $config;

    public function __construct()
    {
        $this->store  = new PhoneNumberStore();
        $this->config = config('WhatsAppMfa');
    }

    public function index(): string
    {
        $user = auth()->user();

        return view($this->config->views['whatsapp_settings_index'], [
            'verifiedPhone' => $this->store->getVerifiedPhoneNumber($user),
        ]);
    }

    public function enroll(): string
    {
        return view($this->config->views['whatsapp_settings_enroll']);
    }

    public function send(): RedirectResponse
    {
        $user  = auth()->user();
        $phone = trim((string) $this->request->getPost('phone'));

        if (! $this->looksLikeAPhoneNumber($phone)) {
            return redirect()->back()->withInput()->with('error', lang('WhatsAppMfa.invalidPhoneNumber'));
        }

        $code = $this->store->beginVerification($user, $phone);

        $senderClass = $this->config->sender;
        /** @var WhatsAppSenderInterface $sender */
        $sender = new $senderClass();
        $sender->send($phone, $code, $this->config);

        return redirect()->route('whatsapp-settings-verify');
    }

    public function verify(): string|RedirectResponse
    {
        $user  = auth()->user();
        $phone = $this->store->getPendingPhoneNumber($user);

        if ($phone === null) {
            return redirect()->route('whatsapp-settings-enroll')
                ->with('error', lang('WhatsAppMfa.noPendingCode'));
        }

        return view($this->config->views['whatsapp_settings_verify'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    public function confirm(): RedirectResponse
    {
        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->store->confirmVerification($user, $code)) {
            return redirect()->back()->with('error', lang('WhatsAppMfa.invalidCode'));
        }

        return redirect()->route('whatsapp-settings')->with('message', lang('WhatsAppMfa.phoneVerifiedMessage'));
    }

    public function disable(): RedirectResponse
    {
        $user = auth()->user();
        $this->store->removeVerifiedPhoneNumber($user);

        return redirect()->route('whatsapp-settings')->with('message', lang('WhatsAppMfa.phoneRemovedMessage'));
    }

    /**
     * Minimal sanity check, not full E.164 validation - just enough to
     * catch empty/obviously-wrong input before sending a real WhatsApp
     * message (and burning API quota, and potentially your approved
     * template's send allowance) on it.
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
