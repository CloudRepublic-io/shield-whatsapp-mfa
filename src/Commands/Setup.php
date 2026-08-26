<?php

declare(strict_types=1);

namespace WhatsAppMfa\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Publisher\Publisher;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Throwable;

/**
 * php spark whatsapp-mfa:setup
 *
 * Publishes Config/WhatsAppMfa.php and Language/en/WhatsAppMfa.php
 * into the host application - the same technique (CodeIgniter's
 * Publisher class) Shield's own `shield:setup` uses, and every other
 * package in this series uses for its own setup command.
 *
 * No migration to handle here - unlike shield-totp-mfa and
 * shield-passkey-mfa, this package stores its one-time codes directly
 * in Shield's own auth_identities table rather than a table of its
 * own, so there's nothing to publish or auto-discover beyond Config
 * and Language.
 */
class Setup extends BaseCommand
{
    protected $group       = 'WhatsAppMfa';
    protected $name        = 'whatsapp-mfa:setup';
    protected $description = 'Publishes WhatsApp MFA config and language file into your app.';
    protected $usage       = 'whatsapp-mfa:setup [--force]';
    protected $options     = [
        '--force' => 'Overwrite files that were already published by a previous run.',
    ];

    public function run(array $params)
    {
        if (! class_exists(UserIdentityModel::class)) {
            CLI::error('CodeIgniter Shield does not appear to be installed.');
            CLI::write('Install it first: composer require codeigniter4/shield');

            return;
        }

        $namespaces = service('autoloader')->getNamespace('WhatsAppMfa');

        if ($namespaces === []) {
            CLI::error('Could not resolve the "WhatsAppMfa" namespace.');
            CLI::write('Make sure it is registered in composer.json or app/Config/Autoload.php.');

            return;
        }

        $force  = (bool) CLI::getOption('force');
        $source = rtrim($namespaces[0], '/\\');

        $publisher = new Publisher($source, APPPATH);

        try {
            $publisher->addPaths(['Config', 'Language'])->merge($force);
        } catch (Throwable $e) {
            CLI::error('Publishing failed: ' . $e->getMessage());
            $this->printPublisherErrors($publisher);

            return;
        }

        $published = $publisher->getPublished();

        if ($published === []) {
            CLI::write('Nothing to publish - files already exist. Re-run with --force to overwrite.', 'yellow');
        } else {
            foreach ($published as $file) {
                CLI::write('  Published: ' . str_replace(APPPATH, 'app/', $file), 'green');
            }
        }

        $this->printPublisherErrors($publisher);
        $this->printRemainingSteps();
    }

    private function printPublisherErrors(Publisher $publisher): void
    {
        foreach ($publisher->getErrors() as $file => $error) {
            CLI::error('  ' . $file . ': ' . $error->getMessage());
        }
    }

    private function printRemainingSteps(): void
    {
        CLI::newLine();
        CLI::write('A few manual steps left:', 'yellow');

        CLI::newLine();
        CLI::write('1) Set your WhatsApp sender credentials in app/Config/WhatsAppMfa.php');
        CLI::write('   or via .env - see that file for exactly what each sender needs.');

        CLI::newLine();
        CLI::write('2) Register the action in app/Config/Auth.php:');
        CLI::write('   public array $actions = [');
        CLI::write("       'login' => \\WhatsAppMfa\\Authentication\\Actions\\WhatsAppMfa::class,");
        CLI::write('   ];');

        CLI::newLine();
        CLI::write('3) Add the settings routes from routes-snippet.php to');
        CLI::write('   app/Config/Routes.php - lets a logged-in user self-service verify a');
        CLI::write('   WhatsApp number from account/whatsapp, rather than requiring your app');
        CLI::write('   to already have a phone number on file for them.');

        CLI::newLine();
        CLI::write('4) If you\'d rather use a phone number your app already stores (instead');
        CLI::write('   of self-service verification), set $phoneNumberField in');
        CLI::write('   app/Config/WhatsAppMfa.php to point at it - see that file\'s doc');
        CLI::write('   comment for how the two sources are prioritized.');
    }
}
