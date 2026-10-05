<?php

namespace MigrationReview\Support;

final class MigrationFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $contents,
        public readonly ?string $basePath = null,
    ) {}

    public static function fromPath(string $path, ?string $basePath = null): self
    {
        return new self(
            path: $path,
            name: str_replace('.php', '', basename($path)),
            contents: (string) file_get_contents($path),
            basePath: $basePath,
        );
    }

    /**
     * The path relative to the project root, with forward slashes, as CI annotations expect.
     */
    public function relativePath(): string
    {
        $path = str_replace('\\', '/', $this->path);

        if ($this->basePath !== null) {
            $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

            if (str_starts_with(strtolower($path), strtolower($base))) {
                return substr($path, strlen($base));
            }
        }

        return $path;
    }

    public function hash(): string
    {
        return hash('sha256', $this->contents);
    }
}
