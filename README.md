# SimpleSQL

**Hybrid SQL-YAML data management for PocketMine-MP plugins.**

SimpleSQL combines the **performance and reliability of SQL** with the **human-friendly editability of YAML** files. During runtime, SQL (via [libasynql](https://github.com/poggit/libasynql)) is the authoritative source of truth. YAML files are maintained as a synchronized mirror that server owners can read and edit while the server is offline.

---

## Table of Contents

- [Why SimpleSQL?](#why-simplesql)
- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Developer Guide](#developer-guide)
  - [Setup](#setup)
  - [Opening a Session](#opening-a-session)
  - [Reading & Writing Data](#reading--writing-data)
  - [Saving & Closing](#saving--closing)
  - [Graceful Shutdown](#graceful-shutdown)
- [Server Owner's Guide - Editing YAML Files](#server-owners-guide--editing-yaml-files)
- [Performance Tips](#performance-tips)
- [API Reference](#api-reference)
- [License](#license)

---

## Why SimpleSQL?

| Feature | Raw SQL | Raw YAML | **SimpleSQL** |
|---|---|---|---|
| Performance at scale | ✅ Fast queries | ❌ Full file I/O | ✅ SQL at runtime |
| Human-editable data | ❌ Need DB tools | ✅ Text editor | ✅ YAML mirror |
| Async (no lag) | ✅ libasynql | ❌ Main thread | ✅ Both async |
| Crash recovery | ✅ ACID | ❌ Corruption risk | ✅ SQL source of truth |
| Offline editing | ❌ DB must be up | ✅ Edit anytime | ✅ Revision-based sync |

**The best of both worlds.** Your plugin gets SQL performance during gameplay, and your server owners get YAML files they can edit with Notepad.

---

## How It Works

```
┌─────────────┐     openSession()     ┌──────────────┐
│  Your Plugin │ ──────────────────▶  │   SimpleSQL   │
└─────────────┘                       └──────┬───────┘
                                             │
                              ┌──────────────┼──────────────┐
                              ▼              │              ▼
                        ┌──────────┐         │       ┌────────────┐
                        │ SQL Load │         │       │ YAML Load  │
                        │ (async)  │         │       │ (AsyncTask)│
                        └────┬─────┘         │       └─────┬──────┘
                             │               │             │
                             └───────┬───────┘─────────────┘
                                     ▼
                            ┌─────────────────┐
                            │ Conflict Resolve │
                            │ (higher revision │
                            │  wins)           │
                            └────────┬────────┘
                                     ▼
                              ┌────────────┐
                              │  Session    │ ◀── get() / set()
                              │  (in-RAM)   │
                              └─────┬──────┘
                                    │ save()
                              ┌─────┴──────┐
                              ▼            ▼
                          ┌───────┐   ┌────────┐
                          │  SQL  │   │  YAML  │
                          │ WRITE │   │ MIRROR │
                          └───────┘   └────────┘
```

1. **Open** - Both SQL and YAML are loaded concurrently. Conflict resolution picks the source with the higher `revision` number.
2. **Use** - Your plugin reads/writes a lightweight in-memory `Session` object. Zero I/O on the main thread.
3. **Save** - Data is written to SQL first (source of truth). On success, a YAML mirror write is queued asynchronously.
4. **Close** - Dirty sessions are auto-saved. Memory is freed (no leaks).

---

## Requirements

- **PocketMine-MP** API 5.0.0+
- **PHP** 8.1 or newer
- **[libasynql](https://github.com/poggit/libasynql)** v4.x (included as a virion dependency)

---

## Installation

SimpleSQL is distributed as a **[Virion](https://github.com/poggit/support/blob/master/virion.md)** library.

### Using Poggit

Add SimpleSQL **and** its dependency [libasynql](https://github.com/poggit/libasynql) as libraries in your `.poggit.yml`:

```yaml
projects:
  YourPlugin:
    path: ""
    libs:
      - src: NhanAZ-Libraries/SimpleSQL/SimpleSQL
        version: ^1.0.0
      - src: poggit/libasynql/libasynql
        version: ^4.0.0
```

> **Note:** Poggit does not resolve transitive virion dependencies automatically. Since SimpleSQL uses libasynql internally, you **must** include both virions in your `libs` list.

### Manual Installation (DEVirion)

1. Download the SimpleSQL `.phar` virion.
2. Place it in your server's `virions/` directory.
3. Install the [DEVirion](https://github.com/poggit/devirion) plugin.

### SQL Resource Files

Your plugin **must** include the SQL resource files. Copy them from SimpleSQL's [`resources/simplesql/`](resources/simplesql/) into your plugin:

```
your-plugin/
├── resources/
│   └── simplesql/
│       ├── mysql.sql
│       └── sqlite.sql
├── src/
│   └── ...
└── plugin.yml
```

These files define the table schema and prepared statements that SimpleSQL uses internally.

> **Important:** Each SQL file **must** start with a dialect declaration on the very first line (`-- #!sqlite` or `-- #!mysql`). See the [libasynql Prepared Statement File format](https://poggit.github.io/libasynql/doxygen/) for details.

### Database Configuration

Add a database section to your plugin's `config.yml`:

```yaml
database:
  # "sqlite" or "mysql"
  type: sqlite
  sqlite:
    file: data.sqlite
  mysql:
    host: 127.0.0.1
    username: root
    password: ""
    schema: your_database
  worker-limit: 1
```

---

## Developer Guide

### Setup

Initialize SimpleSQL in your plugin's `onEnable()` and shut it down in `onDisable()`:

```php
use NhanAZ\SimpleSQL\SimpleSQL;

class MyPlugin extends PluginBase {

    private SimpleSQL $simpleSQL;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->simpleSQL = SimpleSQL::create(
            plugin: $this,
            dbConfig: $this->getConfig()->get("database"),
        );
    }

    protected function onDisable(): void {
        $this->simpleSQL->close();
    }
}
```

### Opening a Session

Sessions are loaded **asynchronously** from both SQL and YAML. You receive the session in a callback:

```php
$this->simpleSQL->openSession("Steve", function(Session $session): void {
    // Session is fully loaded and conflict-resolved.
    // Safe to read/write data here.
    $this->getLogger()->info("Session ready for " . $session->getId());
});
```

> **Important:** You cannot use a session before the callback fires. Always check `hasSession()` or `isLoading()` if you need to guard against early access.

### Reading & Writing Data

The `Session` object provides a simple key-value API:

```php
// Get a value (with default)
$coins = $session->get("coins", 0);

// Set a value
$session->set("coins", $coins + 100);

// Check existence
if ($session->has("vip")) {
    // ...
}

// Remove a key
$session->remove("temp_data");

// Get all data as an array
$allData = $session->getAll();
```

Values can be any JSON-serializable type: `int`, `float`, `string`, `bool`, `array`, or `null`.

### Saving & Closing

```php
// Explicit save (async) - SQL first, then YAML mirror
$session->save(function(bool $success): void {
    if ($success) {
        echo "Saved!";
    }
});

// Close a session (auto-saves if dirty, then frees memory)
$this->simpleSQL->closeSession("Steve", function(): void {
    echo "Session closed.";
});

// Or close via the Session object directly:
$session->close();
```

### Graceful Shutdown

Always call `close()` in your plugin's `onDisable()`. This:
- Cancels all pending loads.
- Persists all dirty sessions to SQL.
- Waits for SQL queries to finish.
- Closes the database connection (if SimpleSQL owns it).

```php
protected function onDisable(): void {
    $this->simpleSQL->close();
}
```

YAML writes are intentionally **skipped** during shutdown (SQL is the source of truth). The next time a session loads, the YAML file will be automatically synced.

---

## Server Owner's Guide - Editing YAML Files

One of SimpleSQL's best features is that all player data is mirrored to human-readable `.yml` files. This means you can view and edit data using any text editor - **but only while the server is stopped.**

### Where Are the Files?

By default, YAML files are stored in your plugin's data folder:

```
plugins/YourPlugin/simplesql/
├── s/
│   └── Steve.yml
├── a/
│   └── Alex.yml
└── _/
    └── _specialName.yml
```

Files are organized into subdirectories by the first letter of the player name (lowercase).

### What Does a YAML File Look Like?

```yaml
revision: 5
data:
  coins: 1500
  rank: "vip"
  homes:
    base:
      x: 100
      y: 64
      z: -200
      world: "lobby"
```

### How to Edit Safely

> **⚠️ CRITICAL RULE: Always increment the `revision` number!**

When SimpleSQL loads a session, it compares the `revision` in the YAML file against the `revision` in the SQL database. **The higher revision wins.** If you edit the YAML but leave the revision unchanged, your changes will be overwritten by the SQL data.

**Step-by-step:**

1. **Stop the server completely.** Never edit YAML files while the server is running.
2. **Open the `.yml` file** in a text editor (Notepad, VS Code, Nano, etc.).
3. **Make your changes** to the values under `data:`.
4. **Increment the `revision` number** by at least 1 (e.g., `5` → `6`).
5. **Save the file** and start the server.

**Example - Giving a player 1000 coins:**

Before:
```yaml
revision: 5
data:
  coins: 500
```

After:
```yaml
revision: 6
data:
  coins: 1500
```

### Common Mistakes to Avoid

| Mistake | What Happens | Fix |
|---|---|---|
| Editing while server is running | Your changes are overwritten immediately | Always stop the server first |
| Forgetting to increment `revision` | SQL has equal or higher revision; your edits are ignored | Always bump the revision by +1 |
| Breaking YAML syntax (bad indentation) | File is marked corrupt and renamed to `.broken`; SQL data is used instead | Use a YAML validator or be careful with indentation |
| Deleting the YAML file | No effect - it will be recreated from SQL on next session load | This is safe, but pointless |

### Recovering from Corruption

If a YAML file has invalid syntax, SimpleSQL will:
1. Rename the corrupt file to `PlayerName.yml.broken` (for your reference).
2. Fall back to the SQL database data (which is always reliable).
3. Create a fresh YAML mirror on the next save.

**You never lose data** - SQL is always the authoritative source.

---

## Performance Tips

### SQLite vs MySQL

| Scenario | Recommendation |
|---|---|
| **Small server** (< 50 players) | **SQLite** - Zero setup, single file, no external service needed. |
| **Medium server** (50–200 players) | **SQLite** is usually fine. Switch to MySQL if you notice slow saves. |
| **Large server / Network** (200+ players) | **MySQL** - Better concurrency, shared across multiple servers. |
| **BungeeCord / Proxy network** | **MySQL** - Required for cross-server data sharing. |

### Write Throttling

SimpleSQL throttles YAML writes to prevent I/O storms. The default is **3 writes per tick**. For large servers, you can increase this:

```php
$simpleSQL = SimpleSQL::create(
    plugin: $this,
    dbConfig: $config,
    maxWritesPerTick: 5, // Increase for high-throughput servers
);
```

### Memory Safety

**Always close sessions when they are no longer needed.** SimpleSQL holds session data in RAM. If you never call `closeSession()`, memory usage will grow indefinitely.

The recommended pattern for player data:
- `PlayerJoinEvent` → `openSession()`
- `PlayerQuitEvent` → `closeSession()`

---

## API Reference

### SimpleSQL (Main Class)

| Method | Description |
|---|---|
| `create(PluginBase, array, ?string, int): self` | Factory: creates instance with libasynql + tick task |
| `openSession(string $id, callable $callback): void` | Load session from SQL + YAML (async) |
| `getSession(string $id): ?Session` | Get an active session (or `null`) |
| `hasSession(string $id): bool` | Check if a session is active |
| `isLoading(string $id): bool` | Check if a session is currently loading |
| `saveSession(Session, ?callable): void` | Persist to SQL, then queue YAML mirror |
| `closeSession(string $id, ?callable): void` | Close session (auto-saves if dirty) |
| `tick(): void` | Drive the write scheduler (auto if using `create()`) |
| `close(): void` | Graceful shutdown |
| `getDatabase(): DataConnector` | Access the underlying DataConnector |
| `getYamlDataPath(): string` | Get the YAML data directory |
| `getSessionCount(): int` | Number of active sessions |
| `getSessionIds(): string[]` | List of active session IDs |

### Session

| Method | Description |
|---|---|
| `getId(): string` | Session identifier |
| `get(string $key, mixed $default = null): mixed` | Read a value |
| `set(string $key, mixed $value): void` | Write a value |
| `remove(string $key): void` | Delete a key |
| `has(string $key): bool` | Check if a key exists |
| `getAll(): array` | Get all data |
| `setAll(array $data): void` | Replace all data |
| `getRevision(): int` | Current revision number |
| `isDirty(): bool` | Has unsaved changes? |
| `isClosed(): bool` | Has this session been closed? |
| `save(?callable $onComplete = null): void` | Persist (async) |
| `close(?callable $onComplete = null): void` | Close session |

---

## License

MIT License. See [LICENSE](LICENSE) for details.
