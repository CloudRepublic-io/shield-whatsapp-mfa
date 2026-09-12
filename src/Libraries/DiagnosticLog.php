<?php

declare(strict_types=1);

namespace WhatsAppMfa\Libraries;

/**
 * Wraps log_message() so diagnostic logging in this package only ever
 * writes when ENVIRONMENT is 'development' - not in staging or
 * production. Matches the identical class already used by
 * shield-passkey-mfa and shield-mfa-dispatcher in this same series -
 * see either package's own doc comment for the fuller rationale.
 *
 * CONFIRMED, REAL GAP THIS FIXES: every sender-failure catch block in
 * this package (WhatsAppActivator::handle(), WhatsAppMfa::handle(),
 * WhatsAppSettingsController::send()/testSend(),
 * WhatsAppStepUpController::send()) previously caught the real
 * exception and discarded it entirely, showing only a generic "please
 * try again" message - a real report confirmed this made it impossible
 * to tell why a send genuinely failed (an invalid Twilio sender number,
 * a missing Content Template, un unapproved WhatsApp sandbox
 * recipient, bad credentials, etc.) without adding temporary debugging
 * code first. All five now log the actual exception message via this
 * class, and the flash message itself gets a `[diagnostic: ...]`
 * suffix in a development environment specifically - the same pattern
 * already used by shield-passkey-mfa's PasskeyMfa::verify(), for the
 * same reason: showing the real reason to end users isn't something
 * that should happen in production, but development needs it visible
 * immediately, not buried in a log file that might not even be
 * checked.
 */
class DiagnosticLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $message, array $context = []): void
    {
        if (ENVIRONMENT !== 'development') {
            return;
        }

        log_message($level, $message, $context);
    }
}
