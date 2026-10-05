<?php

namespace MigrationReview\Output;

use MigrationReview\Analysis\MigrationReport;

interface Renderer
{
    /**
     * @param  list<MigrationReport>  $reports
     */
    public function render(array $reports): string;
}
