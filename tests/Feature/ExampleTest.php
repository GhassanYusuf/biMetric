<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` used to render the Inertia welcome page. That app is retired, so the root
     * URL now hands off to the SPA shell — a redirect is the correct answer here,
     * not a regression.
     */
    public function test_the_root_url_hands_off_to_the_spa(): void
    {
        $this->get('/')->assertRedirect(route('spa'));
    }

    /** The SPA shell itself is public: authentication happens client-side. */
    public function test_the_spa_shell_is_served(): void
    {
        $this->get('/app/dashboard')->assertStatus(200);
    }
}
