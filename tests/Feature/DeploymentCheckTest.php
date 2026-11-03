<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_environment_is_rejected_without_printing_secrets(): void
    {
        config(['services.razorpay.key_secret' => '', 'store.business_address' => '', 'store.grievance_officer' => 'Vinita Pise']);
        $this->artisan('franconia:check-deploy', ['--skip-scheduler' => true])
            ->expectsOutput('FAIL Production environment')
            ->expectsOutput('FAIL Business postal address')
            ->expectsOutput('PASS Grievance officer name')
            ->expectsOutput('FAIL Payment key and secret')
            ->assertFailed();
    }
}
