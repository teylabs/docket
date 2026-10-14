<?php

it('declares no static properties on runtime classes or traits', function () {
    $source = dirname(__DIR__, 2).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($source) + 1, -4);
        $class = 'Tey\\Docket\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            // Laravel's inherited model/facade caches are not Docket runtime state.
            expect($property->getDeclaringClass()->getName())->not->toStartWith('Tey\\Docket\\');
        }
    }
});
