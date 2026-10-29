<?php

declare(strict_types=1);

// Docket's adoption inventory (resources/adoption/surfaces.json, format 1 in surfaces.schema.json), which standards:adoption reads. Every @api
// class is a surface's evidence or a listed non-surface, with no exemptions: a new public class without an entry fails here.

/** @return array<string, mixed> */
function adoptionJson(string $file): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../resources/adoption/'.$file), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function apiClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('~\*\s@api\b~', $source) && preg_match('~^namespace\s+([^;]+);~m', $source, $ns)) {
            $classes[] = $ns[1].'\\'.$file->getBasename('.php');
        }
    }
    sort($classes);

    return $classes;
}

it('is format 1 for this package, with only the schema\'s top-level fields', function () {
    $inventory = adoptionJson('surfaces.json');
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($inventory['format'])->toBe(1)
        ->and($inventory['package']['composer'])->toBe($composer['name'])
        ->and(array_keys($inventory))->toEqualCanonicalizing(array_keys(adoptionJson('surfaces.schema.json')['properties']));
});

it('lists every @api class as surface evidence or a non-surface', function () {
    $inventory = adoptionJson('surfaces.json');
    $listed = [...array_merge(...array_map(fn (array $s): array => $s['uses']['classes'] ?? [], $inventory['surfaces'])), ...array_column($inventory['nonSurfaces'], 'ref')];

    expect(array_values(array_diff(apiClasses(), $listed)))->toBe([]);
    foreach ($listed as $class) {
        expect(class_exists($class) || trait_exists($class) || enum_exists($class))->toBeTrue("{$class} does not exist");
    }
});

it('follows the schema: unique ids, closed groups and signal kinds, known and used facts', function () {
    $inventory = adoptionJson('surfaces.json');
    $schema = adoptionJson('surfaces.schema.json');
    $surface = $schema['$defs']['surface'];
    $kinds = [];
    foreach ($schema['$defs']['signal']['oneOf'] as $kind) {
        $kinds[$kind['properties']['kind']['const']] = $kind;
    }
    $signal = function (array $s, string $where) use ($kinds): void {
        expect(array_key_exists($s['kind'], $kinds))->toBeTrue("{$where}: unknown signal kind");
        $kind = $kinds[$s['kind']];
        expect(array_diff($kind['required'], array_keys($s)))->toBe([], "{$where}: missing fields")
            ->and(array_values(array_diff(array_keys($s), array_keys($kind['properties']))))->toBe([], "{$where}: unknown fields");
    };
    $facts = array_column($inventory['facts'], 'id');
    expect(array_unique($facts))->toBe($facts);
    foreach ($inventory['facts'] as $fact) {
        expect(array_keys($fact['computed']))->toBe(['signals']);
        foreach ($fact['computed']['signals'] as $s) {
            expect($s['kind'])->not->toBe('rule');
            $signal($s, $fact['id']);
        }
    }

    $ids = array_column($inventory['surfaces'], 'id');
    expect(array_unique($ids))->toBe($ids);
    $applied = [];
    foreach ($inventory['surfaces'] as $s) {
        expect(array_diff($surface['required'], array_keys($s)))->toBe([], "{$s['id']}: missing fields")
            ->and(array_values(array_diff(array_keys($s), array_keys($surface['properties']))))->toBe([], "{$s['id']}: unknown fields")
            ->and($surface['properties']['group']['enum'])->toContain($s['group'])
            ->and($surface['properties']['layer']['enum'])->toContain($s['layer'])
            ->and($s['uses'])->not->toBeEmpty()
            ->and(is_file(__DIR__.'/../../'.$s['docs']))->toBeTrue("{$s['id']}: docs path");
        preg_match_all('~[a-z][a-z0-9-]+~', json_encode($s['applies']), $m);
        foreach ($m[0] as $fact) {
            if (! in_array($fact, ['all', 'any', 'not', 'true'], true)) {
                expect($facts)->toContain($fact);
                $applied[] = $fact;
            }
        }
        foreach ($s['handRolled'] as $hr) {
            $signal($hr, $s['id']);
        }
    }
    expect(array_values(array_diff($facts, $applied)))->toBe([], 'every fact is used');
});
