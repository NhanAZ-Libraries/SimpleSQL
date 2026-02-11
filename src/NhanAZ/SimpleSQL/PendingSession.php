<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

use Closure;

/**
 * Barrier that collects BOTH asynchronous SQL and YAML load results
 * before applying conflict resolution and creating the final {@see Session}.
 *
 * Conflict Resolution Rules:
 *   1. YAML corrupt/missing          → SQL is authoritative, mirror to YAML (if data exists).
 *   2. SQL has no row, YAML exists   → YAML is used (offline-created data), sync to SQL.
 *   3. YAML.revision > SQL.revision  → Offline edit detected, YAML wins, sync to SQL.
 *   4. SQL.revision >= YAML.revision → SQL wins, mirror to YAML if data differs.
 *   5. Same revision, same data      → No sync needed.
 *
 * CRITICAL: Neither result is processed until both have arrived, preventing race conditions.
 */
class PendingSession {

	// ── SQL result ──
	private bool $sqlLoaded = false;
	private ?array $sqlData = null;
	private int $sqlRevision = 0;

	// ── YAML result ──
	private bool $yamlLoaded = false;
	private ?array $yamlData = null;
	private int $yamlRevision = 0;
	private bool $yamlCorrupt = false;
	private bool $yamlMissing = false;

	/** Whether this pending session has been cancelled (e.g. by closeSession during load). */
	private bool $cancelled = false;

	/**
	 * @param string    $id       Session identifier.
	 * @param SimpleSQL $manager  Parent manager for creating sessions and dispatching syncs.
	 * @param Closure   $callback Invoked with the created Session after resolution: fn(Session): void
	 */
	public function __construct(
		private readonly string $id,
		private readonly SimpleSQL $manager,
		private readonly Closure $callback,
	) {}

	/**
	 * Called when the SQL load completes (from libasynql callback).
	 *
	 * @param array|null $data     Decoded data array, or null if no row found.
	 * @param int        $revision Revision from SQL, or 0 if no row.
	 */
	public function onSqlLoaded(?array $data, int $revision): void {
		$this->sqlData = $data;
		$this->sqlRevision = $revision;
		$this->sqlLoaded = true;
		$this->tryResolve();
	}

	/**
	 * Called when the YAML AsyncTask completes (from onCompletion callback).
	 *
	 * @param array|null $data     Parsed YAML data, or null if missing/corrupt.
	 * @param int        $revision YAML revision, or 0 if unavailable.
	 * @param bool       $missing  True if the YAML file did not exist.
	 * @param bool       $corrupt  True if the YAML file was corrupt (renamed to .broken).
	 */
	public function onYamlLoaded(?array $data, int $revision, bool $missing, bool $corrupt): void {
		$this->yamlData = $data;
		$this->yamlRevision = $revision;
		$this->yamlMissing = $missing;
		$this->yamlCorrupt = $corrupt;
		$this->yamlLoaded = true;
		$this->tryResolve();
	}

	/**
	 * Cancel this pending session (e.g. plugin disable during load).
	 * The callback will NOT be invoked.
	 */
	public function cancel(): void {
		$this->cancelled = true;
	}

	/**
	 * @return bool Whether this pending session has been cancelled.
	 */
	public function isCancelled(): bool {
		return $this->cancelled;
	}

	/**
	 * Attempt resolution once both results are available.
	 * This is the CORE of the conflict resolution logic.
	 */
	private function tryResolve(): void {
		if (!$this->sqlLoaded || !$this->yamlLoaded || $this->cancelled) {
			return;
		}

		$finalData = [];
		$finalRevision = 0;
		$syncToSql = false;
		$syncToYaml = false;

		if ($this->yamlCorrupt || $this->yamlMissing) {
			// ─── Rule 1: YAML unavailable → SQL is authoritative ───
			$finalData = $this->sqlData ?? [];
			$finalRevision = $this->sqlRevision;
			// Only mirror to YAML if SQL actually has data
			$syncToYaml = ($this->sqlData !== null);

		} elseif ($this->sqlData === null) {
			// ─── Rule 2: No SQL row → use YAML if available ───
			if ($this->yamlData !== null) {
				$finalData = $this->yamlData;
				$finalRevision = $this->yamlRevision;
				$syncToSql = true;
			}
			// Both empty → start fresh (no syncs needed)

		} elseif ($this->yamlRevision > $this->sqlRevision) {
			// ─── Rule 3: YAML has higher revision → offline edit ───
			$finalData = $this->yamlData ?? [];
			$finalRevision = $this->yamlRevision;
			$syncToSql = true;

		} else {
			// ─── Rule 4 & 5: SQL wins (same or higher revision) ───
			$finalData = $this->sqlData;
			$finalRevision = $this->sqlRevision;

			// Mirror to YAML if data or revision differs
			if ($this->yamlData !== $this->sqlData || $this->yamlRevision !== $this->sqlRevision) {
				$syncToYaml = true;
			}
		}

		// Create the active session
		$session = $this->manager->_createSession($this->id, $finalData, $finalRevision);

		// Dispatch any necessary syncs
		if ($syncToSql) {
			$this->manager->_persistToSql($this->id, $finalData, $finalRevision);
		}
		if ($syncToYaml) {
			$this->manager->_persistToYaml($this->id, $finalData, $finalRevision);
		}

		// Invoke the developer callback
		($this->callback)($session);
	}
}
