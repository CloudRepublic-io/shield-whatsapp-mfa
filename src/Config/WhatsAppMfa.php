<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use WhatsAppMfa\Sender\MetaCloudApiSender;
use WhatsAppMfa\Sender\TwilioWhatsAppSender;

/**
 * Copy this file to app/Config/WhatsAppMfa.php in the host application
 * so it can be customized/overridden per project.
 */
class WhatsAppMfa extends BaseConfig
{
    /**
     * Which sender class delivers the code. Must implement
     * WhatsAppSenderInterface. Swap to TwilioWhatsAppSender::class,
     * or your own implementation, without touching the Action class.
     *
     * @var class-string<WhatsAppSenderInterface>
     */
    public string $sender = MetaCloudApiSender::class;

    /**
     * How long (in seconds) a generated code remains valid.
     */
    public int $codeLifetime = 300; // 5 minutes

    /**
     * Minimum seconds the user must wait before requesting a new code
     * (enforced in the view/JS; keep a server-side check too if you
     * expose a public resend endpoint).
     */
    public int $resendCooldown = 30;

    /**
     * How the phone number is found on the pending user. Set to a
     * property/method your User entity actually has. See README for
     * how to store a verified phone number as a Shield "identity".
     */
    public string $phoneNumberField = 'phone';

    // -- Meta WhatsApp Cloud API -------------------------------------------------

    public string $metaPhoneNumberId    = '';
    public string $metaAccessToken      = '';
    public string $metaTemplateName     = 'otp_login';
    public string $metaTemplateLanguage = 'en_US';

    // -- Twilio -------------------------------------------------------------------

    /**
     * Which channel TwilioWhatsAppSender actually sends through -
     * 'whatsapp' (the default, unchanged from before this setting
     * existed) or 'sms'. An app-wide toggle, not a per-user choice -
     * every user gets whichever channel is configured here.
     *
     * SMS has no equivalent to WhatsApp's own Content Template
     * requirement for messages sent outside a 24h session window - it
     * can always send free-form text - so $twilioContentSid below is
     * simply ignored whenever $channel is 'sms', regardless of whether
     * it's set.
     *
     * Reuses $twilioFromNumber below for BOTH channels - TwilioWhatsAppSender
     * strips or adds the 'whatsapp:' prefix on that same value as
     * needed, rather than requiring a second, separate "from" number
     * configured here. This assumes your Twilio number is capable of
     * both channels, which is common but not universal - if your
     * WhatsApp-approved sender and your SMS-capable number are
     * genuinely different numbers on your Twilio account, don't just
     * flip this to 'sms' without first confirming $twilioFromNumber
     * itself is also updated to a number that can actually send SMS.
     *
     * @var 'whatsapp'|'sms'
     */
    public string $channel = 'whatsapp';

    public string $twilioSid         = '';
    public string $twilioAuthToken   = '';
    public string $twilioFromNumber  = ''; // e.g. "whatsapp:+14155238886" - the 'whatsapp:' prefix is optional here regardless of $channel; see TwilioWhatsAppSender
    public string $twilioContentSid  = ''; // leave blank to use plainMessageTemplate instead - ignored entirely when $channel is 'sms'

    /**
     * Used by TwilioWhatsAppSender only when twilioContentSid is empty
     * (i.e. sending free-form text inside Twilio's 24h session window).
     * %s is replaced with the code.
     */
    public string $plainMessageTemplate = 'Your verification code is %s. It expires in 5 minutes.';

    // -- Step-up auth for sensitive pages (RequireFreshWhatsApp filter) -----

    /**
     * How long, in seconds, a step-up WhatsApp challenge stays "fresh"
     * before a protected page requires the user to enter a code again.
     * See shield-totp-mfa's Config\TotpMfa::$stepUpFreshnessSeconds for
     * the full explanation of why this is independent of anything
     * login-time.
     */
    public int $stepUpFreshnessSeconds = 900; // 15 minutes

    /** Session key used to record when the user last passed a step-up challenge. */
    public string $stepUpSessionKey = 'whatsapp_step_up_verified_at';

    /**
     * If true, a user with no verified WhatsApp number at all is
     * redirected to verify one before a step-up-protected page is
     * reached, rather than being let through with nothing to challenge
     * them against.
     */
    public bool $stepUpRequiresEnrollment = false;

    /**
     * Route name to send an unverified user to when
     * $stepUpRequiresEnrollment is true. Defaults to the standalone
     * WhatsAppSettingsController's enrollment route - change this to
     * 'mfa-settings-whatsapp-enroll' if you're using the
     * shield-mfa-dispatcher package's settings page instead.
     */
    public string $stepUpEnrollRouteName = 'whatsapp-settings-enroll';

    // -- Views ----------------------------------------------------------------

    /**
     * View paths used by this package, keyed by a logical name - the
     * same pattern Shield itself uses for Config\Auth::$views.
     * Override either of these in your own copy of this file to point
     * at your own view files instead. Whatever you substitute in must
     * accept the same variables the default expects - check the
     * corresponding file under src/Views/ for exactly what's passed.
     */
    public array $views = [
        'whatsapp_mfa_show'         => 'WhatsAppMfa\Views\whatsapp_mfa_show',
        'whatsapp_mfa_verify'       => 'WhatsAppMfa\Views\whatsapp_mfa_verify',
        'whatsapp_settings_index'   => 'WhatsAppMfa\Views\whatsapp_settings_index',
        'whatsapp_settings_enroll'  => 'WhatsAppMfa\Views\whatsapp_settings_enroll',
        'whatsapp_settings_verify'  => 'WhatsAppMfa\Views\whatsapp_settings_verify',
        'whatsapp_activator_enroll' => 'WhatsAppMfa\Views\whatsapp_activator_enroll',
        'whatsapp_activator_verify' => 'WhatsAppMfa\Views\whatsapp_activator_verify',
        'whatsapp_step_up_show'     => 'WhatsAppMfa\Views\whatsapp_step_up_show',
        'whatsapp_step_up_verify'   => 'WhatsAppMfa\Views\whatsapp_step_up_verify',
        'whatsapp_settings_test_enroll' => 'WhatsAppMfa\Views\whatsapp_settings_test_enroll',
        'whatsapp_settings_test_verify' => 'WhatsAppMfa\Views\whatsapp_settings_test_verify',
    ];

    public function __construct()
    {
        parent::__construct();

        // Pull secrets from .env instead of hardcoding them here.
        $this->metaPhoneNumberId = env('whatsAppMfa.phoneNumberId', $this->metaPhoneNumberId);
        $this->metaAccessToken   = env('whatsAppMfa.accessToken', $this->metaAccessToken);
        $this->twilioSid         = env('whatsAppMfa.twilioSid', $this->twilioSid);
        $this->twilioAuthToken   = env('whatsAppMfa.twilioAuthToken', $this->twilioAuthToken);
        $this->twilioFromNumber  = env('whatsAppMfa.twilioFromNumber', $this->twilioFromNumber);
        $this->twilioContentSid  = env('whatsAppMfa.twilioContentSid', $this->twilioContentSid);
    }
}
