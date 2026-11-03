<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_backup_refuses_unsupported_database(): void
    {
        config(['database.default' => 'sqlite']);
        $this->artisan('franconia:backup-database')->expectsOutput('This backup command requires MySQL.')->assertFailed();
    }

    public function test_backup_refuses_a_public_destination_before_connecting(): void
    {
        config(['database.default' => 'mysql']);
        $this->artisan('franconia:backup-database', ['--directory' => public_path()])
            ->expectsOutput('Backup destination must be outside public_html and the API public directory.')->assertFailed();
    }
}
