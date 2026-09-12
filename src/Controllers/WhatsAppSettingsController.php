<?php

declare(strict_types=1);

namespace WhatsAppMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Libraries\ChannelLabel;
use WhatsAppMfa\Libraries\PhoneNumberStore;
use WhatsAppMfa\Sender\TwilioWhatsAppSender;
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
 *
 * ALSO includes a separate "test WhatsApp delivery" flow
 * (testEnroll()/testSend()/testVerify()/testConfirm()) - a
 * developer-facing migration tool, not a normal end-user MFA concept,
 * so deliberately kept out of shield-mfa-dispatcher's own unified
 * settings page entirely (see PhoneNumberStore's own doc comment,
 * "Testing WhatsApp delivery ahead of a $channel migration", for the
 * full account of why this exists).
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
            'verifiedPhone'       => $this->store->getVerifiedPhoneNumber($user),
            'testIsRelevant'      => $this->whatsAppTestIsRelevant(),
            'whatsAppConfirmed'   => $this->store->hasConfirmedWhatsAppDelivery($user),
        ]);
    }

    /**
     * The "test WhatsApp delivery" action is only meaningful when
     * $channel is currently 'sms' (if it's already 'whatsapp', the
     * NORMAL verify flow already tests WhatsApp directly - a separate
     * test action would be redundant) AND the configured sender is
     * specifically TwilioWhatsAppSender (the only sender with a
     * $channel concept at all - MetaCloudApiSender, for example, only
     * ever sends WhatsApp, so there's nothing to "test" separately
     * there either).
     */
    private function whatsAppTestIsRelevant(): bool
    {
        return $this->config->channel === 'sms'
            && is_a($this->config->sender, TwilioWhatsAppSender::class, true);
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

        // CONFIRMED, REAL BUG FIXED HERE - see WhatsAppActivator's own
        // identical fix for the fuller account of a real report this
        // addresses.
        try {
            $sender->send($phone, $code, $this->config);
        } catch (\Throwable $e) {
            $this->store->cancelVerification($user);

            return redirect()->back()->withInput()->with('error', ChannelLabel::inject('WhatsAppMfa.sendFailedMessage'));
        }

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

        return redirect()->route('whatsapp-settings')->with('message', ChannelLabel::inject('WhatsAppMfa.phoneVerifiedMessage'));
    }

    public function disable(): RedirectResponse
    {
        $user = auth()->user();
        $this->store->removeVerifiedPhoneNumber($user);

        return redirect()->route('whatsapp-settings')->with('message', ChannelLabel::inject('WhatsAppMfa.phoneRemovedMessage'));
    }

    // -------------------------------------------------------------------
    // Testing WhatsApp delivery ahead of a $channel migration - see this
    // class's own doc comment, and PhoneNumberStore's, for the full
    // account of why this exists. Deliberately mirrors the
    // enroll()/send()/verify()/confirm() shape above rather than
    // threading a "test mode" flag through those already-working
    // methods - safer than risking a regression in the normal flow to
    // support this one.
    // -------------------------------------------------------------------

    public function testEnroll(): string|RedirectResponse
    {
        if (! $this->whatsAppTestIsRelevant()) {
            return redirect()->route('whatsapp-settings');
        }

        $user = auth()->user();

        return view($this->config->views['whatsapp_settings_test_enroll'], [
            'verifiedPhone' => $this->store->getVerifiedPhoneNumber($user),
        ]);
    }

    public function testSend(): RedirectResponse
    {
        if (! $this->whatsAppTestIsRelevant()) {
            return redirect()->route('whatsapp-settings');
        }

        $user  = auth()->user();
        $phone = trim((string) $this->request->getPost('phone'));

        if (! $this->looksLikeAPhoneNumber($phone)) {
            return redirect()->back()->withInput()->with('error', lang('WhatsAppMfa.invalidPhoneNumber'));
        }

        $code = $this->store->beginWhatsAppTest($user, $phone);

        // Uses the CONFIGURED sender class, not a hardcoded
        // `new TwilioWhatsAppSender()` - whatsAppTestIsRelevant() above
        // already guarantees $this->config->sender is
        // TwilioWhatsAppSender or a subclass of it, so this is safe,
        // and it means a test can substitute a subclass that overrides
        // the real network call without this method needing to know
        // about that at all.
        $senderClass = $this->config->sender;
        /** @var TwilioWhatsAppSender $sender */
        $sender = new $senderClass();

        // Same sender-failure rollback pattern already applied
        // throughout this package (see send() above, and
        // WhatsAppActivator's own identical fix) - a real Twilio API
        // error here shouldn't crash the request or leave an orphaned
        // test-pending record behind.
        try {
            $sender->sendViaWhatsAppRegardlessOfChannel($phone, $code, $this->config);
        } catch (\Throwable $e) {
            $this->store->cancelWhatsAppTest($user);

            return redirect()->back()->withInput()->with('error', lang('WhatsAppMfa.testSendFailedMessage'));
        }

        return redirect()->route('whatsapp-settings-test-verify');
    }

    public function testVerify(): string|RedirectResponse
    {
        if (! $this->whatsAppTestIsRelevant()) {
            return redirect()->route('whatsapp-settings');
        }

        $user  = auth()->user();
        $phone = $this->store->getWhatsAppTestPendingPhoneNumber($user);

        if ($phone === null) {
            return redirect()->route('whatsapp-settings-test-enroll')
                ->with('error', lang('WhatsAppMfa.noPendingCode'));
        }

        return view($this->config->views['whatsapp_settings_test_verify'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    public function testConfirm(): RedirectResponse
    {
        if (! $this->whatsAppTestIsRelevant()) {
            return redirect()->route('whatsapp-settings');
        }

        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->store->confirmWhatsAppTest($user, $code)) {
            return redirect()->back()->with('error', lang('WhatsAppMfa.invalidCode'));
        }

        return redirect()->route('whatsapp-settings')->with('message', lang('WhatsAppMfa.testConfirmedMessage'));
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
