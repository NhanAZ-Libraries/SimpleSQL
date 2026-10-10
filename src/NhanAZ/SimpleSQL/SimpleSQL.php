<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

use Closure;
use Logger;
use NhanAZ\SimpleSQL\task\TickTask;
use NhanAZ\SimpleSQL\task\YamlLoadTask;
use NhanAZ\SimpleSQL\task\YamlWriteTask;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use poggit\libasynql\SqlError;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * SimpleSQL - Hybrid SQL-YAML data management for PocketMine-MP.
 *
 * Combines the performance and reliability of SQL (via libasynql) with the
 * offline-editability of YAML files. SQL is the authoritative source of truth
 * during runtime; YAML files serve as a human-friendly mirror.
 *
 * ## Quick Start
 * ```php
 * // In your plugin's onEnable():
 * $this->simpleSQL = SimpleSQL::create($this, $this->getConfig()->get("database"));
 *
 * // Open a session (async):
 * $this->simpleSQL->openSession("Steve", function(Session $session): void {
 *     $coins = $session->get("coins", 0);
 *     $session->set("coins", $coins + 100);
 *     $session->save();
 * });
 *
 * // In your plugin's onDisable():
 * $this->simpleSQL->close();
 * ```
 *
 * ## Requirements
 * Your plugin MUST include the SQL resource files in its resources directory:
 *   - `resources/simplesql/mysql.sql`
 *   - `resources/simplesql/sqlite.sql`
 *
 * These files are shipped with the SimpleSQL virion in `resources/simplesql/`.
 *
 * ## Performance Standards
 * - S1: No main thread I/O - all YAML operations are in AsyncTask.
 * - S2: No blocking SQL - uses libasynql prepared statements exclusively.
 * - S3: No memory leaks - mandatory closeSession() clears all data.
 */
class SimpleSQL {

	/** @var array<string, Session> Active sessions indexed by ID. */
	private array $sessions = [];

	/** @var array<string, PendingSession> Sessions currently loading (barrier). */
	private array $pending = [];

	/** @var array<string, true> */
	private array $savesInFlight = [];
	/** @var array<string, true> */
	private array $saveRequested = [];
	/** @var array<string, list<Closure(bool): void>> */
	private array $saveWaiters = [];

	/** Write throttle and coalescing scheduler. */
	private WriteScheduler $writeScheduler;

	/** @var TaskHandler<TickTask>|null Tick task handler (for cancellation on close). */
	private ?TaskHandler $tickHandler = null;

	// ──────────────────────────────────────────────────────────
	//  Factory
	// ──────────────────────────────────────────────────────────

	/**
	 * Create a fully configured SimpleSQL instance.
	 *
	 * This factory:
	 *   1. Creates a DataConnector via libasynql.
	 *   2. Initializes the SQL table.
	 *   3. Registers a repeating tick task for the write scheduler.
	 *
	 * Your plugin MUST include the SQL resource files:
	 *   - `resources/simplesql/mysql.sql`
	 *   - `resources/simplesql/sqlite.sql`
	 *
	 * @param PluginBase  $plugin          The owning plugin instance.
	 * @param array<string, mixed> $dbConfig Database configuration array (type, sqlite, mysql sections).
	 * @param string|null $yamlDataPath    Custom YAML data directory. Defaults to `{dataFolder}/simplesql/`.
	 * @param int         $maxWritesPerTick Maximum YAML writes dispatched per tick (default 3).
	 * @return self
	 */
	public static function create(
		PluginBase $plugin,
		array $dbConfig,
		?string $yamlDataPath = null,
		int $maxWritesPerTick = 3,
	): self {
		$db = libasynql::create($plugin, $dbConfig, [
			"mysql" => "simplesql/mysql.sql",
			"sqlite" => "simplesql/sqlite.sql",
		]);

		$yamlPath = $yamlDataPath ?? ($plugin->getDataFolder() . "simplesql" . DIRECTORY_SEPARATOR);

		$instance = new self(
			db: $db,
			yamlDataPath: $yamlPath,
			server: $plugin->getServer(),
			logger: $plugin->getLogger(),
			maxWritesPerTick: $maxWritesPerTick,
			ownsDb: true,
		);

		// Register tick task for write scheduler dispatch
		$instance->tickHandler = $plugin->getScheduler()->scheduleRepeatingTask(
			new TickTask($instance),
			1 // every tick
		);

		return $instance;
	}

