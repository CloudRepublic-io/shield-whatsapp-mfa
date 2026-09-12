<?php

declare(strict_types=1);

namespace WhatsAppMfa\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Models\UserModel;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * php spark whatsapp-mfa:channel-status
 *
 * Lists every user with a verified phone number on file, and whether
 * WhatsApp delivery has been confirmed for it specifically - a
 * developer-facing readiness check for migrating
 * Config\WhatsAppMfa::$channel from 'sms' to 'whatsapp' safely (see
 * this package's own README, "Migrating an existing user base from
 * SMS to WhatsApp (or back) safely", and PhoneNumberStore's own doc
 * comment, for the full account of why this exists and how the
 * self-service "test WhatsApp delivery" flow populates the data this
 * command reads).
 *
 * Read-only - this command never sends anything or changes any
 * record. It exists purely to answer "how many of my users are
 * actually ready for this switch" without needing to query the
 * Settings table by hand.
 */
class ChannelStatus extends BaseCommand
{
    protected $group       = 'WhatsAppMfa';
    protected $name        = 'whatsapp-mfa:channel-status';
    protected $description = 'Lists users with a verified phone number and whether WhatsApp delivery is confirmed for it.';
    protected $usage       = 'whatsapp-mfa:channel-status [--unconfirmed-only]';
    protected $options     = [
        '--unconfirmed-only' => 'Only list users who have NOT yet confirmed WhatsApp delivery.',
    ];

    public function run(array $params)
    {
        $store    = new PhoneNumberStore();
        $statuses = $store->listVerificationStatuses();

        if ($statuses === []) {
            CLI::write('No users with a verified phone number on file.', 'yellow');

            return;
        }

        $unconfirmedOnly = (bool) CLI::getOption('unconfirmed-only');
        $users           = model(UserModel::class);

        $confirmedCount   = 0;
        $unconfirmedCount = 0;
        $rows             = [];

        foreach ($statuses as $status) {
            if ($status['whatsapp_confirmed']) {
                $confirmedCount++;
            } else {
                $unconfirmedCount++;
            }

            if ($unconfirmedOnly && $status['whatsapp_confirmed']) {
                continue;
            }

            $user = $users->find($status['user_id']);

            $rows[] = [
                (string) $status['user_id'],
                $user !== null ? (string) ($user->email ?? $user->username ?? '') : '(user not found)',
                $status['verified_number'],
                $status['whatsapp_confirmed'] ? 'confirmed' : 'not confirmed',
            ];
        }

        CLI::table($rows, ['User ID', 'Email/Username', 'Verified Number', 'WhatsApp Delivery']);

        CLI::newLine();
        CLI::write(
            sprintf(
                '%d confirmed, %d not yet confirmed, %d total.',
                $confirmedCount,
                $unconfirmedCount,
                $confirmedCount + $unconfirmedCount
            ),
            $unconfirmedCount === 0 ? 'green' : 'yellow'
        );

        if ($unconfirmedCount > 0) {
            CLI::newLine();
            CLI::write('Users listed as "not confirmed" should visit account/whatsapp and use', 'yellow');
            CLI::write('"Test WhatsApp delivery" before you switch $channel to \'whatsapp\' -', 'yellow');
            CLI::write('otherwise their next login could silently fail to receive a code.', 'yellow');
        }
    }
}
