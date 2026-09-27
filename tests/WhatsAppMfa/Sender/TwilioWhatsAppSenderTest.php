<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Sender;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WhatsAppMfa as WhatsAppMfaConfig;
use Tests\WhatsAppMfa\Support\TestableTwilioWhatsAppSender;

// Loaded explicitly rather than autoloaded: a typical CodeIgniter app's
// composer.json only maps Tests\Support\ (to tests/_support), so
// Tests\WhatsAppMfa\Support\* isn't autoloadable, and PHPUnit only
// loads *Test.php files itself.
require_once __DIR__ . '/../Support/TestableTwilioWhatsAppSender.php';

/**
 * Tests TwilioWhatsAppSender::buildFields() directly, in isolation -
 * confirms the channel-dependent To/From/Body logic added for
 * Config\WhatsAppMfa::$channel ('whatsapp' vs 'sms') without making any
 * real network call. send() itself (the cURL call) is NOT covered here
 * - there is no real Twilio account to call in this test environment,
 * and buildFields() is where the logic that actually changed lives.
 */
final class TwilioWhatsAppSenderTest extends CIUnitTestCase
{
    private function makeConfig(): WhatsAppMfaConfig
    {
        $config                        = new WhatsAppMfaConfig();
        $config->twilioSid             = 'ACtest';
        $config->twilioAuthToken       = 'test-token';
        $config->plainMessageTemplate  = 'Your code is %s.';

        return $config;
    }

    public function testWhatsAppChannelPrefixesBothToAndFrom(): void
    {
        $config                   = $this->makeConfig();
        $config->channel          = 'whatsapp';
        $config->twilioFromNumber = '+14155238886'; // deliberately WITHOUT the prefix already on it

        $fields = (new TestableTwilioWhatsAppSender())->exposeBuildFields('+15551234567', '123456', $config);

        $this->assertSame('whatsapp:+15551234567', $fields['To']);
        $this->assertSame('whatsapp:+14155238886', $fields['From']);
    }

    public function testSmsChannelStripsAnyExistingWhatsAppPrefixFromTheSameConfiguredNumber(): void
    {
        $config                   = $this->makeConfig();
        $config->channel          = 'sms';
        $config->twilioFromNumber = 'whatsapp:+14155238886'; // deliberately WITH the prefix already on it

        $fields = (new TestableTwilioWhatsAppSender())->exposeBuildFields('+15551234567', '123456', $config);

        $this->assertSame('+15551234567', $fields['To']);
        $this->assertSame('+14155238886', $fields['From']);
    }

    /**
     * THE regression test for a real, confirmed requirement: SMS has
     * no equivalent to WhatsApp's Content Template mechanism, and
     * Twilio's own SMS API doesn't support ContentSid/ContentVariables
     * at all - a configured twilioContentSid must never leak into an
     * SMS-channel request, even if a developer forgot to clear it
     * after switching channels.
     */
    public function testSmsChannelAlwaysUsesPlainBodyEvenIfAContentSidIsConfigured(): void
    {
        $config                   = $this->makeConfig();
        $config->channel          = 'sms';
        $config->twilioFromNumber = '+14155238886';
        $config->twilioContentSid = 'HXfakeContentSid';

        $fields = (new TestableTwilioWhatsAppSender())->exposeBuildFields('+15551234567', '123456', $config);

        $this->assertArrayNotHasKey('ContentSid', $fields);
        $this->assertArrayNotHasKey('ContentVariables', $fields);
        $this->assertSame('Your code is 123456.', $fields['Body']);
    }

    public function testWhatsAppChannelUsesContentSidWhenConfigured(): void
    {
        $config                   = $this->makeConfig();
        $config->channel          = 'whatsapp';
        $config->twilioFromNumber = '+14155238886';
        $config->twilioContentSid = 'HXfakeContentSid';

        $fields = (new TestableTwilioWhatsAppSender())->exposeBuildFields('+15551234567', '123456', $config);

        $this->assertSame('HXfakeContentSid', $fields['ContentSid']);
        $this->assertArrayNotHasKey('Body', $fields);
    }

    public function testDefaultChannelIsWhatsAppForBackwardCompatibility(): void
    {
        $config = new WhatsAppMfaConfig();

        $this->assertSame('whatsapp', $config->channel);
    }

    // -------------------------------------------------------------------
    // sendViaWhatsAppRegardlessOfChannel() / forceWhatsAppChannel() -
    // the channel-test flow's own forced-WhatsApp sending, independent
    // of Config\WhatsAppMfa::$channel's own current value.
    // -------------------------------------------------------------------

    public function testForceWhatsAppChannelOverridesAnSmsConfiguredChannel(): void
    {
        $config          = $this->makeConfig();
        $config->channel = 'sms';

        $forced = (new TestableTwilioWhatsAppSender())->exposeForceWhatsAppChannel($config);

        $this->assertSame('whatsapp', $forced->channel);
    }

    public function testForceWhatsAppChannelLeavesTheOriginalConfigUntouched(): void
    {
        $config          = $this->makeConfig();
        $config->channel = 'sms';

        (new TestableTwilioWhatsAppSender())->exposeForceWhatsAppChannel($config);

        // THE regression point: forcing the channel for one send must
        // not leak back into the original config object - other,
        // concurrent uses of $config (e.g. a normal login-time send
        // running in the same request) must still see 'sms'.
        $this->assertSame('sms', $config->channel);
    }

    /**
     * Confirms the forced config actually produces WhatsApp-formatted
     * fields when passed through buildFields() - not just that the
     * $channel property itself changed, but that it has the intended
     * downstream effect.
     */
    public function testAConfigForcedToWhatsAppProducesWhatsAppFormattedFields(): void
    {
        $config                   = $this->makeConfig();
        $config->channel          = 'sms';
        $config->twilioFromNumber = '+14155238886';

        $sender = new TestableTwilioWhatsAppSender();
        $forced = $sender->exposeForceWhatsAppChannel($config);
        $fields = $sender->exposeBuildFields('+15551234567', '123456', $forced);

        $this->assertSame('whatsapp:+15551234567', $fields['To']);
        $this->assertSame('whatsapp:+14155238886', $fields['From']);
    }
}
