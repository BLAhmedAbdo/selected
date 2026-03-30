<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Keep going — AIOps detector ready.');
})->purpose('Display an inspiring message');