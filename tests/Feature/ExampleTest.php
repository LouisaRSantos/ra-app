<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_home_requires_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
