<?php

namespace MigrationReview\Output;

use MigrationReview\Analysis\MigrationReport;

class JsonRenderer implements Renderer
{
    public function render(array $reports): string
    {
        return json_encode(
            ['migrations' => array_map(fn (MigrationReport $r) => $r->toArray(), $reports)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }
}
