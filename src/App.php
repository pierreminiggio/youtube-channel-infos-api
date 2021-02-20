<?php

namespace App;

class App
{
    public function run(string $path, ?string $queryParameters): void
    {
        var_dump($path, $queryParameters);
    }
}
