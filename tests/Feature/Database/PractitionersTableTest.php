<?php

namespace Tests\Feature\Database;

use App\Models\Practitioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PractitionersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_a_second_practitioner_for_the_same_business(): void
    {
        $practitioner = Practitioner::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"practitioners_business_id_unique"');

        Practitioner::factory()->for($practitioner->business)->create();
    }

    public function test_each_business_can_have_its_own_practitioner(): void
    {
        $first = Practitioner::factory()->create();
        $second = Practitioner::factory()->create();

        $this->assertModelExists($first);
        $this->assertModelExists($second);
    }
}
