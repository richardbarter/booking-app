<?php

namespace Tests\Feature\Seeders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_test_user_and_the_sample_business(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('businesses', ['name' => 'Alex Morgan Physio & Sports Massage']);
        $this->assertDatabaseCount('practitioners', 1);
        $this->assertDatabaseCount('services', 3);
    }
}
