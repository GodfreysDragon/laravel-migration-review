<?php

namespace GodfreysDragon\MigrationReview\Tests;

use GodfreysDragon\MigrationReview\MigrationReviewServiceProvider;
use GodfreysDragon\MigrationReview\Support\MigrationFile;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MigrationReviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('migration-review.ai.api_key', null);
        $app['config']->set('cache.default', 'array');
    }

    public static function fixturePath(string $name = ''): string
    {
        return __DIR__.'/fixtures/migrations'.($name === '' ? '' : '/'.$name);
    }

    public static function fixture(string $name): MigrationFile
    {
        return MigrationFile::fromPath(self::fixturePath($name), dirname(__DIR__));
    }

    /**
     * Wrap a snippet of up() code in a migration class.
     */
    public static function migration(string $up, ?string $down = 'Schema::dropIfExists(\'x\');'): MigrationFile
    {
        $downMethod = $down === null ? '' : "\n    public function down(): void\n    {\n        {$down}\n    }\n";

        $contents = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        {$up}
    }
{$downMethod}};
PHP;

        return new MigrationFile('/app/database/migrations/2026_02_01_000000_test.php', '2026_02_01_000000_test', $contents, '/app');
    }
}
