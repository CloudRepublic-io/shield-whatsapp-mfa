<?php

declare(strict_types=1);

return [
    'heading'          => 'Verify it\'s you',
    'sendIntro'         => 'For your security, we need to send a one-time code to your {channel} number ending in {phone}.',
    'sendButton'        => 'Send code via {channel}',
    'verifyIntro'       => 'Enter the 6-digit code we sent to your {channel} number ending in {phone}.',
    'codeLabel'         => 'Verification code',
    'verifyButton'      => 'Verify',
    'resendButton'      => 'Resend code',
    'resendWait'        => 'You can request a new code in {seconds}s.',
    'enterCode'         => 'Please enter the code we sent you.',
    'invalidCode'       => 'That code is incorrect. Please try again.',
    'codeExpired'       => 'That code has expired. Please request a new one.',
    'noPendingCode'     => 'No pending verification found. Please log in again.',
    'successMessage'    => 'Signed in successfully.',

    // The current Config\WhatsAppMfa::$channel's own display label -
    // see WhatsAppMfa\Libraries\ChannelLabel, which substitutes one of
    // these into every {channel} placeholder above and below.
    'channelLabel_whatsapp' => 'WhatsApp',
    'channelLabel_sms'      => 'SMS',

    // Self-service phone number settings
    'settingsHeading'      => '{channel} verification',
    'settingsIntro'        => 'Verify a {channel} number so you can sign in with a code sent there instead of (or alongside) other methods.',
    'currentPhoneLabel'    => 'Verified number',
    'noPhoneSet'           => 'You haven\'t verified a {channel} number yet.',
    'addButton'            => 'Add a {channel} number',
    'changeButton'         => 'Change number',
    'removeButton'         => 'Remove',
    'removeConfirm'        => 'Remove this number? You will no longer be able to sign in with a {channel} code until you verify a new one.',
    'enrollHeading'        => 'Verify a {channel} number',
    'enrollIntro'          => 'Enter a {channel} number and we\'ll send a code to confirm you control it.',
    'phoneLabel'           => '{channel} number',
    'phonePlaceholder'     => 'e.g. +14155551234',
    'sendCodeButton'       => 'Send code',
    'invalidPhoneNumber'   => 'Please enter a valid phone number.',
    'confirmVerifyIntro'   => 'Enter the 6-digit code we sent to the number ending in {phone}.',
    'phoneVerifiedMessage' => '{channel} number verified.',
    'phoneRemovedMessage'  => '{channel} number removed.',

    // Registration-time activator
    'skipButton' => 'Skip for now',

    // Step-up auth for sensitive pages
    'stepUpHeading'         => 'Confirm it\'s you',
    'stepUpIntro'           => 'This action requires confirming your identity. We\'ll send a code to your {channel} number ending in {phone}.',
    'stepUpNeedsEnrollment' => 'This action requires a verified {channel} number. Please verify one first.',
    'stepUpFailed'          => 'That code is incorrect. Please try again.',
];
