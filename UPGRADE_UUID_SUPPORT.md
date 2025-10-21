# UUID/ULID Support - Upgrade Guide & Gotchas

## Overview
Version X.X.X adds support for UUID and ULID primary keys by changing the `model_id` column from `unsignedBigInteger` to `string(36)`.

## Potential Issues & Solutions

### 1. BREAKING CHANGE: Existing Installations ⚠️

**Problem:** Users with existing `rewind_versions` data will have a column type mismatch.

**Solution:** Provide an upgrade migration for existing users.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('rewind.table_name', 'rewind_versions');
        $connection = config('rewind.database_connection');

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            // Convert model_id from unsignedBigInteger to string(36)
            // Existing integer IDs will be cast to strings automatically
            $table->string('model_id', 36)->change();
        });
    }

    public function down(): void
    {
        $tableName = config('rewind.table_name', 'rewind_versions');
        $connection = config('rewind.database_connection');

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            // Note: This will fail if you have UUID/ULID values
            $table->unsignedBigInteger('model_id')->change();
        });
    }
};
```

**Migration Instructions:**
```bash
# Existing users need to run:
php artisan make:migration upgrade_rewind_versions_for_uuid_support
# Copy the migration code above
php artisan migrate
```

### 2. Index Performance Considerations

**Issue:** String indexes are typically slower than integer indexes for comparison operations.

**Analysis:**
- UUID: 36 characters (128-bit value in string format: "550e8400-e29b-41d4-a716-446655440000")
- ULID: 26 characters (128-bit value in base32: "01ARZ3NDEKTSV4RRFFQ69G5FAV")
- Integer (bigint): Up to 20 characters when stringified

**Impact:**
- Minimal impact due to the composite index on `['model_type', 'model_id']`
- The `model_type` filter narrows the search space significantly
- Modern databases handle string indexes efficiently with proper indexing

**Recommendation:** Monitor query performance in production. If needed, consider:
- Adding a hash index for very large datasets
- Using database-native UUID types (PostgreSQL UUID, MySQL BINARY(16)) in a future version

### 3. Storage Size Increase

**Before:**
- `unsignedBigInteger`: 8 bytes

**After:**
- Integer IDs (as string): ~1-20 bytes + overhead
- UUID: 36 bytes + overhead
- ULID: 26 bytes + overhead

**Estimated Impact:**
For 1 million version records:
- Integer IDs: ~8 MB → ~10-20 MB
- UUID IDs: ~36 MB
- ULID IDs: ~26 MB

This is generally acceptable for most applications, but should be considered for very high-volume systems.

### 4. Database-Specific Considerations

#### PostgreSQL
- Could use native `uuid` column type for better performance
- `string(36)` works but uses more storage than native UUID (16 bytes)

#### MySQL
- Could use `BINARY(16)` with `uuid_to_bin()` for better performance
- `string(36)` is compatible and easier to read in queries

#### SQLite
- No native UUID type, string storage is standard approach
- `string(36)` is the correct choice

**Decision:** We chose `string(36)` for cross-database compatibility and readability. Power users can customize the migration for their specific database if needed.

### 5. Very Large Integer IDs

**Question:** What if someone has integer IDs larger than normal?

**Answer:**
- Standard `bigint`: Max value is 9,223,372,036,854,775,807 (19 digits)
- `string(36)` can hold up to 36 characters
- ✅ No issue - string(36) is more than sufficient

### 6. Eloquent Polymorphic Relationship Handling

**Concern:** Does Laravel's `morphTo`/`morphMany` work with mixed ID types?

**Answer:** ✅ Yes, Laravel handles this correctly:
```php
// In RewindVersion model
public function model(): MorphTo
{
    return $this->morphTo(); // Works with any ID type
}

// In Rewindable trait
public function versions(): MorphMany
{
    return $this->morphMany(RewindVersion::class, 'model'); // Works with any ID type
}
```

Laravel uses `$model->getKey()` internally, which returns the correct type.
The database stores everything as strings, and Laravel casts appropriately.

### 7. Query Comparison Edge Cases

**Potential Issue:** String vs integer comparison in raw queries

**Current Code Review:**
✅ All queries use Eloquent relationships (no raw WHERE clauses on model_id)
✅ Polymorphic relationships handle type conversion automatically
✅ No direct comparisons found that would cause issues

**Example of what would be problematic (not found in codebase):**
```php
// ❌ BAD - Not in our codebase
RewindVersion::where('model_id', '>', 100)->get(); // String comparison would fail

// ✅ GOOD - How we actually query
$model->versions()->where(...); // Uses relationship
```

### 8. JSON Serialization

**Consideration:** model_id in API responses

**Impact:** None - IDs are already serialized as strings in JSON for large integers
```json
{
  "model_id": "550e8400-e29b-41d4-a716-446655440000",  // UUID
  "model_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",           // ULID
  "model_id": "12345"                                  // Integer (as string)
}
```

## Testing Recommendations

### Before Deploying
1. ✅ Run full test suite including new UUID/ULID tests
2. ✅ Test with existing integer ID models (backward compatibility)
3. ⚠️ Create performance benchmarks for your dataset size
4. ⚠️ Test the upgrade migration with a copy of production data

### Performance Testing
```php
// Benchmark version lookups
$start = microtime(true);
$product->versions()->where('version', 5)->first();
$duration = microtime(true) - $start;
```

## Backward Compatibility

✅ **Fully compatible** - Existing models with integer IDs continue to work:
- Integer IDs are stored as strings (e.g., "1", "2", "123")
- Eloquent relationships handle conversion automatically
- All existing functionality preserved

## Recommended Rollout Strategy

### For Package Maintainers
1. ✅ Tag this as a minor version bump (not patch) due to migration requirement
2. ⚠️ Document the upgrade migration prominently in release notes
3. ⚠️ Consider creating an artisan command to automate the upgrade:
   ```bash
   php artisan rewind:upgrade-for-uuid
   ```

### For Package Users
1. Backup your database before upgrading
2. Update the package
3. Run the upgrade migration (provided in release notes)
4. Test thoroughly in staging before production
5. Monitor performance after deployment

## Conclusion

The change from `unsignedBigInteger` to `string(36)` is a sound architectural decision that:

✅ Enables UUID/ULID support
✅ Maintains backward compatibility with integer IDs
✅ Uses Laravel's built-in polymorphic relationship handling
✅ Requires minimal code changes (just the schema)

⚠️ Requires an upgrade migration for existing users
⚠️ Slight storage increase (acceptable for most use cases)
⚠️ Minor performance considerations (unlikely to be noticeable)

The benefits of supporting modern ID strategies (UUID/ULID) outweigh the minor storage and potential performance trade-offs.
