<?php

namespace MigrationReview\Support;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Finds the migration files to review.
 */
class MigrationLocator
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly string $basePath,
    ) {}

    /**
     * Migrations that have not been run against the database yet.
     *
     * If the migrations table does not exist (fresh CI database), every migration counts as pending.
     *
     * @param  list<string>  $paths
     * @return list<MigrationFile>
     */
    public function pending(array $paths): array
    {
        $ran = [];

        try {
            if ($this->migrator->repositoryExists()) {
                $ran = $this->migrator->getRepository()->getRan();
            }
        } catch (Throwable) {
            // No reachable database: treat everything as pending.
        }

        return array_values(array_filter(
            $this->all($paths),
            fn (MigrationFile $file) => ! in_array($file->name, $ran, true),
        ));
    }

    /**
     * Every migration in the given directories.
     *
     * @param  list<string>  $paths
     * @return list<MigrationFile>
     */
    public function all(array $paths): array
    {
        $files = $this->migrator->getMigrationFiles($paths);

        return array_values(array_map(fn (string $path) => MigrationFile::fromPath($path, $this->basePath), $files));
    }

    /**
     * Specific files (or directories) given on the command line.
     *
     * @param  list<string>  $paths
     * @return list<MigrationFile>
     */
    public function explicit(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->absolute($path);

            if (is_dir($absolute)) {
                array_push($files, ...$this->all([$absolute]));
            } elseif (is_file($absolute)) {
                $files[] = MigrationFile::fromPath($absolute, $this->basePath);
            }
        }

        return $files;
    }

    /**
     * Migration directories registered with the migrator, plus the default one.
     *
     * @return list<string>
     */
    public function defaultPaths(): array
    {
        return array_values(array_unique([
            $this->basePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations',
            ...$this->migrator->paths(),
        ]));
    }

    private function absolute(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        if ($isAbsolute) {
            return $path;
        }

        // Prefer the working directory, which is what users type paths relative to.
        $fromCwd = realpath($path);

        return $fromCwd !== false ? $fromCwd : $this->basePath.DIRECTORY_SEPARATOR.$path;
    }
}
