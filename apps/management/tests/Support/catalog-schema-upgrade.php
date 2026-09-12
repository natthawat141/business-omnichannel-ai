<?php

// Synthetic upgrade probe. Requires an explicitly isolated, empty development database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'agent_catalog_upgrade_test'
    || ! str_starts_with((string) config('database.connections.mysql.host'), 'agent-catalog-mysql-')
    || Schema::hasTable('migrations')) {
    throw new RuntimeException('Refusing probe: use a fresh isolated agent catalog test database.');
}

$new = database_path('migrations/2026_09_06_000002_add_category_schema_version.php');
$old = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => $path !== $new));
if (Artisan::call('migrate', ['--path' => $old, '--realpath' => true, '--force' => true]) !== 0) {
    throw new RuntimeException('Legacy schema setup failed.');
}
$definitions = json_encode([['key' => 'bedrooms', 'type' => 'number', 'label_th' => 'ห้องนอน', 'searchable' => true, 'operators' => ['eq']]]);
$categoryId = DB::table('package_categories')->insertGetId([
    'name_th' => 'Synthetic upgrade category', 'slug' => 'synthetic-upgrade', 'attribute_definitions' => $definitions,
]);
$packageId = DB::table('packages')->insertGetId([
    'name_th' => 'Synthetic legacy item', 'category_id' => $categoryId, 'code' => 'UPGRADE-FIXTURE',
    'attributes' => json_encode(['ที่จอดรถ' => '2 คัน']), 'is_published' => false,
]);
$beforeCategory = (array) DB::table('package_categories')->find($categoryId);
$beforePackage = (array) DB::table('packages')->find($packageId);
Artisan::call('migrate', ['--path' => $new, '--realpath' => true, '--pretend' => true, '--force' => true]);
$sql = Artisan::output();
if (! str_contains($sql, 'schema_version') || Schema::hasColumn('package_categories', 'schema_version')) {
    throw new RuntimeException('Dry-run must describe the new column without applying it.');
}
if (Artisan::call('migrate', ['--path' => $new, '--realpath' => true, '--force' => true]) !== 0) {
    throw new RuntimeException('Additive migration failed.');
}
$afterCategory = (array) DB::table('package_categories')->find($categoryId);
if (($afterCategory['schema_version'] ?? null) !== 1) {
    throw new RuntimeException('Existing category was not marked legacy version 1.');
}
unset($afterCategory['schema_version']);
if ($beforeCategory !== $afterCategory || $beforePackage !== (array) DB::table('packages')->find($packageId)) {
    throw new RuntimeException('Upgrade changed legacy data.');
}
echo "PASS: MySQL dry-run, additive upgrade, legacy definitions and package bytes preserved.\n";
