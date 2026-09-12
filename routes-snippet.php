<?php
/**
 * Add these to app/Config/Routes.php. Shield doesn't know about these
 * controllers/routes, so they need to be added by hand.
 */

// The "skip for now" link on WhatsAppActivator's enrollment views. NOT
// wrapped in the 'session' filter (or any full-login-required filter)
// - it's reached by a user who is mid-registration (Shield's
// "pending" state), not someone already fully logged in. Wrapping it
// in a filter that requires full login would make it unreachable.
//
// Points at WhatsAppActivatorController, NOT at WhatsAppActivator (the
// Action class) directly - routes are dispatched through CodeIgniter's
// Controller lifecycle (initController(), etc.), which an
// ActionInterface implementation doesn't have.
$routes->post(
    'auth/a/whatsapp-activator/skip',
    '\WhatsAppMfa\Controllers\WhatsAppActivatorController::skip',
    ['as' => 'whatsapp-activator-skip']
);

// These are for the self-service phone verification settings pages,
// for an already-fully-logged-in user, so the 'session' filter is
// correct here.
$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get(
        'account/whatsapp',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::index',
        ['as' => 'whatsapp-settings']
    );

    $routes->get(
        'account/whatsapp/enroll',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::enroll',
        ['as' => 'whatsapp-settings-enroll']
    );

    $routes->post(
        'account/whatsapp/send',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::send',
        ['as' => 'whatsapp-settings-send']
    );

    $routes->get(
        'account/whatsapp/verify',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::verify',
        ['as' => 'whatsapp-settings-verify']
    );

    $routes->post(
        'account/whatsapp/confirm',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::confirm',
        ['as' => 'whatsapp-settings-confirm']
    );

    $routes->post(
        'account/whatsapp/disable',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::disable',
        ['as' => 'whatsapp-settings-disable']
    );

    // "Test WhatsApp delivery" - a developer-facing migration tool for
    // confirming a user's number works over WhatsApp specifically,
    // ahead of an app-wide Config\WhatsAppMfa::$channel switch. See
    // PhoneNumberStore's own doc comment ("Testing WhatsApp delivery
    // ahead of a $channel migration") for the full account. Each
    // controller action itself checks whether this is currently
    // relevant (redirecting back to the main settings page if not), so
    // no route-level guard is needed here beyond the same 'session'
    // filter as the rest of this group.
    $routes->get(
        'account/whatsapp/test',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::testEnroll',
        ['as' => 'whatsapp-settings-test-enroll']
    );

    $routes->post(
        'account/whatsapp/test/send',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::testSend',
        ['as' => 'whatsapp-settings-test-send']
    );

    $routes->get(
        'account/whatsapp/test/verify',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::testVerify',
        ['as' => 'whatsapp-settings-test-verify']
    );

    $routes->post(
        'account/whatsapp/test/confirm',
        '\WhatsAppMfa\Controllers\WhatsAppSettingsController::testConfirm',
        ['as' => 'whatsapp-settings-test-confirm']
    );

    // Step-up challenge shown by the RequireFreshWhatsApp filter.
    // Wrapped in the 'session' filter here too - this challenges an
    // EXISTING, already-logged-in session, it doesn't log anyone in, so
    // it makes no sense to reach without already being logged in.
    $routes->get(
        'account/whatsapp/step-up',
        '\WhatsAppMfa\Controllers\WhatsAppStepUpController::show',
        ['as' => 'whatsapp-step-up']
    );

    $routes->post(
        'account/whatsapp/step-up/send',
        '\WhatsAppMfa\Controllers\WhatsAppStepUpController::send',
        ['as' => 'whatsapp-step-up-send']
    );

    $routes->post(
        'account/whatsapp/step-up/verify',
        '\WhatsAppMfa\Controllers\WhatsAppStepUpController::verify',
        ['as' => 'whatsapp-step-up-verify']
    );
});
