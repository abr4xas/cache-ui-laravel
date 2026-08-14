---
name: cache-store-development
description: Inspect and extend Laravel cache stores — enumerate keys across the file, redis and database drivers, strip cache key prefixes so a listed key can actually be deleted, subclass a Store without breaking its locks, and register a driver through Cache::extend().
---

# Cache Store Development

Verified against Laravel 13.x (13.25). `refreshIfOwned()` and `cache.serializable_classes` are recent additions — confirm they exist in the version you target before relying on them.

## When to use this skill

Reach for it when the task involves any of:

- Listing, searching or deleting cache keys outside `Cache::get()` / `Cache::put()`.
- A key that comes back with an unexpected prefix, or a `Cache::forget()` that reports success without deleting anything.
- Writing a custom `Store`, especially one that changes what gets written to the underlying medium.
- Registering a cache driver with `Cache::extend()`.

## Resolve the store, then ask it

The single rule most of the others fall out of: **resolve the store once, then read what you need off the store object.** Re-deriving the same facts from config by a hardcoded path silently breaks the moment a store is named something other than the driver name.

```php
$baseStore = Cache::store($storeName)->getStore();

$keys = match (true) {
    $baseStore instanceof RedisStore => $this->fromRedis($baseStore),
    // Subclasses land here too, so a custom file store needs no extra branch.
    $baseStore instanceof FileStore => $this->fromFile($baseStore),
    $baseStore instanceof DatabaseStore => $this->fromDatabase($baseStore),
    default => [],
};
```

Dispatch on the resolved class rather than on `config("cache.stores.{$name}.driver")`. A store subclassing `FileStore` then works with no new branch, and the store name stops having to match the driver name.

What each store will tell you:

| Need | Ask the store | Not this |
| --- | --- | --- |
| Cache directory | `FileStore::getDirectory()` | `config('cache.stores.file.path')` |
| Database connection | `DatabaseStore::getConnection()` | `DB::table(...)` on the default connection |
| Key prefix | `$store->getPrefix()` | `config('cache.prefix')` |
| Redis connection | `RedisStore::connection()` | `Redis::connection()` |

`DatabaseStore` has no `getTable()`. That one still comes from config — key it by the store name you resolved, `config("cache.stores.{$storeName}.table")`, never a hardcoded `"database"`.

## Key prefixes

Prefixes are where cache tooling quietly breaks, because the prefix is applied at a layer most code never sees.

**`Repository` applies no prefix at all.** `Repository::itemKey($key)` returns the key untouched; every prefix is the `Store`'s doing. So the value stored in Redis or in the `cache` table is `$prefix.$key`, and `Cache::forget($key)` re-applies that prefix on the way out.

Which stores prefix, in Laravel 13:

| Store | `getPrefix()` |
| --- | --- |
| `FileStore` | `''` — never prefixes; the key is sha1-hashed into the filename |
| `ArrayStore` | `''` |
| `DatabaseStore` | `$this->prefix` — written into the `key` column |
| `RedisStore` | `$this->prefix` |
| `MemcachedStore` | `$this->prefix` |

The prefix itself comes from `CacheManager::getPrefix($config)`, which is `$config['prefix'] ?? config('cache.prefix')`. Laravel's default `cache.prefix` is **not** empty.

**The round trip is the invariant to protect:** every key you hand a user must be a key `Cache::forget()` accepts. Read a prefixed key out of storage, hand it back to `Cache::forget()`, and the prefix gets applied a second time — the delete matches nothing. `DatabaseStore::forget()` returns `true` regardless, so this fails silently. Strip the prefix on read:

```php
use Illuminate\Support\Str;

$key = Str::chopStart($storedKey, $store->getPrefix());
```

`Str::chopStart()` removes the needle only when it really is at the start, and no-ops on an empty needle. Laravel strips its own prefix the same way in `DatabaseStore::forgetManyIfExpired()`.

Reach for it rather than `str_replace()`, which would also strip an occurrence in the middle of a key, or `ltrim()`, which takes a *character list* rather than a prefix and so eats into the real key:

```php
ltrim('myapp_cache_cart', 'myapp_cache_');   // 'rt'   — wrong
ltrim('myapp_cache_apple', 'myapp_cache_');  // 'le'   — wrong
Str::chopStart('myapp_cache_cart', 'myapp_cache_');  // 'cart'
```

The `ltrim` bug hides well: it returns the right answer whenever the real key happens to start with a character absent from the prefix, so `myapp_cache_users` → `users` looks fine and the tests pass.

### Redis stacks two prefixes

