<?php

$root = dirname(__DIR__);
$index = file_get_contents($root.'/llms.txt');

if ($index === false || ! preg_match_all('/^- \[[^\]]+\]\(([^)]+)\)/m', $index, $matches)) {
    fwrite(STDERR, "llms.txt must index local documents.\n");
    exit(1);
}

$missing = false;

foreach ($matches[1] as $target) {
    if (! is_file($root.'/'.$target)) {
        fwrite(STDERR, "Missing llms.txt target: {$target}\n");
        $missing = true;
    }
}

if ($missing) {
    exit(1);
}

echo 'Verified '.count($matches[1])." llms.txt targets.\n";
