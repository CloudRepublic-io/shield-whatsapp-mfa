<?php

declare(strict_types=1);

return [
    'heading'          => 'Verify it\'s you',
    'sendIntro'         => 'For your security, we need to send a one-time code to your WhatsApp number ending in {phone}.',
    'sendButton'        => 'Send code via WhatsApp',
    'verifyIntro'       => 'Enter the 6-digit code we sent to your WhatsApp number ending in {phone}.',
    'codeLabel'         => 'Verification code',
    'verifyButton'      => 'Verify',
    'resendButton'      => 'Resend code',
    'resendWait'        => 'You can request a new code in {seconds}s.',
    'enterCode'         => 'Please enter the code we sent you.',
    'invalidCode'       => 'That code is incorrect. Please try again.',
    'codeExpired'       => 'That code has expired. Please request a new one.',
    'noPendingCode'     => 'No pending verification found. Please log in again.',
    'successMessage'    => 'Signed in successfully.',

    // Self-service phone number settings
    'settingsHeading'      => 'WhatsApp verification',
    'settingsIntro'        => 'Verify a WhatsApp number so you can sign in with a code sent there instead of (or alongside) other methods.',
    'currentPhoneLabel'    => 'Verified number',
    'noPhoneSet'           => 'You haven\'t verified a WhatsApp number yet.',
    'addButton'            => 'Add a WhatsApp number',
    'changeButton'         => 'Change number',
    'removeButton'         => 'Remove',
    'removeConfirm'        => 'Remove this number? You will no longer be able to sign in with a WhatsApp code until you verify a new one.',
    'enrollHeading'        => 'Verify a WhatsApp number',
    'enrollIntro'          => 'Enter a WhatsApp number and we\'ll send a code to confirm you control it.',
    'phoneLabel'           => 'WhatsApp number',
    'phonePlaceholder'     => 'e.g. +14155551234',
    'sendCodeButton'       => 'Send code',
    'invalidPhoneNumber'   => 'Please enter a valid phone number.',
    'confirmVerifyIntro'   => 'Enter the 6-digit code we sent to the number ending in {phone}.',
    'phoneVerifiedMessage' => 'WhatsApp number verified.',
    'phoneRemovedMessage'  => 'WhatsApp number removed.',

    // Registration-time activator
    'skipButton' => 'Skip for now',

    // Step-up auth for sensitive pages
    'stepUpHeading'         => 'Confirm it\'s you',
    'stepUpIntro'           => 'This action requires confirming your identity. We\'ll send a code to your WhatsApp number ending in {phone}.',
    'stepUpNeedsEnrollment' => 'This action requires a verified WhatsApp number. Please verify one first.',
    'stepUpFailed'          => 'That code is incorrect. Please try again.',
];
