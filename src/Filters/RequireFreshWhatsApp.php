<?php

declare(strict_types=1);

namespace WhatsAppMfa\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Forces a fresh WhatsApp challenge before a protected route is
 * reached - "step-up" auth for sensitive actions, on top of and
 * separate from ordinary login MFA. Mirrors shield-totp-mfa's own
 * RequireFreshTotp filter exactly - see that class's own doc comment
 * for the full explanation of why this is independent of "remember
 * this device" and how the freshness window works; not repeated here.
 *
 * Register the alias in app/Config/Filters.php:
 *
 *   public array $aliases = [
 *       ...
 *       'whatsapp-fresh' => \WhatsAppMfa\Filters\RequireFreshWhatsApp::class,
 *   ];
 *
 * then apply it alongside your normal login-required filter:
 *
 *   $routes->group('admin/billing', ['filter' => ['session', 'whatsapp-fresh']], static function ($routes) {
 *       // ... routes for changing Stripe keys, etc.
 *   });
 */
class RequireFreshWhatsApp implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null) {
            // Not this filter's job - let whatever login-required
            // filter runs alongside it (e.g. Shield's 'session')
            // handle an unauthenticated request.
            return null;
        }

        $config = config('WhatsAppMfa');
        $store  = new PhoneNumberStore();

        if (! $store->hasVerifiedPhoneNumber($user)) {
            if (! $config->stepUpRequiresEnrollment) {
                // Policy default: nothing to challenge them with, so
                // don't lock them out of a page they have no way to
                // unlock. Set $config->stepUpRequiresEnrollment = true
                // if you'd rather send them to verify a number first
                // instead.
                return null;
            }

            session()->set('whatsapp_step_up_redirect', (string) current_url(true));

            return redirect()->route($config->stepUpEnrollRouteName)
                ->with('message', lang('WhatsAppMfa.stepUpNeedsEnrollment'));
        }

        $verifiedAt = session($config->stepUpSessionKey);

        if (is_int($verifiedAt) && (time() - $verifiedAt) < $config->stepUpFreshnessSeconds) {
            return null; // still fresh - let the request through
        }

        session()->set('whatsapp_step_up_redirect', (string) current_url(true));

        return redirect()->route('whatsapp-step-up');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the controller runs.
    }
}
