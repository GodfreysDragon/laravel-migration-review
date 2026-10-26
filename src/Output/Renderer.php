<?php

namespace GodfreysDragon\MigrationReview\Output;

use GodfreysDragon\MigrationReview\Analysis\MigrationReport;

interface Renderer
{
    /**
     * @param  list<MigrationReport>  $reports
     */
    public function render(array $reports): string;
}
