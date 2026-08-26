<?php

declare(strict_types=1);

namespace WhatsAppMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use WhatsAppMfa\Libraries\CompletesPendingAction;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Handles the "skip for now" link on WhatsAppActivator's enrollment
 * view.
 *
 * This has to be a real Controller, not a method on WhatsAppActivator
 * itself - routes are dispatched through CodeIgniter's normal
 * Controller lifecycle, which an ActionInterface implementation
 * doesn't have. See TotpActivatorController (in shield-totp-mfa) for
 * the fuller explanation; not repeated here.
 */
class WhatsAppActivatorController extends Controller
{
    use CompletesPendingAction;

    public function skip(): RedirectResponse
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('WhatsAppActivatorController: cannot get the pending registration user.');
        }

        $store = new PhoneNumberStore();
        $store->cancelVerification($user);
        $store->cancelActivation($user);

        $this->completePendingAction($user);

        $authenticator->getUser()->activate();

        return redirect()->to(config('Auth')->registerRedirect());
    }
}
