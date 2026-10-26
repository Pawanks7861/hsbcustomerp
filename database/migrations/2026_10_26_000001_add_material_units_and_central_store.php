<?php

use App\Models\InventoryLocation;
use App\Support\MaterialUnits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('material_units')) {
            Schema::create('material_units', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50);
                $table->string('slug', 50)->unique();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $now = now();
        $names = MaterialUnits::defaults();

        if (Schema::hasTable('materials')) {
            foreach (DB::table('materials')->whereNotNull('unit')->distinct()->pluck('unit') as $unit) {
                $names[] = (string) $unit;
            }
        }

        foreach ($names as $name) {
            $trimmed = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
            if ($trimmed === '') {
                continue;
            }
            $slug = mb_strtolower($trimmed);
            if (DB::table('material_units')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('material_units')->insert([
                'name' => $trimmed,
                'slug' => $slug,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->makeProjectIdNullable('stock_ledger_entries');
        $this->makeProjectIdNullable('stock_adjustments');

        if (Schema::hasTable('inventory_locations') && ! DB::table('inventory_locations')->where('location_code', 'CENTRAL')->exists()) {
            DB::table('inventory_locations')->insert([
                'location_code' => 'CENTRAL',
                'name' => 'Central Store',
                'location_type' => InventoryLocation::CENTRAL_STORE,
                'project_id' => null,
                'is_active' => true,
                'is_default' => false,
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_units');
    }

    private function makeProjectIdNullable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'project_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `project_id` BIGINT UNSIGNED NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('project_id')->nullable()->change();
        });
    }
};
