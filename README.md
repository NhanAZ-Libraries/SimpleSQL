# SimpleSQL

SimpleSQL is a virion that stores plugin session data in SQL and maintains a YAML mirror for offline inspection and editing.

[![Analyze and validate SimpleSQL virion](https://github.com/NhanAZ-Libraries/SimpleSQL/actions/workflows/validate.yml/badge.svg)](https://github.com/NhanAZ-Libraries/SimpleSQL/actions/workflows/validate.yml)

## How it works

SimpleSQL uses [libasynql](https://github.com/poggit/libasynql) for asynchronous SQLite or MySQL queries. It loads the SQL row and YAML file before opening a session. The copy with the higher revision wins. If an offline YAML edit wins, SimpleSQL waits for the SQL synchronization to succeed before exposing the session. A failed SQL read leaves the session unopened and invokes the optional error callback.

Session changes are held in memory until saved. Saves write to SQL first and then queue a YAML mirror write. Writes for one session run in order, and a change made while a save is running remains dirty until it is saved separately. A failed save keeps the session open. During shutdown, a failed SQL save causes SimpleSQL to write a separate recovery snapshot if the filesystem is available.

## Requirements and compatibility

- Axolotl-PM API `5.0.0` or another compatible API 5 server.
- PHP 8.1 or newer with the extensions required by the server, including YAML.
- [libasynql](https://github.com/poggit/libasynql) bundled as a separate virion.

The `virion.yml` minimum is API `5.0.0`. The [validation workflow](.github/workflows/validate.yml) pins its analysis dependencies and records the exact server source used by PHPStan. This does not establish compatibility with every Axolotl-PM 5.x release. Validate your assembled plugin and server combination before deploying it.

## Installation and build

Add SimpleSQL and libasynql to the virion inputs of your plugin build. Include both SQL resource files from [`resources/simplesql`](resources/simplesql) at the same paths in your plugin. SimpleSQL is a library inside the resulting plugin PHAR, not a standalone server plugin.

Use [DevTools](https://github.com/NhanAZ/DevTools) to build and validate the plugin PHAR. Pin the virion sources and DevTools revision in your build workflow so that the artifact can be reproduced. The [SimpleEconomy build workflow](https://github.com/NhanAZ-Plugins/SimpleEconomy/blob/main/.github/workflows/devtools.yml) shows one consumer configuration, but its pinned revisions should be reviewed before reuse.

The plugin must contain these resources.

```text
resources/simplesql/mysql.sql
resources/simplesql/sqlite.sql
```

Configure a database section in your plugin's config file. For example, SQLite can use the following settings.

```yaml
database:
  type: sqlite
  sqlite:
    file: data.sqlite
  worker-limit: 1
```

Refer to [libasynql](https://github.com/poggit/libasynql) for MySQL configuration and prepared statement requirements.

## Using sessions

Create the manager in `onEnable()` and close it in `onDisable()`. Validate the database configuration before passing it to `create()`.

```php
use NhanAZ\SimpleSQL\Session;
use NhanAZ\SimpleSQL\SimpleSQL;

$database = $this->getConfig()->get("database");
if (!is_array($database)) {
    throw new \RuntimeException("Invalid database configuration");
}

$simpleSQL = SimpleSQL::create($this, $database);
$simpleSQL->openSession(
    "Steve",
    function (Session $session): void {
        $coins = $session->get("coins", 0);
        $session->set("coins", (int) $coins + 100);
        $session->save();
    },
    function (string $reason): void {
        // Report the failure to your plugin's logger or caller.
    },
);

// Call $simpleSQL->close() from your plugin's onDisable().
```

`openSession()` is asynchronous. Read or edit data only after its success callback runs. The error callback is optional, but consumers should provide one so a database outage does not leave an operation waiting silently.

`Session::get()`, `set()`, `remove()`, `has()`, `getAll()` and `setAll()` operate on in-memory data. `save()` accepts an optional callback receiving a success boolean. `closeSession()` saves dirty data before releasing it. Its completion callback runs after a successful close. If SQL cannot save the data, the session stays open for a retry.

## Offline YAML edits and recovery

Stop the server before editing a YAML mirror. Each file has `revision` and `data` fields. Increment `revision` when changing the data so that the offline edit wins over the SQL row at the next load.

```yaml
revision: 6
data:
  coins: 1500
```

If a YAML file cannot be parsed, SimpleSQL renames it with a `.broken` suffix and uses the SQL row if one is available. Check the log and inspect the renamed file before discarding it.

If SQL fails during shutdown, SimpleSQL attempts to write `player.yml.<random>.recovery.yml` alongside the normal mirror. The recovery file is **not loaded automatically**. With the server stopped, inspect the database and recovery file, then restore the desired data to the normal YAML file with a revision higher than the SQL row. Keep a copy of both before making changes. A recovery snapshot also depends on a writable filesystem, so maintain ordinary database and filesystem backups.

## Development

The Composer lockfile pins the server and analysis toolchain for repeatable checks. The CI workflow also pins the libasynql source used during static analysis.

```sh
composer validate --strict
composer install --no-interaction --prefer-dist --no-scripts --no-plugins
composer phpstan
php tests/sql-load-safety.php
php tests/save-lifecycle.php
```

The regression scripts test load failure barriers, ordered saves and shutdown recovery. They do not replace a runtime smoke test of a plugin PHAR containing both virions.

## Support and license

Report defects through [GitHub Issues](https://github.com/NhanAZ-Libraries/SimpleSQL/issues) or [NhanAZ Discord](https://discord.gg/j2X83ujT6c). SimpleSQL is licensed under the [MIT License](LICENSE). libasynql has its own license and must retain its notices when bundled.
