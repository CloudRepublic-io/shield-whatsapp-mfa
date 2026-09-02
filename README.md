# Shield WhatsApp MFA

A drop-in Authentication **Action** for [CodeIgniter Shield](https://shield.codeigniter.com/)
that sends a 6-digit one-time code over WhatsApp instead of email, for use
as a second factor after login (or as the confirmation step after
registration).

It follows the exact same `show()` / `handle()` / `verify()` lifecycle as
Shield's built-in `Email2FA` action, just swapping the delivery channel.

Also includes **`WhatsAppSettingsController`** - lets an already-logged-in
user self-service verify (or change, or remove) a WhatsApp number, the
same "add this later" shape as `shield-totp-mfa`'s
`TotpSettingsController` and `shield-passkey-mfa`'s
`PasskeySettingsController`. Without this, WhatsApp MFA only works if
your app already has a verified phone number on file for every user;
with it, a user can turn WhatsApp on for themselves at any time, the
same as they'd add a passkey or set up an authenticator app.

## What's in the box

```
src/
  Authentication/Actions/
    WhatsAppMfa.php                       <- 'login' action: sends + verifies a fresh code each time
    WhatsAppActivator.php                 <- 'register' action: optional phone verification at signup
  Commands/Setup.php                      <- `php spark whatsapp-mfa:setup`
  Config/WhatsAppMfa.php                  <- provider + credentials config, step-up settings, view overrides
  Controllers/
    WhatsAppActivatorController.php       <- handles WhatsAppActivator's "skip for now" link
    WhatsAppSettingsController.php        <- self-service phone verification
    WhatsAppStepUpController.php          <- step-up challenge (send + verify)
  Filters/RequireFreshWhatsApp.php        <- step-up auth filter for sensitive routes
  Sender/WhatsAppSenderInterface.php      <- contract for delivery providers
  Sender/MetaCloudApiSender.php           <- Meta WhatsApp Cloud API (default)
  Sender/TwilioWhatsAppSender.php         <- Twilio WhatsApp alternative
  Language/en/WhatsAppMfa.php
  Libraries/
    PhoneNumberStore.php                  <- shared verified-phone storage/verification/step-up logic
    CompletesPendingAction.php            <- shared "finish this pending action" trait
  Views/
    whatsapp_mfa_show.php                 <- "we're about to send you a code" (login)
    whatsapp_mfa_verify.php               <- "enter your code" + resend (login)
    whatsapp_activator_enroll.php         <- phone entry + skip (registration)
    whatsapp_activator_verify.php         <- confirm the code sent to it (registration)
    whatsapp_settings_index.php           <- current verified number, add/change/remove
    whatsapp_settings_enroll.php          <- enter a phone number to verify (self-service)
    whatsapp_settings_verify.php          <- confirm the code sent to it (self-service)
    whatsapp_step_up_show.php             <- "we're about to send a code" (step-up)
    whatsapp_step_up_verify.php           <- confirm the code sent to it (step-up)
routes-snippet.php                        <- routes to add by hand
```

## How Shield Actions work (quick recap)

Shield lets you plug in a class for what happens right after `register`
or `login`. You register it in `app/Config/Auth.php`:

```php
public array $actions = [
    'register' => null,
    'login'    => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
];
```

Shield's own routes (added by `service('auth')->routes($routes)`, which
most Shield installs already call) point at a generic `ActionController`
that calls `show()`, `handle()`, and `verify()` on whichever class you
configured. You don't need to add routes yourself.

## Installation

### Option A - via Composer (recommended)

1. `composer require cloudrepublic/shield-whatsapp-mfa`.
2. Run the setup command - publishes `Config/WhatsAppMfa.php` and
   `Language/en/WhatsAppMfa.php` into your app:

   ```
   php spark whatsapp-mfa:setup
   ```

### Option B - manual drop-in

1. Copy `src/` into your app (e.g. `app/ThirdParty/WhatsAppMfa/src`)
   and register the namespace in `app/Config/Autoload.php`:

   ```php
   public array $psr4 = [
       APP_NAMESPACE  => APPPATH,
       'WhatsAppMfa'  => APPPATH . 'ThirdParty/WhatsAppMfa/src',
   ];
   ```

