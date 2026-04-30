<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credentials\CredentialStore;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class ArgosInitCommand extends Command
{
    protected $signature = 'argos:init';

    protected $description = 'Argos Setup-Wizard: Claude-Token und optionale DB-Verbindung konfigurieren';

    public function handle(CredentialStore $credentials): int
    {
        $this->line('');
        info('Argos Setup-Wizard');
        $this->line('');

        // --- Claude Token ---
        $existingToken = $credentials->getClaudeToken();

        $saveToken = true;

        if ($existingToken !== null) {
            $saveToken = confirm('Claude OAuth Token ist bereits gesetzt. Überschreiben?', default: false);
        }

        if ($saveToken) {
            $token = password('Claude OAuth Token:');

            if ($token !== '') {
                $credentials->saveClaudeToken($token);
                info('Claude Token gespeichert.');
            }
        }

        $this->line('');

        // --- DB-Konfiguration ---
        $configureDb = confirm('MariaDB-Verbindung konfigurieren?', default: false);

        if ($configureDb) {
            $host = text('Host', default: '127.0.0.1');
            $port = text('Port', default: '3306');
            $database = text('Database', default: 'argos');
            $username = text('Username', default: 'argos');
            $dbPassword = password('Password');

            $credentials->saveDbConfig([
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_DATABASE' => $database,
                'DB_USERNAME' => $username,
                'DB_PASSWORD' => $dbPassword,
            ]);

            info('Datenbank-Konfiguration gespeichert.');
        }

        $this->line('');
        info('Argos initialisiert. Starte mit: php artisan argos');

        return self::SUCCESS;
    }
}
