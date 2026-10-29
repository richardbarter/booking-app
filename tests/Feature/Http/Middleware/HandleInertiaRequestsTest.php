<?php

namespace Tests\Feature\Http\Middleware;

use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase
{
    public function test_pages_offer_sign_up_while_registration_is_enabled(): void
    {
        config(['fortify.features' => [Features::registration(), Features::resetPasswords()]]);

        $response = $this->get(route('home'));

        $response->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
    }

    public function test_pages_hide_sign_up_when_registration_is_disabled(): void
    {
        config(['fortify.features' => [Features::resetPasswords()]]);

        $response = $this->get(route('home'));

        $response->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    }
}