	// ──────────────────────────────────────────────────────────
	//  Constructor
	// ──────────────────────────────────────────────────────────

	/**
	 * @param DataConnector $db              Pre-configured DataConnector with simplesql.* queries.
	 * @param string        $yamlDataPath    Directory for YAML mirror files.
	 * @param Server        $server          Server instance (for AsyncPool access).
	 * @param Logger|null   $logger          Optional logger for warnings/errors.
	 * @param int           $maxWritesPerTick Write throttle limit.
	 * @param bool          $ownsDb          Whether to close the DataConnector on shutdown.
	 */
	public function __construct(
		private readonly DataConnector $db,
		private string $yamlDataPath,
		private readonly Server $server,
		private readonly ?Logger $logger = null,
		int $maxWritesPerTick = 3,
		private readonly bool $ownsDb = false,
	) {
		$this->yamlDataPath = rtrim($yamlDataPath, "/\\") . DIRECTORY_SEPARATOR;
		$this->writeScheduler = new WriteScheduler($this, $maxWritesPerTick);

		// Ensure base YAML directory exists
		if (!is_dir($this->yamlDataPath)) {
			@mkdir($this->yamlDataPath, 0777, true);
		}

		// Initialize SQL table
		$this->db->executeGeneric("simplesql.init", [], null, function (SqlError $error): void {
			$this->logger?->error("[SimpleSQL] Table initialization failed: " . $error->getMessage());
		});
		$this->db->waitAll();
	}

	// ──────────────────────────────────────────────────────────
	//  Public API
	// ──────────────────────────────────────────────────────────

	/**
	 * Open a session by ID, loading data from both SQL and YAML asynchronously.
	 *
	 * Both loads MUST complete before the callback fires (barrier logic).
	 * Conflict resolution is applied automatically per the documented rules.
	 *
	 * @param string   $id       Unique session identifier (e.g. player name or UUID).
	 * @param callable(Session): void $callback Invoked when the session is ready.
	 * @param (callable(string): void)|null $onError Invoked if loading or synchronization fails.
	 *
	 * @throws RuntimeException If a session or pending load already exists for this ID.
	 */
	public function openSession(string $id, callable $callback, ?callable $onError = null): void {
		if (isset($this->sessions[$id])) {
			throw new RuntimeException("Session '$id' is already open");
		}
		if (isset($this->pending[$id])) {
			throw new RuntimeException("Session '$id' is already loading");
		}

		$pending = new PendingSession(
			$id,
			$this,
			Closure::fromCallable($callback),
			$onError !== null ? Closure::fromCallable($onError) : null
		);
		$this->pending[$id] = $pending;

		// ── Start SQL load (async via libasynql) ──
		try {
		$this->db->executeSelect(
			"simplesql.load",
			["id" => $id],
			function (array $rows) use ($pending, $id): void {
				if ($pending->isCancelled()) {
					return;
				}
				if (count($rows) > 0) {
					$row = $rows[0];
					if (!is_array($row)) {
						$pending->onLoadFailed("SQL load returned an invalid row.");
						return;
					}
					try {
						$decoded = SqlRowDecoder::decode($row);
					} catch (UnexpectedValueException $error) {
						$this->logger?->error("[SimpleSQL] Invalid SQL data for '$id': " . $error->getMessage());
						$pending->onLoadFailed($error->getMessage());
						return;
					}
					$pending->onSqlLoaded($decoded["data"], $decoded["revision"]);
				} else {
					$pending->onSqlLoaded(null, 0);
				}
			},
			function (SqlError $error) use ($pending, $id): void {
				$this->logger?->error("[SimpleSQL] SQL load failed for '$id': " . $error->getMessage());
				if (!$pending->isCancelled()) {
					$pending->onLoadFailed($error->getMessage());
				}
			}
		);
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] SQL load could not start for '$id': " . $error->getMessage());
			$pending->onLoadFailed($error->getMessage());
			return;
		}