2. Copy `Config/WhatsAppMfa.php` to `app/Config/WhatsAppMfa.php`, and
   `Language/en/WhatsAppMfa.php` to `app/Language/en/WhatsAppMfa.php`.

### Either way, finish with these

3. **Register the action** in `app/Config/Auth.php`:

   ```php
   public array $actions = [
       'register' => null,
       'login'    => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
   ];
   ```

4. **Add credentials to `.env`** (Meta Cloud API shown; see
   `Config/WhatsAppMfa.php` for Twilio equivalents):

   ```ini
   whatsAppMfa.phoneNumberId = "1234567890"
   whatsAppMfa.accessToken   = "EAAG..."
   ```

   Never commit real tokens — `.env` is git-ignored by default in CI4 apps.

5. **Add the settings routes** from `routes-snippet.php` to
   `app/Config/Routes.php`, and link to `account/whatsapp` from
   wherever your account settings page lives - this is what lets a
   user self-service verify their own WhatsApp number (see "Self-service
   phone verification" below), which is the recommended way to get a
   phone number on file at all. If you'd rather use a phone number your
   app already stores instead, skip this and see step 6.

6. **Or, if you already store phone numbers yourself:** set
   `$phoneNumberField` in `app/Config/WhatsAppMfa.php` to whatever
   property your `User` entity exposes it as (defaults to `phone`) -
   see [customizing the User entity](https://shield.codeigniter.com/customization/user_provider/)
   if you haven't added one yet. `resolvePhoneNumber()` checks the
   self-service verified record first and this as a fallback, so both
   can coexist if you want.

7. **Get a WhatsApp Business sender approved.** Both Meta's Cloud API and
   Twilio require an approved *authentication-category message template*
   for OTP codes sent outside an existing conversation — you can't just
   fire free-form text. Set the template name in
   `app/Config/WhatsAppMfa.php` (`$metaTemplateName` /
   `$twilioContentSid`) to match what you got approved.

8. **If you'd rather verify a phone number at registration time
   instead of (or in addition to) self-service**, register
   `WhatsAppActivator` for `'register'` in `app/Config/Auth.php`:

   ```php
   public array $actions = [
       'register' => \WhatsAppMfa\Authentication\Actions\WhatsAppActivator::class,
       'login'    => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
   ];
   ```

   Then add `WhatsAppActivator`'s own routes from `routes-snippet.php`
   - **including its "skip" route by default.** The enrollment/verify
   views always render a "skip for now" link, regardless of whether
   this route exists - omitting it is safe (the views detect a missing
   route and simply hide the link, rather than throwing when the very
   first user registers), but you'd be silently taking away a "skip"
   option from every new user unless that's actually what you want
   (e.g. because MFA is mandatory for everyone via
   `shield-mfa-dispatcher`'s `$required`/`$requiredMethodsForGroups`).
   If you deliberately don't want "skip" offered at all, omitting the
   route is now enough on its own - you don't need to also edit the
   views.

## Self-service phone verification

`WhatsAppSettingsController` gives an already-logged-in user their own
`account/whatsapp` page to verify (or change, or remove) a WhatsApp
number - the same "add this later" model `shield-totp-mfa` and
`shield-passkey-mfa` use for their own methods. The flow:

1. **`account/whatsapp/enroll`** - enter a phone number.
2. **`account/whatsapp/send`** - a 6-digit code is sent to it via
   whichever sender you've configured (the same `$config->sender` the
   login action itself uses).
3. **`account/whatsapp/verify`** → **`.../confirm`** - enter the code;
   on success, the number becomes the permanent, verified one
   `WhatsAppMfa::resolvePhoneNumber()` reads by default.

This is deliberately a **separate concern** from the per-login OTP
code - see `PhoneNumberStore`'s class doc comment for the full
explanation, but in short: `WhatsAppMfa::createIdentity()` generates a
fresh, short-lived code every single login attempt (a `whatsapp_mfa`
identity), while `PhoneNumberStore` manages one permanent, verified
number that only changes when the user explicitly re-verifies a new
one - stored via CodeIgniter's own Settings library (a per-user
context), not as a Shield identity record. See "Why the verified phone
number is stored via Settings, not a Shield identity" further down for
why - a real, confirmed bug with a Shield-identity-based approach,
fixed in the current version.

**This is NOT wired into Shield's own pending-action check** the way
TOTP/passkey enrollment is - verifying a phone number doesn't make
WhatsApp MFA "the" login method for anyone by itself. If you're using
`shield-mfa-dispatcher`, a user still needs to separately choose
`'whatsapp'` as their preferred method from the dispatcher's own
settings page after verifying their number here. If `WhatsAppMfa` is
registered directly as `Auth::$actions['login']` (not via the
dispatcher), it already runs unconditionally for every login attempt
regardless of this page - self-service verification just controls
*which number* it sends to, not whether it runs at all.

## Why the verified phone number is stored via Settings, not a Shield identity

**Fixed in the current version - update if you're on an older copy.**
An earlier version of `PhoneNumberStore` stored the permanent, verified
phone number as a Shield identity record, with the actual phone number
in the `secret` column - reusing Shield's own `auth_identities` table
the same way every package in this series reuses it for its own
per-user data.

That table's `UNIQUE` constraint, however, is on `(type, secret)` -
**not** `(user_id, type, secret)`. Two *different* accounts both
verifying the *same* phone number - one person with two accounts, or a
shared family phone - produced two rows with the identical
`(type='whatsapp_phone', secret='+15551234567')` pair, and the second
account's own, entirely unrelated verification failed with a
duplicate-key database error. This is Shield's own base schema, not
something this package should (or safely could) alter - the exact same
bug, and the exact same fix, found and applied to
`shield-mfa-dispatcher`'s `MfaPreference` (see that [package's]('https://github.com/CloudRepublic-io/shield-mfa-dispatcher') README
for the fuller account of the same underlying pattern).