`SCAN` and `KEYS` return the fully prefixed key, and there are two prefixes on it: the store's, plus any global prefix the connection applies. Compute both, exactly as `RedisStore::currentTags()` does:

```php
$connection = $store->connection();

$connectionPrefix = match (true) {
    $connection instanceof PhpRedisConnection => $connection->_prefix(''),
    $connection instanceof PredisConnection => (string) ($connection->getOptions()->prefix ?: ''),
    default => '',
};

$prefix = $connectionPrefix.$store->getPrefix();
```

Match the scan on `$prefix.'*'` so it stays inside this store's keyspace instead of walking everything sharing the Redis database.

Terminate the scan on the cursor Redis reports, not on the cursor you started from. phpredis 6.1+ starts the iteration from `null` but reports completion as `0`, so comparing against the starting value never matches and the loop spins forever:

```php
} while (! in_array((string) $cursor, ['0', ''], true));
```

## Subclassing a Store that changes the payload format

If your `Store` writes anything other than what the parent writes — wrapping the value, adding metadata — then **every method that touches the raw payload has to be overridden**, including the ones you will not think of.

`get()`, `put()` and `add()` are obvious. These are the ones that get missed:

- **`refreshIfOwned()`** — reached only through `Cache::lock()->refresh()`. It compares the raw unserialized payload against the lock owner. A wrapped payload never equals the owner, so `refresh()` returns `false` forever, with nothing in the logs.
- **`touch()`** — the parent re-stores `$payload['data']` verbatim. With a wrapping `put()`, that double-wraps the value.
- **`increment()` / `decrement()`** — unwrap before casting. Probing with `isset($data['value'])` misreads a wrapped `null` (`isset` is false for null), and falls through to casting the wrapper array itself. Use `array_key_exists()`.

**`FileStore::lock()` does `new static(...)`**, so your subclass becomes the lock store too, and `FileLock` drives it through `add()`, `get()` and `forget()`. Your payload format has to survive that round trip.

Keep the expiration header exactly 10 characters. `getPayload()` reads it with `substr($contents, 0, 10)`, so the parent pads it — `str_pad((string) $this->expiration($seconds), 10, '0', STR_PAD_LEFT)` — and a subclass that writes the raw integer inherits a latent bug.

## Registering a driver with Cache::extend()

`Cache::extend()` hands you the whole job of building the store, including the parts `CacheManager` would have done for you. Mirror the matching `create*Driver()` method in `Illuminate\Cache\CacheManager` and carry across everything it passes:

```php
Cache::extend('key-aware-file', fn (Application $app, array $config): Repository => Cache::repository(
    new KeyAwareFileStore(
        $app->make(Filesystem::class),
        $config['path'],
        $config['permission'] ?? null,
        // Dropping this silently disables the allowed_classes restriction on
        // unserialize() that cache.serializable_classes is there to enforce.
        $app['config']['cache.serializable_classes'] ?? null,
    )->setLockDirectory($config['lock_path'] ?? null),
    // Without $config the repository loses its store name, so cache events are
    // dispatched without it and the `events` flag is ignored.
    $config,
));
```

Register inside an `$this->app->booting()` callback so the driver exists before any other provider's `boot()` reads from the cache.

## Anti-patterns

- Deriving a store's path, table or connection from `config()` by a hardcoded key. Ask the resolved store.
- Branching on the configured driver string. Branch on the resolved store's class, so subclasses are covered.
- Handing out storage-level keys. Strip the prefix so the key survives the round trip back into `Cache::forget()`.
- Trusting a `true` from a delete. `DatabaseStore::forgetMany()` runs the `delete()` and then `return true` unconditionally, so a miss is indistinguishable from a hit — assert the key is gone rather than reading the return value.

## Verifying the work

Cover the round trip specifically, with a non-empty `cache.prefix` set, since that is the default and the case that breaks:

```php
$listed = $inspector->getAllKeys('database');

expect($inspector->forgetKey($listed[0], 'database'))->toBeTrue()
    ->and(Cache::store('database')->get('user_1_profile'))->toBeNull();
```

Exercise the file driver against real files in a temp directory and the database driver against in-memory SQLite. Mocking the `File` facade or `DB::table()` asserts which methods were called, which stays green through exactly the refactors that break these rules.

## References

- `Illuminate\Cache\CacheManager` — the `create*Driver()` methods are the reference for `Cache::extend()`.
- `Illuminate\Cache\RedisStore::currentTags()` — the canonical two-prefix computation.
- `Illuminate\Cache\FileStore` — the payload format a subclass has to keep.
- [Cache docs](https://laravel.com/docs/13.x/cache), [Adding custom cache drivers](https://laravel.com/docs/13.x/cache#adding-custom-cache-drivers)
