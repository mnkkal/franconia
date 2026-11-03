<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'franconia:backup-database {--directory= : Private backup directory outside public_html}';

    protected $description = 'Back up MySQL before deployment without exposing credentials in process arguments';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('This backup command requires MySQL.');

            return self::FAILURE;
        }
        $directory = $this->option('directory') ?: config('store.backup_directory');
        if (! is_string($directory) || $directory === '') {
            $this->error('Set a private backup directory.');

            return self::FAILURE;
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            return self::FAILURE;
        }
        $resolved = realpath($directory);
        if (! $resolved || str_contains(str_replace('\\', '/', $resolved), '/public_html') || str_starts_with($resolved, realpath(public_path()))) {
            $this->error('Backup destination must be outside public_html and the API public directory.');

            return self::FAILURE;
        }
        $configFile = tempnam($resolved, '.mysql-');
        $backup = $resolved.'/franconia-'.now()->format('Y-m-d-His').'-'.Str::random(6).'.sql';
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
        try {
            $db = config('database.connections.mysql');
            file_put_contents($configFile, "[client]\nhost=".$quote($db['host'])."\nport=".(int) $db['port']."\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\n");
            chmod($configFile, 0600);
            $process = new Process([config('store.mysqldump_binary'), '--defaults-extra-file='.$configFile,
                '--single-transaction', '--quick', '--hex-blob', '--result-file='.$backup, $db['database']]);
            $process->setTimeout(300)->run();
            if (! $process->isSuccessful() || ! is_file($backup) || filesize($backup) === 0) {
                $this->error('Database backup failed. No migrations should be run. Check the dump binary and database permissions.');

                return self::FAILURE;
            }
            chmod($backup, 0600);
            $this->info('Database backup created: '.$backup);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Database backup failed; deployment must stop.');

            return self::FAILURE;
        } finally {
            if (is_file($configFile)) {
                file_put_contents($configFile, '');
                unlink($configFile);
            }
        }
    }
}