		// ── Start YAML load (async via AsyncTask) ──
		$filePath = $this->getYamlPath($id);
		$task = new YamlLoadTask(
			$filePath,
			$id,
			function (string $sessionId, array $result) use ($pending): void {
				if ($pending->isCancelled()) {
					return;
				}

				$data = $result["data"] ?? null;
				$revision = $result["revision"] ?? null;
				$missing = $result["missing"] ?? null;
				$corrupt = $result["corrupt"] ?? null;
				if (($data !== null && !is_array($data)) || !is_int($revision) || $revision < 0 || !is_bool($missing) || !is_bool($corrupt)) {
					$pending->onLoadFailed("Invalid YAML load result.");
					return;
				}
				if ($corrupt) {
					$reason = $result["reason"] ?? "unknown reason";
					if (!is_string($reason)) {
						$reason = "unknown reason";
					}
					$this->logger?->warning(
						"[SimpleSQL] YAML file for '$sessionId' was corrupt: "
						. $reason
						. " - renamed to .broken, falling back to SQL."
					);
				}

				$pending->onYamlLoaded(
					$data,
					$revision,
					$missing,
					$corrupt,
				);
			}
		);
		try {
			$this->server->getAsyncPool()->submitTask($task);
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] YAML load could not start for '$id': " . $error->getMessage());
			$pending->onLoadFailed($error->getMessage());
		}
	}

	/**
	 * Get an active session by ID, or null if not loaded.
	 */
	public function getSession(string $id): ?Session {
		return $this->sessions[$id] ?? null;
	}

	/**
	 * Check whether a session is currently active.
	 */
	public function hasSession(string $id): bool {
		return isset($this->sessions[$id]);
	}

	/**
	 * Check whether a session is currently loading.
	 */
	public function isLoading(string $id): bool {
		return isset($this->pending[$id]);
	}

	/**
	 * Persist a session's data to SQL (authoritative), then queue a YAML mirror write.
	 *
	 * Revision is incremented ONLY after SQL confirms success.
	 *
	 * @param Session       $session    The session to save.
	 * @param (callable(bool): void)|null $onComplete Callback after SQL save.
	 */
	public function saveSession(Session $session, ?callable $onComplete = null): void {
		$id = $session->getId();
		if (($this->sessions[$id] ?? null) !== $session) {
			throw new RuntimeException("Session '$id' is not active");
		}
		if (isset($this->savesInFlight[$id])) {
			$this->saveRequested[$id] = true;
			if ($onComplete !== null) {
				$this->saveWaiters[$id][] = Closure::fromCallable($onComplete);
			}
			return;
		}
		if (!$session->isDirty()) {
			if ($onComplete !== null) {
				$onComplete(true);
			}
			return;
		}
		$data = $session->_getData();
		$newRevision = $session->getRevision() + 1;
		$mutationVersion = $session->_getMutationVersion();
		$this->savesInFlight[$id] = true;
		$finish = function (bool $success) use ($session, $id, $onComplete): void {
			unset($this->savesInFlight[$id]);
			$requested = isset($this->saveRequested[$id]);
			$waiters = $this->saveWaiters[$id] ?? [];
			unset($this->saveRequested[$id], $this->saveWaiters[$id]);
			if ($onComplete !== null) {
				$onComplete($success);
			}
			if (isset($this->savesInFlight[$id])) {
				if ($requested) {
					$this->saveRequested[$id] = true;
					foreach ($waiters as $waiter) {
						$this->saveWaiters[$id][] = $waiter;
					}
				}
				return;
			}
			if ($success && $requested && $session->isDirty()) {
				$this->saveSession($session, static function (bool $nextSuccess) use ($waiters): void {
					foreach ($waiters as $waiter) {
						$waiter($nextSuccess);
					}
				});
			} else {
				foreach ($waiters as $waiter) {
					$waiter($success);
				}
			}
		};

		$encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encodedData === false) {
			if ($this->logger !== null) {
				$this->logger->error("[SimpleSQL] JSON encode failed for session '$id'");
			}
			$finish(false);
			return;
		}

		try {
		$this->db->executeGeneric(
			"simplesql.save",
			[
				"id" => $id,
				"data" => $encodedData,
				"revision" => $newRevision,
			],
			function () use ($session, $id, $data, $newRevision, $mutationVersion, $finish): void {
				// SQL persistence confirmed - now safe to increment revision
				$session->_setRevision($newRevision);
				$session->_markCleanIfUnchanged($mutationVersion);

				// Queue YAML mirror write (same revision as SQL)
				$this->writeScheduler->scheduleWrite($id, $data, $newRevision);

				$finish(true);
			},
			function (SqlError $error) use ($id, $finish): void {
				$this->logger?->error("[SimpleSQL] SQL save failed for '$id': " . $error->getMessage());
				$finish(false);
			}
		);
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] SQL save could not start for '$id': " . $error->getMessage());
			$finish(false);
		}
	}

	/**
	 * Close a session, releasing all held data (S3 memory leak prevention).
	 *
	 * If the session is dirty, an implicit save is triggered before closing.
	 * If the session is still loading, the load is cancelled.
	 *
	 * @param string        $id         Session identifier.
	 * @param (callable(): void)|null $onComplete Callback after successful close.
	 */
	public function closeSession(string $id, ?callable $onComplete = null): void {
		// Cancel pending load if still in progress
		if (isset($this->pending[$id])) {
			$this->pending[$id]->cancel();
			unset($this->pending[$id]);
			if ($onComplete !== null) {
				$onComplete();
			}
			return;
		}

		$session = $this->sessions[$id] ?? null;
		if ($session === null) {
			if ($onComplete !== null) {
				$onComplete();
			}
			return;
		}

		$doClose = function () use ($id, $session, $onComplete): void {
			if (($this->sessions[$id] ?? null) !== $session) {
				return;
			}
			$session->_markClosed();
			unset($this->sessions[$id]);
			// Note: don't cancel write scheduler - let pending YAML writes finish
			if ($onComplete !== null) {
				$onComplete();
			}
		};

		if ($session->isDirty() || isset($this->savesInFlight[$id])) {
			$this->saveSession($session, function (bool $success) use ($id, $session, $doClose, $onComplete): void {
				if (!$success) {
					return;
				}
				if ($session->isDirty()) {
					$this->closeSession($id, $onComplete);
					return;
				}
				$doClose();
			});
		} else {
			$doClose();
		}
	}

	/**
	 * Drive the write scheduler. Call this every tick if not using the factory method.
	 */
	public function tick(): void {
		$this->writeScheduler->tick();
	}

	/**
	 * Graceful shutdown: settle SQL writes, then persist remaining dirty sessions.
	 * If SQL remains unavailable, retain a separate recovery snapshot on disk.
	 */
	public function close(): void {
		// Cancel tick task
		if ($this->tickHandler !== null) {
			$this->tickHandler->cancel();
			$this->tickHandler = null;
		}

		// Cancel all pending loads
		foreach ($this->pending as $pending) {
			$pending->cancel();
		}
		$this->pending = [];

		try {
			$this->db->waitAll();
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] Could not finish pending SQL writes during shutdown: " . $error->getMessage());
		}

		foreach ($this->sessions as $id => $session) {
			for ($attempt = 0; $attempt < 3 && $session->isDirty(); $attempt++) {
				$success = false;
				try {
					$this->saveSession($session, function (bool $saved) use (&$success): void {
						$success = $saved;
					});
					$this->db->waitAll();
				} catch (Throwable $error) {
					$this->logger?->error("[SimpleSQL] Shutdown SQL save failed for '$id': " . $error->getMessage());
					break;
				}
				if (!$success) {
					break;
				}
			}
			if ($session->isDirty()) {
				$this->writeRecoverySnapshot($id, $session);
			}
			$session->_markClosed();
		}

		$this->sessions = [];

		if ($this->ownsDb) {
			$this->db->close();
		}
	}

	// ──────────────────────────────────────────────────────────
	//  Accessors
	// ──────────────────────────────────────────────────────────

	/**
	 * Returns the DataConnector instance.
	 */
	public function getDatabase(): DataConnector {
		return $this->db;
	}

	/**
	 * Returns the YAML data directory path.
	 */
	public function getYamlDataPath(): string {
		return $this->yamlDataPath;
	}

	/**
	 * Returns the write scheduler instance.
	 */
	public function getWriteScheduler(): WriteScheduler {
		return $this->writeScheduler;
	}

	/**
	 * Returns all active session IDs.
	 * @return string[]
	 */
	public function getSessionIds(): array {
		return array_keys($this->sessions);
	}

	/**
	 * Returns the number of active sessions.
	 */
	public function getSessionCount(): int {
		return count($this->sessions);
	}

	// ──────────────────────────────────────────────────────────
	//  Internal methods (called by PendingSession, WriteScheduler)
	// ──────────────────────────────────────────────────────────

	/**
	 * @internal Create and register a Session after barrier resolution.
	 * @param array<array-key, mixed> $data
	 */
	public function _createSession(string $id, array $data, int $revision): Session {
		$session = new Session($id, $data, $revision, $this);
		$this->sessions[$id] = $session;
		unset($this->pending[$id]);
		return $session;
	}

	public function _abortPending(string $id, PendingSession $pending): void {
		if (($this->pending[$id] ?? null) === $pending) {
			unset($this->pending[$id]);
		}
	}

	/**
	 * @internal Persist data to SQL (used by PendingSession for sync operations).
	 * @param array<array-key, mixed> $data
	 */
	public function _persistToSql(string $id, array $data, int $revision, Closure $onComplete): void {
		$encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encodedData === false) {
			$this->logger?->error("[SimpleSQL] JSON encode failed during sync for '$id'");
			$onComplete(false);
			return;
		}

		try {
		$this->db->executeGeneric(
			"simplesql.save",
			[
				"id" => $id,
				"data" => $encodedData,
				"revision" => $revision,
			],
			function () use ($onComplete): void {
				$onComplete(true);
			},
			function (SqlError $error) use ($id, $onComplete): void {
				$this->logger?->error("[SimpleSQL] Sync-to-SQL failed for '$id': " . $error->getMessage());
				$onComplete(false);
			}
		);
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] SQL synchronization could not start for '$id': " . $error->getMessage());
			$onComplete(false);
		}
	}

	/**
	 * @internal Queue a YAML mirror write via the write scheduler.
	 * @param array<array-key, mixed> $data
	 */
	public function _persistToYaml(string $id, array $data, int $revision): void {
		$this->writeScheduler->scheduleWrite($id, $data, $revision);
	}

	/**
	 * @internal Dispatch a YAML write AsyncTask (called by WriteScheduler).
	 * @param array<array-key, mixed> $data
	 */
	public function _dispatchYamlWrite(string $id, array $data, int $revision, Closure $onComplete): void {
		$filePath = $this->getYamlPath($id);

		$task = new YamlWriteTask(
			$filePath,
			$data,
			$revision,
			function (bool $success, string $error) use ($id, $onComplete): void {
				if (!$success && $error !== "") {
					$this->logger?->warning("[SimpleSQL] YAML write failed for '$id': $error");
				}
				$onComplete();
			}
		);

		$this->server->getAsyncPool()->submitTask($task);
	}

	// ──────────────────────────────────────────────────────────
	//  Private helpers
	// ──────────────────────────────────────────────────────────

	/**
	 * Compute the sharded YAML file path for a session ID.
	 *
	 * Uses 1-level subdirectory sharding based on the first character of the ID
	 * (lowercased) to prevent directory metadata overhead on large installations.
	 *
	 * Example: "Steve" → {yamlDataPath}/s/Steve.yml
	 *
	 * @param string $id Session identifier.
	 * @return string Absolute file path.
	 */
	private function getYamlPath(string $id): string {
		$firstChar = strtolower(substr($id, 0, 1));
		// Use '_' shard for empty IDs or non-alphanumeric first characters
		if ($firstChar === "" || !ctype_alnum($firstChar)) {
			$firstChar = "_";
		}
		$safeId = rawurlencode($id);
		if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $safeId) === 1) {
			$safeId = sprintf("%%%02X%s", ord($safeId[0]), substr($safeId, 1));
		}
		return $this->yamlDataPath . $firstChar . DIRECTORY_SEPARATOR . $safeId . ".yml";
	}

	private function writeRecoverySnapshot(string $id, Session $session): void {
		try {
			$path = $this->getYamlPath($id) . "." . bin2hex(random_bytes(6)) . ".recovery.yml";
			$directory = dirname($path);
			if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
				throw new RuntimeException("Could not create recovery directory.");
			}
			$content = yaml_emit([
				"revision" => $session->getRevision() + 1,
				"data" => $session->_getData(),
			], YAML_UTF8_ENCODING);
			if (!$content) {
				throw new RuntimeException("Could not encode recovery YAML.");
			}
			$tmpPath = $path . ".tmp";
			if (@file_put_contents($tmpPath, $content, LOCK_EX) === false || !@rename($tmpPath, $path)) {
				@unlink($tmpPath);
				throw new RuntimeException("Could not write recovery YAML.");
			}
			$this->logger?->error("[SimpleSQL] SQL save failed for '$id'; unsaved data was written to '$path'. Restore it while the server is stopped.");
		} catch (Throwable $error) {
			$this->logger?->error("[SimpleSQL] SQL save and recovery snapshot both failed for '$id': " . $error->getMessage());
		}
	}
}
