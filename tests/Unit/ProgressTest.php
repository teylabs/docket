<?php

use Tey\Docket\Progress;

it('formats attempted progress, floors and bounds the percentage, and preserves unknown totals', function (int $done, ?int $total, ?int $percent, string $text) {
    $progress = new Progress($done, $total);

    expect($progress->done)->toBe($done)
        ->and($progress->total)->toBe($total)
        ->and($progress->percent)->toBe($percent)
        ->and($progress->toArray())->toBe(['done' => $done, 'total' => $total, 'percent' => $percent])
        ->and(json_decode(json_encode($progress), true))->toBe($progress->toArray())
        ->and((string) $progress)->toBe($text);
})->with([
    [620, 1000, 62, '620 of 1,000 (62%)'],
    [620, null, null, '620 done'],
    [340, 1200, 28, '340 of 1,200 (28%)'],
    [29, 100, 29, '29 of 100 (29%)'],
    [0, 5, 0, '0 of 5 (0%)'],
    [5, 5, 100, '5 of 5 (100%)'],
    [6, 5, 100, '6 of 5 (100%)'],
    [0, 0, 100, '0 of 0 (100%)'],
]);
