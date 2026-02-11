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

/**
 * SimpleSQL — Hybrid SQL-YAML data management for PocketMine-MP.
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
 * - S1: No main thread I/O — all YAML operations are in AsyncTask.
 * - S2: No blocking SQL — uses libasynql prepared statements exclusively.
 * - S3: No memory leaks — mandatory closeSession() clears all data.
 */
class SimpleSQL {

	/** @var array<string, Session> Active sessions indexed by ID. */
	private array $sessions = [];

	/** @var array<string, PendingSession> Sessions currently loading (barrier). */
	private array $pending = [];

	/** Write throttle and coalescing scheduler. */
	private WriteScheduler $writeScheduler;

	/** Tick task handler (for cancellation on close). */
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
	 * @param array       $dbConfig        Database configuration array (type, sqlite, mysql sections).
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
	 * @param callable $callback Invoked when the session is ready: fn(Session $session): void
	 *
	 * @throws RuntimeException If a session or pending load already exists for this ID.
	 */
	public function openSession(string $id, callable $callback): void {
		if (isset($this->sessions[$id])) {
			throw new RuntimeException("Session '$id' is already open");
		}
		if (isset($this->pending[$id])) {
			throw new RuntimeException("Session '$id' is already loading");
		}

		$pending = new PendingSession($id, $this, Closure::fromCallable($callback));
		$this->pending[$id] = $pending;

		// ── Start SQL load (async via libasynql) ──
		$this->db->executeSelect(
			"simplesql.load",
			["id" => $id],
			function (array $rows) use ($pending): void {
				if ($pending->isCancelled()) {
					return;
				}
				if (count($rows) > 0) {
					$row = $rows[0];
					$data = json_decode((string) $row["data"], true);
					if (!is_array($data)) {
						$data = [];
					}
					$revision = (int) $row["revision"];
					$pending->onSqlLoaded($data, $revision);
				} else {
					$pending->onSqlLoaded(null, 0);
				}
			},
			function (SqlError $error) use ($pending, $id): void {
				$this->logger?->error("[SimpleSQL] SQL load failed for '$id': " . $error->getMessage());
				if (!$pending->isCancelled()) {
					$pending->onSqlLoaded(null, 0);
				}
			}
		);

		// ── Start YAML load (async via AsyncTask) ──
		$filePath = $this->getYamlPath($id);
		$task = new YamlLoadTask(
			$filePath,
			$id,
			function (string $sessionId, array $result) use ($pending): void {
				if ($pending->isCancelled()) {
					return;
				}

				if (!empty($result["corrupt"])) {
					$this->logger?->warning(
						"[SimpleSQL] YAML file for '$sessionId' was corrupt: "
						. ($result["reason"] ?? "unknown reason")
						. " — renamed to .broken, falling back to SQL."
					);
				}

				$pending->onYamlLoaded(
					$result["data"],
					(int) $result["revision"],
					(bool) $result["missing"],
					(bool) $result["corrupt"],
				);
			}
		);
		$this->server->getAsyncPool()->submitTask($task);
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
	 * @param callable|null $onComplete Callback: fn(bool $success): void
	 */
	public function saveSession(Session $session, ?callable $onComplete = null): void {
		$id = $session->getId();
		$data = $session->_getData();
		$newRevision = $session->getRevision() + 1;

		$encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encodedData === false) {
			if ($this->logger !== null) {
				$this->logger->error("[SimpleSQL] JSON encode failed for session '$id'");
			}
			if ($onComplete !== null) {
				$onComplete(false);
			}
			return;
		}

		$this->db->executeGeneric(
			"simplesql.save",
			[
				"id" => $id,
				"data" => $encodedData,
				"revision" => $newRevision,
			],
			function () use ($session, $id, $data, $newRevision, $onComplete): void {
				// SQL persistence confirmed — now safe to increment revision
				$session->_setRevision($newRevision);
				$session->_markClean();

				// Queue YAML mirror write (same revision as SQL)
				$this->writeScheduler->scheduleWrite($id, $data, $newRevision);

				if ($onComplete !== null) {
					$onComplete(true);
				}
			},
			function (SqlError $error) use ($id, $onComplete): void {
				$this->logger?->error("[SimpleSQL] SQL save failed for '$id': " . $error->getMessage());
				if ($onComplete !== null) {
					$onComplete(false);
				}
			}
		);
	}

	/**
	 * Close a session, releasing all held data (S3 memory leak prevention).
	 *
	 * If the session is dirty, an implicit save is triggered before closing.
	 * If the session is still loading, the load is cancelled.
	 *
	 * @param string        $id         Session identifier.
	 * @param callable|null $onComplete Callback: fn(): void
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
			$session->_markClosed();
			unset($this->sessions[$id]);
			// Note: don't cancel write scheduler — let pending YAML writes finish
			if ($onComplete !== null) {
				$onComplete();
			}
		};

		if ($session->isDirty()) {
			$this->saveSession($session, function (bool $success) use ($doClose): void {
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
	 * Graceful shutdown: persist all dirty sessions to SQL and clean up.
	 *
	 * YAML writes are intentionally skipped during shutdown (SQL is source of truth).
	 * Next session load will automatically sync SQL → YAML.
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

		// Persist all dirty sessions to SQL (non-blocking queued, then waitAll)
		foreach ($this->sessions as $id => $session) {
			if ($session->isDirty()) {
				$data = $session->_getData();
				$newRevision = $session->getRevision() + 1;
				$encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ($encodedData !== false) {
					$this->db->executeGeneric("simplesql.save", [
						"id" => $id,
						"data" => $encodedData,
						"revision" => $newRevision,
					]);
				}
			}
			$session->_markClosed();
		}

		// Block until all SQL queries complete (acceptable during shutdown)
		$this->db->waitAll();
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
	 */
	public function _createSession(string $id, array $data, int $revision): Session {
		$session = new Session($id, $data, $revision, $this);
		$this->sessions[$id] = $session;
		unset($this->pending[$id]);
		return $session;
	}

	/**
	 * @internal Persist data to SQL (used by PendingSession for sync operations).
	 */
	public function _persistToSql(string $id, array $data, int $revision): void {
		$encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encodedData === false) {
			$this->logger?->error("[SimpleSQL] JSON encode failed during sync for '$id'");
			return;
		}

		$this->db->executeGeneric(
			"simplesql.save",
			[
				"id" => $id,
				"data" => $encodedData,
				"revision" => $revision,
			],
			null,
			function (SqlError $error) use ($id): void {
				$this->logger?->error("[SimpleSQL] Sync-to-SQL failed for '$id': " . $error->getMessage());
			}
		);
	}

	/**
	 * @internal Queue a YAML mirror write via the write scheduler.
	 */
	public function _persistToYaml(string $id, array $data, int $revision): void {
		$this->writeScheduler->scheduleWrite($id, $data, $revision);
	}

	/**
	 * @internal Dispatch a YAML write AsyncTask (called by WriteScheduler).
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
		return $this->yamlDataPath . $firstChar . DIRECTORY_SEPARATOR . $id . ".yml";
	}
}