`PhoneNumberStore` now stores the verified number through
CodeIgniter's own [Settings library](https://settings.codeigniter.com/)
instead, using its per-user **context** mechanism - each user's number
is stored and looked up under its own context string, with no shared
uniqueness constraint between different users' rows at all. Nothing
about `PhoneNumberStore`'s own public API changed - only its internal
storage mechanism for this one identity type specifically. The other
three identity types (`ID_TYPE_PHONE_PENDING`, `ID_TYPE_PHONE_ACTIVATE`,
`ID_TYPE_PHONE_STEP_UP`) remain real Shield identity records - see
`PhoneNumberStore`'s own class doc comment for why each one is safe to
remain one, or (for `ID_TYPE_PHONE_ACTIVATE`) needs to.

### A second, more severe bug found and fixed alongside it

While fixing the above, a related but more severe bug was found in
`ensureActivationMarker()` (the registration-in-progress marker
`WhatsAppActivator` creates): it stored a **fixed literal string**
(`'n/a'`) as the secret, reasoning that "it's never read, only the
marker's existence matters." That reasoning missed the same
`UNIQUE(type, secret)` constraint - any two people registering at
roughly the same time, **regardless of phone number**, would both
produce `(type='whatsapp_phone_activate', secret='n/a')`, an identical
pair. Unlike the phone-sharing bug above, this one needed no
coincidence at all - it's a real risk for any live, multi-user app.
Fixed by randomizing the value (`bin2hex(random_bytes(8))`), matching
the pattern `shield-passkey-mfa`'s own activation marker already used
correctly. This identity type still needs to remain a real Shield
record (Shield's own pending-check queries it directly), so it wasn't
moved to Settings the way the verified phone number was - only its
secret's *content* needed to change.

## If you get a "must implement getType, createIdentity" fatal error

This package was written against a version of `ActionInterface` that
only required `show()`/`handle()`/`verify()`. Current Shield versions
also require `getType()` (returns the identity type string this
action uses - `'whatsapp_mfa'` here) and `createIdentity(User $user): string`
(generates the code, stores it, and returns the plaintext value so the
caller can send it). Both are implemented in `WhatsAppMfa.php` -
`handle()` now calls `$this->createIdentity($user)` rather than doing
that work inline, matching how Shield's own `Email2FA` separates
"create the code" from "send the code". If you're reading this having
pulled an older copy of this file, update it from the current version.

## Login completion: `completeLogin()`, not `login()`

`verify()` calls `auth('session')->getAuthenticator()->completeLogin($user)`
to finish the pending login. This was originally a guessed placeholder
(`login($user)`), flagged as something to double-check - and the
TotpMfa package (built later in this series) hit the actual failure
mode: Shield's `login()` is a stricter, separate public method (used
for things like remember-me auto-login) that refuses if it finds
leftover identities for the pending action type, with an error like
*"The user has identities for action, so cannot complete login."*
`completeLogin()` is the method Shield's own `Session::attempt()`
actually calls to finish a pending action, and doesn't have that
restriction. Confirmed against Shield v1.3.0's real source; still
worth a quick sanity check against whichever version you're running if
you hit login issues after upgrading Shield -
`vendor/codeigniter4/shield/src/Authentication/Authenticators/Session.php`.

