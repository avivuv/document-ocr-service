<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ExampleTest extends TestCase
{
    public function test_akar_aplikasi_mengarahkan_ke_playground(): void
    {
        $this->get('/')->assertRedirect('/playground');
    }
}
