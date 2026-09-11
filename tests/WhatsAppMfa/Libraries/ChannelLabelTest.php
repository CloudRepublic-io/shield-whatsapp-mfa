<?php

declare(strict_types=1);

namespace Tests\WhatsAppMfa\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use WhatsAppMfa\Libraries\ChannelLabel;

final class ChannelLabelTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore the default so other tests in the suite aren't
        // affected by whichever channel the last test in this file
        // happened to leave configured.
        config('WhatsAppMfa')->channel = 'whatsapp';
    }

    public function testCurrentReturnsTheWhatsAppLabelByDefault(): void
    {
        config('WhatsAppMfa')->channel = 'whatsapp';

        $this->assertSame(lang('WhatsAppMfa.channelLabel_whatsapp'), ChannelLabel::current());
    }

    public function testCurrentReturnsTheSmsLabelWhenConfiguredForSms(): void
    {
        config('WhatsAppMfa')->channel = 'sms';

        $this->assertSame(lang('WhatsAppMfa.channelLabel_sms'), ChannelLabel::current());
    }

    /**
     * THE regression test this class exists to satisfy: switching
     * $channel to 'sms' must actually change what a user-facing string
     * says, not leave it saying "WhatsApp" regardless.
     */
    public function testInjectSubstitutesTheChannelPlaceholder(): void
    {
        config('WhatsAppMfa')->channel = 'sms';

        $result = ChannelLabel::inject('WhatsAppMfa.phoneLabel');

        $this->assertSame(lang('WhatsAppMfa.channelLabel_sms') . ' number', $result);
        $this->assertStringNotContainsString('{channel}', $result);
    }

    public function testInjectLeavesOtherPlaceholdersUntouched(): void
    {
        config('WhatsAppMfa')->channel = 'whatsapp';

        $result = ChannelLabel::inject('WhatsAppMfa.sendIntro');

        // {phone} is still the caller's own responsibility to
        // substitute - inject() only ever touches {channel}.
        $this->assertStringContainsString('{phone}', $result);
    }
}