## Switching providers

`Config/WhatsAppMfa.php` has a single `$sender` property:

```php
public string $sender = \WhatsAppMfa\Sender\MetaCloudApiSender::class;
// or
public string $sender = \WhatsAppMfa\Sender\TwilioWhatsAppSender::class;
```

To use a different provider entirely (360dialog, Vonage, an in-house
gateway), implement `WhatsAppSenderInterface` (one method: `send()`) and
point `$sender` at your class. The Action itself never talks to any
provider API directly.

## Security notes

- Codes are hashed with `password_hash()` before storage — never stored
  or logged in plaintext.
- Codes expire (`$config->codeLifetime`, default 5 minutes) and are
  single-use (deleted on successful verify, and any older pending code
  is deleted before a new one is issued).
- Consider adding rate limiting on the `auth/a/handle` (resend) and
  `auth/a/verify` (guess attempts) routes via CodeIgniter's `throttle`
  filter, the same way Shield already rate-limits login attempts.
- WhatsApp delivery is not end-to-end guaranteed instant — a code should
  still expire and be resendable rather than assumed to arrive
  immediately.

## If the page loads but shows nothing at all

The views in this package wrap their content in a section named
`'main'`, matching what Shield's own layout (`setting('Auth.views')['layout']`)
actually renders - Shield's own `login.php` uses the same name. An
earlier version of these files used a section called `'content'`
instead, which that layout never displays: the page loads without any
error (nothing is actually wrong, syntactically), it just never gets
inserted into the page, so you'd see a blank content area with no log
entry to explain it. If you still see nothing after updating, check
whether you're using a custom `Auth.views['layout']` and confirm what
section name it renders.

## Overriding views

Every view this package renders is looked up through
`Config\WhatsAppMfa::$views`, the same pattern Shield itself uses for
`Config\Auth::$views`. To use your own view instead of a default,
override its entry in your `app/Config/WhatsAppMfa.php`:

```php
public array $views = [
    'whatsapp_mfa_show' => 'App\Views\auth\my_whatsapp_show',
    // any key you don't list keeps using this package's default
];
```

Your replacement doesn't need to live under any particular namespace -
anywhere `view()` can resolve works. It does need to accept the same
variables the default expects; check the matching file under
`src/Views/` for exactly what's passed. The overridable keys:
`whatsapp_mfa_show` and `whatsapp_mfa_verify` (login), and
`whatsapp_settings_index`, `whatsapp_settings_enroll`, and
`whatsapp_settings_verify` (self-service settings).

## If you get "Declaration must be compatible" when loading this class

An earlier version of `handle()` declared a `: string` return type,
written before `ActionInterface`'s actual current contract (confirmed
via the shield-totp-mfa package's real test suite) was known:
`handle()` must return `Response`, not a raw string. That mismatch
would fatal the moment this class is ever loaded under a Shield
version with that contract - it went unnoticed because this package
had never actually been exercised end-to-end until tests were written
directly against it. Fixed: `handle()` now builds the view body and
returns it via `service('response')->setBody($body)`, matching
`show()`'s own confirmed-correct pattern in the other packages in this
series. If you're on an older copy of this file, replace it.

## If you get "Call to undefined function WhatsAppMfa\Libraries\random_string()"

