<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_the_api_health_endpoint_returns_json(): void
    {
        $this->getJson(route('api.health'))->assertOk()->assertExactJson(['status' => 'ok']);
    }
}
