<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * ITERATION-1 FIX: the homepage reads galleries (featured exhibitions),
     * which requires the tables to exist — the old test ran without
     * RefreshDatabase and 500'd once real DB-backed content was added.
     */
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
