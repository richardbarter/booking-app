<?php

namespace Tests\Feature\Database;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomersTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function emailsNotInStoredForm(): array
    {
        return [
            'with capitals' => ['Sam@Example.com'],
            'with a space before' => [' sam@example.com'],
            'with a space after' => ['sam@example.com '],
            'empty' => [''],
        ];
    }

    #[DataProvider('emailsNotInStoredForm')]
    public function test_rejects_an_email_that_is_not_trimmed_and_lower_case_or_is_empty(string $email): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"customers_email_check"');

        Customer::factory()->create(['email' => $email]);
    }

    public function test_rejects_a_second_customer_with_the_same_email_in_one_business(): void
    {
        $business = Business::factory()->create();
        Customer::factory()->for($business)->create(['email' => 'sam@example.com']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"customers_business_id_email_unique"');

        Customer::factory()->for($business)->create(['email' => 'sam@example.com']);
    }

    public function test_allows_the_same_email_in_two_businesses(): void
    {
        Customer::factory()->create(['email' => 'sam@example.com']);

        $other = Customer::factory()->create(['email' => 'sam@example.com']);

        $this->assertModelExists($other);
    }
}