Fixed in the current version - update if you're on an older copy. Both
`PhoneNumberStore::beginVerification()` and `WhatsAppMfa::createIdentity()`
used to call CI4's `random_string()` text helper to generate the
6-digit code, without ever calling `helper('text')` first - that
helper isn't autoloaded by default, and nothing else in either class
loaded it. This is a real bug that broke both methods in a real app,
not a hypothetical one.

Worth knowing if you're wondering why the existing test suite didn't
catch this despite exercising both methods extensively: PHP function
definitions, once loaded via `helper()`, stay loaded for the rest of
that process - something else running earlier in the same shared
PHPUnit process most likely loaded the `text` helper for an unrelated
reason, masking the bug there while it broke a real app directly. This
is exactly the kind of load-order fragility that's easy to not notice
in a test environment - fixed by removing the dependency on the helper
entirely (a small, self-contained `generateCode()` method using only
`random_int()`), not just adding the missing `helper('text')` call,
which would still leave this fragile to whatever happens to run first.

## If you get "Can't find a route for 'GET: auth/a/handle'" after entering a code

Fixed in the current version - update if you're on an older copy. This
was a real, confirmed bug in `WhatsAppMfa::verify()` and
`WhatsAppActivator::verify()`'s error handling, not something specific
to your setup.

The code-entry page is rendered directly by `handle()` - a **POST**
response to `auth/a/handle`, with no redirect in between - so the
browser's address bar stays on that POST-only route while the user is
looking at the form. `redirect()->back()` targets wherever the browser
was last on, which is that same URL; browsers only ever follow a
redirect via GET, and there's no GET route registered at
`auth/a/handle` - hence the error, the moment a wrong or empty code
was submitted.

`TotpMfa`/`PasskeyMfa` never had this problem, and don't need this
fix: their verify forms are rendered by `show()` itself (a GET route),
not by a separate `handle()` step, so `back()` correctly lands on a
real, GET-accessible page for them. WhatsApp's flow has an extra step
(send the code, *then* show the form) that TOTP/passkey don't, which
is exactly what created the gap.

Fixed by redirecting to the named `auth-action-show` route explicitly
instead of `back()` - already the pattern this same method used
correctly for its *other* error cases (an expired or already-consumed
code), just inconsistently missed for a wrong or empty one. Worth
knowing about the one UX trade-off this introduces for
`WhatsAppActivator` specifically: since its `show()` renders the
*phone entry* form (not a code-retry), a wrong code during
registration sends the user back to re-entering their number rather
than just retrying - correct and safe, if a little more friction than
ideal.

Also worth being upfront about why the existing test suite never
caught this either: every test in this whole series calls these
methods directly rather than through real HTTP, so `redirect()->back()`'s
actual behavior (which depends on session-tracked "previous URL"
state that only a real browser request populates) was never once
genuinely exercised by any test here. Regression tests have been added
that check the redirect target explicitly instead.

## Step-up auth for sensitive pages (`RequireFreshWhatsApp` filter)

Everything above concerns login. This is different: a route **filter**
that forces a fresh WhatsApp challenge before reaching a specific page,
even for a user who's already fully logged in - useful for gating
sensitive actions (updating payment/API settings, changing an email
address, etc.) behind re-confirmed identity, the way Stripe, GitHub,
and AWS all do before letting you touch billing or security settings.
Mirrors `shield-totp-mfa`'s own `RequireFreshTotp` filter - see that
package's README for more detail on the design; the short version is
repeated here, plus what's different about WhatsApp specifically.

### Setup

1. Register the filter alias in `app/Config/Filters.php`:

   ```php
   public array $aliases = [
       // ... your existing aliases
       'whatsapp-fresh' => \WhatsAppMfa\Filters\RequireFreshWhatsApp::class,
   ];
   ```

2. Add the step-up challenge routes from `routes-snippet.php` (already
   included if you copied the whole snippet earlier).

3. Apply it to whichever routes need protecting, alongside your normal
   login-required filter:

   ```php
   $routes->group('admin/billing', ['filter' => ['session', 'whatsapp-fresh']], static function ($routes) {
       $routes->get('stripe-settings', 'Admin\BillingController::index');
       $routes->post('stripe-settings', 'Admin\BillingController::update');
   });
   ```

