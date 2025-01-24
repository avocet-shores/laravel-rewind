<?php

use AvocetShores\LaravelRewind\Dto\ApproachPlan;
use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\ApproachEngine;
use Illuminate\Database\Eloquent\Model;

/**
 * Helper: Create a mock Model with a "versions" collection.
 * The $versions array should be an array of associative arrays:
 *   [
 *     ['version' => 1, 'is_snapshot' => true],
 *     ['version' => 2, 'is_snapshot' => false],
 *     ...
 *   ]
 * Pass $relationIsLoaded=false to ensure that ApproachEngine attempts to call ->load('versions').
 */
function makeMockModel(array $versions, bool $relationIsLoaded = true): Model
{
    // We will return a mock of Illuminate\Database\Eloquent\Model
    // that pretends the "versions" relationship is a Collection.
    $model = Mockery::mock(Model::class);

    $model->shouldReceive('load')
        ->with('versions')
        ->andReturn($model);

    // Convert each element of $versions into a RewindVersion so that
    // ->where(...) calls on the collection still work properly.
    $versionsCollection = collect($versions)->map(fn ($v) => RewindVersion::make([
        'version' => $v['version'],
        'is_snapshot' => $v['is_snapshot'],
    ]));

    // The engine calls $model->versions->where(...). We fake this by
    // returning the Collection whenever "versions" attribute is accessed.
    // By default, Laravel’s $model->versions is effectively $model->getAttribute('versions').
    $model->shouldReceive('getAttribute')
        ->with('versions')
        ->andReturn($versionsCollection)
        ->byDefault();

    return $model;
}

beforeEach(function () {
    $this->engine = new ApproachEngine;
});

test('returns ApproachMethod::None if currentVersion == targetVersion', function () {
    $model = makeMockModel([]);

    $plan = $this->engine->run($model, 5, 5);

    expect($plan)
        ->toBeInstanceOf(ApproachPlan::class)
        ->and($plan->method)->toBe(ApproachMethod::None)
        ->and($plan->cost)->toBe(0)
        ->and($plan->snapshot)->toBeNull();
});

test('direct approach stepping backward calculates partial-diff cost', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 5, 2);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot?->version)->toBe(1);
});

/**
 * Tests involving snapshot behind.
 */
test('no snapshot behind exists, so behind approach is not considered', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => false],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 1, 3);

    expect($plan->method)->toBe(ApproachMethod::Direct)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot)->toBeNull();
});

test('snapshot behind exactly equals the target, cost=0, chosen over direct', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 2, 5);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(5);
});

test('snapshot is cheaper than direct approach', function () {

    $versions = [
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
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 1, 10);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(6)
        ->and($plan->snapshot?->version)->toBe(5);
});

/**
 * Tests involving snapshot ahead.
 */
test('target version is between two snapshots', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
        ['version' => 6, 'is_snapshot' => false],
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 6, 2);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2);
});

test('snapshot ahead exactly equals the target, chosen over direct', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
    ];
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 5, 3);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(3);
});

test('snapshot ahead is cheaper than a direct backward approach', function () {

    $versions = [
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
    ];

    $model = makeMockModel($versions);

    $plan = (new ApproachEngine)->run($model, 17, 5);

    expect($plan)->toBeInstanceOf(ApproachPlan::class)
        ->and($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(5)
        ->and($plan->snapshot?->version)->toBe(1);
});

/**
 * Tests for picking the minimal cost among direct, behind, and ahead.
 */
test('picks the snapshot approach if it has strictly lower cost than direct', function () {

    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => true],
        ['version' => 6, 'is_snapshot' => false],
    ];

    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 1, 5);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(1)
        ->and($plan->snapshot?->version)->toBe(5);
});

test('prefers direct if multiple approaches have the same minimal cost', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => false],
    ];
    // Now direct cost=1 => behind cost=1 => ahead cost=1 => all tie => direct approach is first in the stable sort => ApproachMethod::Direct wins.
    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 1, 2);

    expect($plan->method)->toBe(ApproachMethod::Direct)
        ->and($plan->cost)->toBe(1);
});

test('picks snapshot ahead if it is strictly cheaper than direct or behind', function () {
    $versions = [
        ['version' => 1, 'is_snapshot' => true],
        ['version' => 2, 'is_snapshot' => false],
        ['version' => 3, 'is_snapshot' => true],
        ['version' => 4, 'is_snapshot' => false],
        ['version' => 5, 'is_snapshot' => false],
        ['version' => 6, 'is_snapshot' => false],
        ['version' => 7, 'is_snapshot' => true],
        ['version' => 8, 'is_snapshot' => true],
    ];

    $model = makeMockModel($versions);

    $plan = $this->engine->run($model, 3, 6);

    expect($plan->method)->toBe(ApproachMethod::From_Snapshot)
        ->and($plan->cost)->toBe(2)
        ->and($plan->snapshot?->version)->toBe(7);
});

afterEach(function () {
    // Close mockery to verify expectations
    Mockery::close();
});
