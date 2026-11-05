<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards tenancy for every table, including those later sub-plans add: a
 * table with a business_id must cascade from businesses, and its model must
 * be scoped to the current business.
 */
class TenantTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models whose tables have a business_id but which aren't scoped, each
     * with the reason.
     *
     * @var array<class-string<Model>, string>
     */
    private const array UNSCOPED_MODELS = [];

    /**
     * The tenant tables when this guard was written, so it can't pass by
     * finding none.
     *
     * @var list<string>
     */
    private const array KNOWN_TENANT_TABLES = ['practitioners', 'services', 'working_patterns', 'edited_days', 'time_off', 'customers', 'bookings'];

    public function test_finds_every_known_tenant_table(): void
    {
        $tenantTables = $this->tablesWithABusinessId();

        $this->assertSame([], array_values(array_diff(self::KNOWN_TENANT_TABLES, $tenantTables)));
    }

    public function test_every_table_with_a_business_id_cascades_from_businesses(): void
    {
        $tablesWithoutTheKey = array_values(array_filter(
            $this->tablesWithABusinessId(),
            fn (string $table): bool => ! collect(Schema::getForeignKeys($table))->contains(
                fn (array $foreignKey): bool => $foreignKey['columns'] === ['business_id']
                    && $foreignKey['foreign_table'] === 'businesses'
                    && $foreignKey['foreign_columns'] === ['id']
                    && $foreignKey['on_delete'] === 'cascade',
            ),
        ));

        $this->assertSame([], $tablesWithoutTheKey, 'These tables need a foreign key from business_id to businesses with ON DELETE CASCADE.');
    }

    public function test_every_model_whose_table_has_a_business_id_uses_belongs_to_business(): void
    {
        $tenantTables = $this->tablesWithABusinessId();
        $tenantModels = array_filter(
            $this->appModels(),
            fn (string $model): bool => in_array((new $model)->getTable(), $tenantTables, true),
        );

        $unscopedModels = array_values(array_filter(
            $tenantModels,
            fn (string $model): bool => ! in_array(BelongsToBusiness::class, class_uses_recursive($model), true)
                && ! array_key_exists($model, self::UNSCOPED_MODELS),
        ));

        $this->assertCount(count(self::KNOWN_TENANT_TABLES), $tenantModels);
        $this->assertSame([], $unscopedModels, 'These models need the BelongsToBusiness trait.');
    }

    /**
     * Every table in the schema with a business_id column, from PostgreSQL's
     * catalogue.
     *
     * @return list<string>
     */
    private function tablesWithABusinessId(): array
    {
        return DB::table('information_schema.columns')
            ->whereRaw('table_schema = current_schema()')
            ->where('column_name', 'business_id')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
    }

    /**
     * Every concrete model class in app/Models.
     *
     * @return list<class-string<Model>>
     */
    private function appModels(): array
    {
        return collect(File::allFiles(app_path('Models')))
            ->map(fn ($file): string => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
            ->filter(fn (string $class): bool => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract())
            ->values()
            ->all();
    }
}