That's it - a user reaching `admin/billing/stripe-settings` without a
recent-enough WhatsApp challenge gets sent to a short challenge flow
first, then bounced back to where they were headed.

### Three steps, not two - unlike `shield-totp-mfa`/`shield-passkey-mfa`

TOTP and passkey step-up challenges are a single page: show the form,
submit, done - there's nothing to "send" first. WhatsApp needs an
explicit send step, the same way the login action does:
`WhatsAppStepUpController::show()` ("we're about to send a code to the
number ending in...") → `send()` (generates + sends a fresh code,
renders the code-entry form) → `verify()` (checks it, stamps the
step-up session).

This challenges the user's **already-verified** number specifically -
`PhoneNumberStore::beginStepUpChallenge()`/`verifyStepUpChallenge()`
use a dedicated identity type (`ID_TYPE_PHONE_STEP_UP`), deliberately
separate from the phone-verification flow's own type. A step-up
challenge in progress and a "change my number" attempt in progress
don't interfere with each other, even if a user somehow has both open
at once.

### How freshness works

A timestamp is stashed in session the moment a challenge succeeds.
Subsequent requests to any `whatsapp-fresh`-protected route within
`$config->stepUpFreshnessSeconds` (default 15 minutes) pass straight
through without asking again; after that window, the next protected
page reached asks again.

### What happens if the user has no verified number at all

By default, `RequireFreshWhatsApp` lets them through - there's nothing
to challenge them with, so the filter doesn't lock them out of a page
they have no way to unlock. If you'd rather force verification before
such pages are reachable at all, set:

```php
public bool $stepUpRequiresEnrollment = true;
public string $stepUpEnrollRouteName  = 'whatsapp-settings-enroll'; // or
                                        // 'mfa-settings-whatsapp-enroll'
                                        // if using shield-mfa-dispatcher
```

### This is separate machinery from the login Action, deliberately

`RequireFreshWhatsApp`/`WhatsAppStepUpController` don't touch Shield's
`ActionInterface`/pending-login mechanism at all - they're an ordinary
CodeIgniter filter and controller operating on `auth()->user()`. See
`shield-totp-mfa`'s README for the fuller explanation of why step-up
auth is deliberately kept out of the Action system entirely.

### Built with the `redirect()->back()` fix already in place

`WhatsAppStepUpController::verify()`'s error path redirects to the
named `whatsapp-step-up` route, never `back()` - see the confirmed bug
this exact pattern caused in the login action's own `verify()`
(documented earlier in this README) for why. The step-up flow has the
identical shape (a code-entry page rendered by a POST response, no
redirect in between), so it was built with that fix from the start
rather than needing it discovered here separately.

## Tests

**If you're using `shield-mfa-dispatcher` [package]('https://github.com/CloudRepublic-io/shield-mfa-dispatcher')** (or anything else that
makes `Config\Auth::$actions` point at something other than
`WhatsAppMfa`/`WhatsAppActivator` directly): the confirmed fixes
`shield-totp-mfa` needed for this exact same architecture (session
leakage between test methods, `resetServices()`'s own route-wiping
side effect, and reflection-based pending-state simulation for the
registration-time activator once `'login'` points elsewhere) are
applied here too - see `shield-totp-mfa`'s README and its
`TotpMfaTest`/`TotpActivatorTest` class doc comments for the full,
diagnostic-backed account; not repeated here in full since the
mechanism is identical. `WhatsAppMfaTest` and `WhatsAppActivatorTest`
have the complete fix; `RequireFreshWhatsAppTest`,
`WhatsAppStepUpControllerTest`, and `WhatsAppSettingsControllerTest`
have the defensive `Services::routes()->loadRoutes()` piece only,
since they use `actingAs()` rather than `attempt()` and were never
affected by the session/pending-state issues specifically.

`tests/WhatsAppMfa/` covers `WhatsAppMfa` (`handle()`/`verify()`: code
generation and sending via a fake sender, so no real network call ever
happens, plus correct/wrong/empty/expired code handling),
`WhatsAppActivator` (the registration-time counterpart - phone entry,
send, verify, skip), `PhoneNumberStore`, the self-service settings
controller, and the step-up auth filter/controller.

