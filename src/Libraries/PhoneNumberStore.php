<?php

declare(strict_types=1);

namespace WhatsAppMfa\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Config\WhatsAppMfa as WhatsAppMfaConfig;

/**
 * Self-service phone number verification, kept entirely separate from
 * WhatsAppMfa::createIdentity() (which generates a fresh LOGIN code
 * every time, deliberately short-lived) - this is a ONE-TIME "prove
 * you actually control this number" step, producing a PERMANENT
 * record that WhatsAppMfa::resolvePhoneNumber() checks first, by
 * default.
 *
 * The permanent, verified phone number is stored via CodeIgniter's own
 * Settings library (https://settings.codeigniter.com/), using a
 * per-user context - NOT as a Shield identity record, as an earlier
 * version of this class did.
 *
 * CONFIRMED, REAL BUG THIS FIXES: storing the verified number as an
 * identity with the actual phone number in the 'secret' column hit
 * Shield's own auth_identities UNIQUE(type, secret) constraint - NOT
 * (user_id, type, secret) - the moment two DIFFERENT accounts verified
 * the SAME phone number (e.g. one person with two accounts, or a
 * shared family phone), throwing a duplicate-key database error on the
 * second account's own, entirely unrelated verification. This is
 * Shield's own base schema, not something this package should (or
 * safely could) alter - the exact same reasoning, and the exact same
 * fix, already applied to shield-mfa-dispatcher's MfaPreference (see
 * that package's README for the fuller account of the same underlying
 * pattern).
 *
 * Three remaining identity types (still real Shield identity records,
 * not moved to Settings - see each one's own reasoning below):
 *
 *   - ID_TYPE_PHONE_PENDING ('whatsapp_phone_pending') - a short-lived
 *     record for a verification attempt in progress, holding the
 *     PROPOSED phone number and the hashed code sent to it. Deleted
 *     the moment verification succeeds, or replaced if a new attempt
 *     is started before the old one is confirmed. Safe to remain an
 *     identity: the 'secret' column holds a bcrypt/argon2 hash of the
 *     code, which is unique per call even for an identical input code,
 *     due to its own internal random salt - no collision risk.
 *   - ID_TYPE_PHONE_ACTIVATE ('whatsapp_phone_activate') - used only by
 *     WhatsAppActivator (the optional registration-time action). A
 *     phone number isn't known yet the moment Shield calls
 *     createIdentity() during registration - it only gets collected
 *     once show() actually renders a form - so this exists purely to
 *     give Shield's own pending-action check something to find in the
 *     meantime. MUST remain a real identity record: Shield's own
 *     setAuthAction() specifically queries auth_identities for a
 *     matching type, so this can't move to Settings the way the
 *     verified phone number could. Deleted once verification actually
 *     succeeds (see confirmVerification()) or the user skips via
 *     WhatsAppActivatorController::skip().
 *   - ID_TYPE_PHONE_STEP_UP ('whatsapp_phone_step_up') - used only by
 *     the step-up flow (RequireFreshWhatsApp/WhatsAppStepUpController).
 *     Deliberately separate from ID_TYPE_PHONE_PENDING: step-up
 *     challenges the user's ALREADY-VERIFIED number (proving they
 *     still control it, for re-confirming identity before a sensitive
 *     action), not a newly-proposed one - conflating the two would let
 *     a step-up challenge in progress interfere with an unrelated
 *     phone-change attempt, or vice versa. Safe to remain an identity,
 *     same reasoning as ID_TYPE_PHONE_PENDING.
 *   - ID_TYPE_WHATSAPP_TEST_PENDING ('whatsapp_channel_test_pending') -
 *     used only by the "test WhatsApp delivery" self-service action
 *     (see "Testing WhatsApp delivery ahead of a $channel migration"
 *     below) - deliberately separate from ID_TYPE_PHONE_PENDING too,
 *     for the identical reason: starting a channel test shouldn't
 *     silently cancel an unrelated in-progress "change my number"
 *     attempt, or vice versa.
 *
 * Self-service settings-page verification (WhatsAppSettingsController)
 * and the login action's own per-login OTP code
 * (WhatsAppMfa::createIdentity()) are NOT wired into Shield's
 * pending-action check at all - only WhatsAppActivator's registration
 * flow is, via ID_TYPE_PHONE_ACTIVATE specifically.
 *
 * Testing WhatsApp delivery ahead of a $channel migration - a real,
 * confirmed gap: Config\WhatsAppMfa::$channel is a single, app-wide
 * setting, so a user whose number was only ever confirmed via SMS has
 * no way to know whether WhatsApp will actually work for them until
 * $channel is switched for everyone at once - Twilio's WhatsApp API
 * accepts a send request regardless of whether the destination number
 * can actually receive WhatsApp (that failure only surfaces later, via
 * an async webhook this package doesn't implement), so a login
 * attempt can silently fail the moment the switch happens. The methods
 * below let a user test WhatsApp delivery specifically, independent of
 * whatever $channel is currently configured, so a developer can
 * confirm delivery for their whole user base BEFORE switching $channel
 * for everyone - see WhatsAppSettingsController's own "test" actions,
 * and the `php spark whatsapp:channel-status` command.
 *
 * The confirmed WhatsApp number is stored the same way as the main
 * verified number (via Settings, not an identity) - but as a SEPARATE
 * key, storing the actual number confirmed (not just a boolean), so a
 * later change to the main verified number is automatically detected
 * as stale rather than needing to remember to clear a separate flag
 * everywhere the verified number changes.
 */
