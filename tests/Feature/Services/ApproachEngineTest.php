<?php

use AvocetShores\LaravelRewind\Dto\ApproachPlan;
use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\ApproachEngine;

/**
 * Helper: Create a versions collection from an array of version definitions.
 */
function makeVersionsCollection(array $versions): \Illuminate\Support\Collection
{
    return collect($versions)->map(fn ($v) => RewindVersion::make([
        'version' => $v['version'],
        'is_snapshot' => $v['is_snapshot'],
    ]));
}

beforeEach(function () {
    $this->engine = new ApproachEngine;
});

test('returns ApproachMethod::None if currentVersion == targetVersion', function () {
    $versions = makeVersionsCollection([]);

    $plan = $this->engine->run($versions, 5, 5);

    expect($plan)
        ->toBeInstanceOf(ApproachPlan::class)
        ->and($plan->method)->toBe(ApproachMethod::None)
        ->and($plan->cost)->toBe(0)
        ->and($plan->snapshot)->toBeNull();
});

test('direct approach stepping backward calculates partial-diff cost', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
    ]);

    $plan = $this->engine->run($versions, 5, 2);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot?->version)->toBe(1);
});

/**
 * Tests involving snapshot behind.
 */
test('no snapshot behind exists, so behind approach is not considered', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => false],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 1, 3);

    expect($plan->method)->toBe(ApproachMethod::Direct)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot)->toBeNull();
});

test('snapshot behind exactly equals the target, cost=0, chosen over direct', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
    ]);

    $plan = $this->engine->run($versions, 2, 5);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(5);
});

test('snapshot is cheaper than direct approach', function () {

    $versions = makeVersionsCollection([
        ['version' => 1,  'is_snapshot' => true],
        ['version' => 2,  'is_snapshot' => false],
        ['version' => 3,  'is_snapshot' => false],
        ['version' => 4,  'is_snapshot' => false],
        ['version' => 5,  'is_snapshot' => true],
        ['version' => 6,  'is_snapshot' => false],
        ['version' => 7,  'is_snapshot' => false],
        ['version' => 8,  'is_snapshot' => false],
        ['version' => 9,  'is_snapshot' => false],
        ['version' => 10, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 1, 10);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(6)
        ->and($plan->snapshot?->version)->toBe(5);
});

/**
 * Tests involving snapshot ahead.
 */
test('target version is between two snapshots', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
        ['version' => 6, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 6, 2);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2);
});

test('snapshot ahead exactly equals the target, chosen over direct', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 5, 3);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(3);
});

test('snapshot ahead is cheaper than a direct backward approach', function () {

    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
        ['version' => 6, 'is_snapshot' => false],
        ['version' => 7, 'is_snapshot' => false],
        ['version' => 8, 'is_snapshot' => false],
        ['version' => 9, 'is_snapshot' => false],
        ['version' => 10, 'is_snapshot' => true],
        ['version' => 11, 'is_snapshot' => false],
        ['version' => 12, 'is_snapshot' => false],
        ['version' => 13, 'is_snapshot' => false],
        ['version' => 14, 'is_snapshot' => false],
        ['version' => 15, 'is_snapshot' => false],
        ['version' => 16, 'is_snapshot' => false],
        ['version' => 17, 'is_snapshot' => false],
    ]);

    $plan = (new ApproachEngine)->run($versions, 17, 5);

    expect($plan)->toBeInstanceOf(ApproachPlan::class)
        ->and($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(5)
        ->and($plan->snapshot?->version)->toBe(1);
});

/**
 * Tests for picking the minimal cost among direct, behind, and ahead.
 */
test('picks the snapshot approach if it has strictly lower cost than direct', function () {

    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
        ['version' => 6, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 1, 5);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(5);
});

test('prefers direct if multiple approaches have the same minimal cost', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
    ]);

    $plan = $this->engine->run($versions, 1, 2);

    expect($plan->method)->toBe(ApproachMethod::Direct)
        ->and($plan->cost)->toBe(1);
});

test('picks snapshot ahead if it is strictly cheaper than direct or behind', function () {
    $versions = makeVersionsCollection([
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
        ['version' => 6, 'is_snapshot' => false],
        ['version' => 7, 'is_snapshot' => true],
        ['version' => 8, 'is_snapshot' => true],
    ]);

    $plan = $this->engine->run($versions, 3, 6);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot?->version)->toBe(7);
});