```
tests/WhatsAppMfa/
  Support/FakeWhatsAppSender.php    <- records what would have been sent, no real network call
  Support/TestableWhatsAppMfa.php   <- fixes the phone number for testing, since Shield's
                                        stock User entity has no phone column
  Authentication/Actions/WhatsAppMfaTest.php
  Authentication/Actions/WhatsAppActivatorTest.php <- registration-time counterpart, including
                                                        the $wasAlreadyActive / forced-setup-reuse fix
  Libraries/PhoneNumberStoreTest.php       <- pending-to-permanent record lifecycle, step-up challenges,
                                               and regression tests for the fixed duplicate-key bugs
  Controllers/WhatsAppSettingsControllerTest.php <- enroll/send/verify/confirm/disable
  Filters/RequireFreshWhatsAppTest.php     <- step-up freshness/enrollment logic
  Controllers/WhatsAppStepUpControllerTest.php <- step-up show/send/verify, including a
                                                    regression test for the back()-vs-route() fix
```

### Setup

1. Copy `tests/WhatsAppMfa` into your app's own `tests/` folder, the
   same way `src/` gets installed - see "Installation" above.
2. Make sure your test database has Shield's own migrations applied
   (`users`, `auth_identities`, etc.) - `protected $namespace = null;`
   in the test class triggers this automatically, equivalent to
   `php spark migrate --all`, as long as the connection itself works.
3. Run it the same way as the rest of your suite:

   ```
   vendor/bin/phpunit tests/WhatsAppMfa
   ```

### Why two different testing patterns in this one suite

`WhatsAppMfaTest` and `WhatsAppActivatorTest` (the login and
registration-time actions) use a real `Session::attempt()` call with
real credentials to put the authenticator into a genuinely *pending*
login state - `actingAs()` puts it into a *fully logged in* state
instead, a different thing from what `getPendingUser()` checks for;
this was worked out through several rounds of trial and error,
documented in `shield-totp-mfa`'s own `TotpMfaTest`.
`WhatsAppSettingsControllerTest` uses `actingAs()` instead, because
that controller is for someone *already fully logged in* managing
their own settings (`auth()->user()`, not `getPendingUser()`), which is
exactly the state `actingAs()` produces.

### `WhatsAppActivatorTest` can test something `PasskeyActivatorTest` can't

Both packages' activator classes gained the same fix for
`shield-mfa-dispatcher`'s forced-setup reuse: `verify()` now checks
whether the user was already active before deciding where to redirect
(see `shield-totp-mfa`'s README, "Design decisions worth knowing
about", for the full explanation). `PasskeyActivatorTest` can't
exercise that branch directly - it only runs after a genuinely valid
signed WebAuthn response, which the test suite can't produce (see
`PasskeyIdentityStoreTest`'s class doc comment in that package). This
package's equivalent check *can* be tested directly
(`testVerifyForAnAlreadyActiveUserRedirectsToLoginNotRegistration`),
since WhatsApp's code verification is plain 6-digit matching, not real
cryptography - the same reason `shield-totp-mfa`'s `TotpActivatorTest`
can test it too.

### Why a fake sender and a phone-number override

`FakeWhatsAppSender` replaces whatever's configured in
`Config\WhatsAppMfa::$sender` for the duration of each test, so running
this suite never sends a real WhatsApp message or calls a real
Meta/Twilio API - used by both `WhatsAppMfaTest` and
`WhatsAppSettingsControllerTest`. `TestableWhatsAppMfa` (used only by
`WhatsAppMfaTest`) overrides `resolvePhoneNumber()` to a fixed test
number for the login-action tests specifically - not needed for the
settings controller tests, since those exercise `PhoneNumberStore`
directly rather than going through `resolvePhoneNumber()`'s fallback
chain.

### If you hit "Declaration must be compatible" loading `WhatsAppMfa`

See "If you get 'Declaration must be compatible'..." earlier in this
README - fixed in the current version of `WhatsAppMfa.php`.