class PhoneNumberStore
{
    public const ID_TYPE_PHONE_PENDING        = 'whatsapp_phone_pending';
    public const ID_TYPE_PHONE_ACTIVATE       = 'whatsapp_phone_activate';
    public const ID_TYPE_PHONE_STEP_UP        = 'whatsapp_phone_step_up';
    public const ID_TYPE_WHATSAPP_TEST_PENDING = 'whatsapp_channel_test_pending';

    private const SETTING_KEY                 = 'WhatsAppMfa.verifiedPhoneNumber';
    private const WHATSAPP_CONFIRMED_SETTING_KEY = 'WhatsAppMfa.whatsappChannelConfirmedNumber';

    protected UserIdentityModel $identities;
    protected WhatsAppMfaConfig $config;

    public function __construct()
    {
        $this->identities = model(UserIdentityModel::class);
        $this->config     = config('WhatsAppMfa');
    }

    private function contextFor(User $user): string
    {
        return 'user:' . $user->id;
    }

    /**
     * Ensures Shield's own pending-action check has something to find
     * during a registration ceremony - called from
     * WhatsAppActivator::createIdentity(). No expiry tied to a real
     * challenge the way ID_TYPE_PHONE_PENDING has (there's no
     * cryptographic challenge here, just "registration is still in
     * progress") - a generous 1-hour window purely to avoid an
     * abandoned registration attempt lingering forever, not a security
     * boundary the way the other expiry values in this package are.
     *
     * CONFIRMED, REAL BUG FIXED HERE: an earlier version stored a fixed
     * literal string ('n/a') as the secret, on the reasoning that
     * "it's never read, only the marker's existence matters." That
     * reasoning missed Shield's own auth_identities UNIQUE(type,
     * secret) constraint - any two users simultaneously mid-registration
     * (neither having finished yet) would both produce
     * (type='whatsapp_phone_activate', secret='n/a'), an identical
     * pair, and the second person to reach this point would hit a
     * duplicate-key database error. Unlike the verified-phone-number
     * bug this class also fixes (see class doc comment), this one
     * needed no coincidence at all - any two people registering at
     * roughly the same time would trigger it, in any real app with more
     * than a handful of users. Randomizing the value (matching
     * shield-passkey-mfa's own PasskeyIdentityStore::ensureActivationMarker(),
     * which already did this correctly) removes the collision entirely,
     * without needing to move this identity type to Settings the way
     * the verified phone number was - this one still needs to remain a
     * real identity record for Shield's own pending-check to find.
     */
    public function ensureActivationMarker(User $user): void
    {
        $existing = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_ACTIVATE)
            ->first();

        if ($existing !== null && $existing->expires->getTimestamp() > time()) {
            return;
        }

        if ($existing !== null) {
            $this->identities->delete($existing->id);
        }

        $this->identities->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_PHONE_ACTIVATE,
            'name'    => null,
            'secret'  => bin2hex(random_bytes(8)), // unused; only its existence matters - randomized so no two users' rows can collide
            'extra'   => null,
            'expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    public function cancelActivation(User $user): void
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_ACTIVATE)
            ->delete();
    }

    public function getVerifiedPhoneNumber(User $user): ?string
    {
        $value = service('settings')->get(self::SETTING_KEY, $this->contextFor($user));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasVerifiedPhoneNumber(User $user): bool
    {
        return $this->getVerifiedPhoneNumber($user) !== null;
    }

    /**
     * What phone number is a verification attempt currently pending
     * for, if any - used by the "enter code" view to show which
     * number the code was sent to.
     */
    public function getPendingPhoneNumber(User $user): ?string
    {
        $pending = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_PENDING)
            ->first();

        return $pending->name ?? null;
    }

    /**
     * Starts a verification attempt for the given number, returning
     * the plaintext code for the caller to actually send - this class
     * has no sender dependency of its own; the settings controller
     * reuses whichever sender WhatsAppMfa itself is already configured
     * with, rather than this class duplicating that wiring.
     */
    public function beginVerification(User $user, string $phoneNumber): string
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_PENDING)
            ->delete();

        $code = $this->generateCode();

        $this->identities->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_PHONE_PENDING,
            'name'    => $phoneNumber,
            'secret'  => password_hash($code, PASSWORD_DEFAULT),
            'extra'   => null,
            'expires' => date('Y-m-d H:i:s', time() + $this->config->codeLifetime),
        ]);

        return $code;
    }

    /**
     * Checks the submitted code against the pending verification
     * attempt, and on success, promotes it to the permanent verified
     * record (replacing any previous one). Returns false (never
     * throws) on any failure - a wrong, expired, or missing code is a
     * normal, expected outcome to handle gracefully.
     */
    public function confirmVerification(User $user, string $code): bool
    {
        $pending = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_PENDING)
            ->first();

        if ($pending === null) {
            return false;
        }

        if ($pending->expires !== null && $pending->expires->getTimestamp() < time()) {
            $this->identities->delete($pending->id);

            return false;
        }

        if (! password_verify($code, $pending->secret)) {
            return false;
        }

        $phoneNumber = $pending->name;
        $this->identities->delete($pending->id);

        service('settings')->set(self::SETTING_KEY, $phoneNumber, $this->contextFor($user));

        // Harmless no-op if this verification wasn't part of a
        // registration-time flow (WhatsAppSettingsController never
        // creates one) - only relevant when WhatsAppActivator is in
        // use.
        $this->cancelActivation($user);

        return true;
    }

    public function cancelVerification(User $user): void
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_PENDING)
            ->delete();
    }

    /**
     * Rolls back a step-up challenge that was created but never actually
     * delivered - added specifically so a sender failure mid-send
     * (WhatsAppStepUpController now catches this) doesn't leave an
     * orphaned ID_TYPE_PHONE_STEP_UP row behind that the user has no way
     * to ever satisfy, since the code inside it was never actually sent
     * to them.
     */
    public function cancelStepUp(User $user): void
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_STEP_UP)
            ->delete();
    }

    public function removeVerifiedPhoneNumber(User $user): void
    {
        service('settings')->forget(self::SETTING_KEY, $this->contextFor($user));
        // A removed number can no longer be "confirmed for WhatsApp" -
        // there's nothing left for that confirmation to refer to.
        service('settings')->forget(self::WHATSAPP_CONFIRMED_SETTING_KEY, $this->contextFor($user));
    }

    // -------------------------------------------------------------------
    // Testing WhatsApp delivery ahead of a $channel migration - see this
    // class's own doc comment for the full account of why this exists.
    // Entirely independent of ID_TYPE_PHONE_PENDING (the normal
    // "change my number" flow) and of Config\WhatsAppMfa::$channel - a
    // test attempt always sends via WhatsApp specifically, regardless
    // of what $channel is currently configured to.
    // -------------------------------------------------------------------

    /**
     * What phone number a WhatsApp channel test is currently pending
     * for, if any - used by the "enter code" view, same purpose as
     * getPendingPhoneNumber() but for the separate test flow.
     */
    public function getWhatsAppTestPendingPhoneNumber(User $user): ?string
    {
        $pending = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_TEST_PENDING)
            ->first();

        return $pending->name ?? null;
    }

    /**
     * Starts a WhatsApp channel test for the given number, returning
     * the plaintext code for the caller to actually send - same "no
     * sender dependency of its own" reasoning as beginVerification().
     * The caller is expected to send this via WhatsApp SPECIFICALLY
     * (TwilioWhatsAppSender::sendViaWhatsAppRegardlessOfChannel()),
     * regardless of Config\WhatsAppMfa::$channel's own current value.
     */
    public function beginWhatsAppTest(User $user, string $phoneNumber): string
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_TEST_PENDING)
            ->delete();

        $code = $this->generateCode();

        $this->identities->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_WHATSAPP_TEST_PENDING,
            'name'    => $phoneNumber,
            'secret'  => password_hash($code, PASSWORD_DEFAULT),
            'extra'   => null,
            'expires' => date('Y-m-d H:i:s', time() + $this->config->codeLifetime),
        ]);

        return $code;
    }

    /**
     * Checks the submitted code against the pending WhatsApp channel
     * test, and on success, records that number as confirmed to work
     * over WhatsApp specifically - a SEPARATE record from the main
     * verified number (see this class's own doc comment for why:
     * storing the confirmed NUMBER, not just a boolean, so a later
     * change to the main verified number is automatically detected as
     * stale). Returns false (never throws) on any failure - a wrong,
     * expired, or missing code is a normal, expected outcome to handle
     * gracefully.
     */
    public function confirmWhatsAppTest(User $user, string $code): bool
    {
        $pending = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_TEST_PENDING)
            ->first();

        if ($pending === null) {
            return false;
        }

        if ($pending->expires !== null && $pending->expires->getTimestamp() < time()) {
            $this->identities->delete($pending->id);

            return false;
        }

        if (! password_verify($code, $pending->secret)) {
            return false;
        }

        $phoneNumber = $pending->name;
        $this->identities->delete($pending->id);

        service('settings')->set(self::WHATSAPP_CONFIRMED_SETTING_KEY, $phoneNumber, $this->contextFor($user));

        return true;
    }

    /**
     * Rolls back a WhatsApp channel test that was started but never
     * actually delivered - same reasoning as cancelVerification()/
     * cancelStepUp(): a sender failure mid-send shouldn't leave an
     * orphaned pending record behind that the user has no way to ever
     * satisfy, since the code inside it was never actually sent.
     */
    public function cancelWhatsAppTest(User $user): void
    {
        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_WHATSAPP_TEST_PENDING)
            ->delete();
    }

    /**
     * Has WhatsApp delivery been confirmed for this user's CURRENT
     * verified number specifically? Checking the stored confirmed
     * number against the current verified number (rather than a plain
     * boolean) means this correctly returns false the moment the
     * verified number changes to something new that hasn't itself been
     * tested yet - no separate step needed to remember to clear a
     * stale flag.
     */
    public function hasConfirmedWhatsAppDelivery(User $user): bool
    {
        $verifiedNumber = $this->getVerifiedPhoneNumber($user);

        if ($verifiedNumber === null) {
            return false;
        }

        $confirmedNumber = service('settings')->get(self::WHATSAPP_CONFIRMED_SETTING_KEY, $this->contextFor($user));

        return $confirmedNumber === $verifiedNumber;
    }

    /**
     * The specific number WhatsApp delivery was last confirmed for, if
     * any - regardless of whether it still matches the current
     * verified number (unlike hasConfirmedWhatsAppDelivery(), which
     * checks that match). Used by the `whatsapp:channel-status` CLI
     * command to show a genuinely stale confirmation distinctly from
     * "never tested at all", rather than collapsing both into the same
     * "not confirmed" result.
     */
    public function getConfirmedWhatsAppNumber(User $user): ?string
    {
        $value = service('settings')->get(self::WHATSAPP_CONFIRMED_SETTING_KEY, $this->contextFor($user));

        return is_string($value) && $value !== '' ? $value : null;
    }

    // -------------------------------------------------------------------
    // Step-up challenges (RequireFreshWhatsApp / WhatsAppStepUpController)
    // -------------------------------------------------------------------

    /**
     * Starts a step-up challenge: sends a fresh code to the user's
     * ALREADY-VERIFIED phone number (not a newly-proposed one - see
     * this class's own doc comment for why that distinction matters),
     * for re-confirming identity before a sensitive action. Returns
     * the plaintext code for the caller to actually send - same "no
     * sender dependency of its own" reasoning as beginVerification().
     * Returns null if the user has no verified number to challenge
     * against at all - RequireFreshWhatsApp should never reach this
     * method in that case, but this keeps it safe to call regardless.
     */
    public function beginStepUpChallenge(User $user): ?string
    {
        $phone = $this->getVerifiedPhoneNumber($user);

        if ($phone === null) {
            return null;
        }

        $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_STEP_UP)
            ->delete();

        $code = $this->generateCode();

        $this->identities->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_PHONE_STEP_UP,
            'name'    => null,
            'secret'  => password_hash($code, PASSWORD_DEFAULT),
            'extra'   => null,
            'expires' => date('Y-m-d H:i:s', time() + $this->config->codeLifetime),
        ]);

        return $code;
    }

    /**
     * Checks the submitted code against the pending step-up challenge,
     * consuming it so it can't be replayed. Returns false (never
     * throws) on any failure - a wrong, expired, or missing code is a
     * normal, expected outcome to handle gracefully.
     */
    public function verifyStepUpChallenge(User $user, string $code): bool
    {
        $challenge = $this->identities
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_PHONE_STEP_UP)
            ->first();

        if ($challenge === null) {
            return false;
        }

        if ($challenge->expires !== null && $challenge->expires->getTimestamp() < time()) {
            $this->identities->delete($challenge->id);

            return false;
        }

        if (! password_verify($code, $challenge->secret)) {
            return false;
        }

        $this->identities->delete($challenge->id);

        return true;
    }

    /**
     * A 6-digit numeric code, digits 1-9 only (no zero, to avoid any
     * "0 vs O" confusion when a user reads it off their phone) -
     * matches what an earlier version of this method got from CI4's
     * random_string('nozero', 6) text helper. Self-contained rather
     * than relying on that helper deliberately: it's part of a
     * "helper" group that isn't autoloaded by default, and this class
     * never called helper('text') to load it - a real bug that broke
     * this exact method in practice ("Call to undefined function
     * WhatsAppMfa\Libraries\random_string()"), not a hypothetical one.
     * Using only built-in PHP here removes that whole class of failure
     * rather than just remembering to load the right helper.
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
     * Every user_id with a verified phone number on file, alongside
     * that number and whether WhatsApp delivery has been confirmed for
     * it specifically - used by `php spark whatsapp-mfa:channel-status`
     * to give a developer an at-a-glance view of how ready their user
     * base is for a Config\WhatsAppMfa::$channel migration to
     * 'whatsapp', without needing to query the Settings table by hand.
     *
     * Queries the `settings` table directly (CodeIgniter's own
     * codeigniter4/settings package, confirmed against its own test
     * suite: columns class/key/value/type/context) rather than through
     * service('settings') itself, since that library has no "list every
     * context a given key was ever set under" method - only get/set/
     * forget for one context at a time. If your app has configured a
     * non-default table name for that library, update $settingsTable
     * below to match.
     *
     * @return array<int, array{user_id: int, verified_number: string, whatsapp_confirmed: bool}>
     */
    public function listVerificationStatuses(): array
    {
        $settingsTable = 'settings';

        $rows = db_connect()
            ->table($settingsTable)
            ->where('class', 'WhatsAppMfa')
            ->where('key', 'verifiedPhoneNumber')
            ->get()
            ->getResultArray();

        $statuses = [];

        foreach ($rows as $row) {
            $context = (string) ($row['context'] ?? '');

            if (! str_starts_with($context, 'user:')) {
                continue;
            }

            $userId         = (int) substr($context, 5);
            $verifiedNumber = (string) $row['value'];

            $confirmedNumber = service('settings')->get(self::WHATSAPP_CONFIRMED_SETTING_KEY, $context);

            $statuses[] = [
                'user_id'            => $userId,
                'verified_number'    => $verifiedNumber,
                'whatsapp_confirmed' => $confirmedNumber === $verifiedNumber,
            ];
        }

        return $statuses;
    }
}
