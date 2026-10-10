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
	/** @var array<array-key, mixed>|null */
	private ?array $sqlData = null;
	private int $sqlRevision = 0;

	// ── YAML result ──
	private bool $yamlLoaded = false;
	/** @var array<array-key, mixed>|null */
	private ?array $yamlData = null;
	private int $yamlRevision = 0;
	private bool $yamlCorrupt = false;
	private bool $yamlMissing = false;

	/** Whether this pending session has been cancelled (e.g. by closeSession during load). */
	private bool $cancelled = false;
	private bool $resolutionStarted = false;

	/**
	 * @param string    $id       Session identifier.
	 * @param SimpleSQL $manager  Parent manager for creating sessions and dispatching syncs.
	 * @param Closure   $callback Invoked with the created Session after resolution: fn(Session): void
	 */
	public function __construct(
		private readonly string $id,
		private readonly SimpleSQL $manager,
		private readonly Closure $callback,
		private readonly ?Closure $onError = null,
	) {}

	public function onLoadFailed(string $reason): void {
		if ($this->cancelled) {
			return;
		}
		$this->cancelled = true;
		$this->manager->_abortPending($this->id, $this);
		if ($this->onError !== null) {
			($this->onError)($reason);
		}
	}

	/**
	 * Called when the SQL load completes (from libasynql callback).
	 *
	 * @param array<array-key, mixed>|null $data Decoded data array, or null if no row found.
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
	 * @param array<array-key, mixed>|null $data Parsed YAML data, or null if missing/corrupt.
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
		if (!$this->sqlLoaded || !$this->yamlLoaded || $this->cancelled || $this->resolutionStarted) {
			return;
		}
		$this->resolutionStarted = true;
		if ($this->yamlCorrupt && $this->sqlData === null) {
			$this->onLoadFailed("YAML is corrupt and SQL has no row to recover from.");
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

		if ($syncToSql) {
			$this->manager->_persistToSql($this->id, $finalData, $finalRevision, function (bool $success) use ($finalData, $finalRevision, $syncToYaml): void {
				if ($this->cancelled) {
					return;
				}
				if (!$success) {
					$this->onLoadFailed("SQL synchronization failed.");
					return;
				}
				$this->complete($finalData, $finalRevision, $syncToYaml);
			});
			return;
		}
		$this->complete($finalData, $finalRevision, $syncToYaml);
	}

	/** @param array<array-key, mixed> $data */
	private function complete(array $data, int $revision, bool $syncToYaml): void {
		$session = $this->manager->_createSession($this->id, $data, $revision);
		if ($syncToYaml) {
			$this->manager->_persistToYaml($this->id, $data, $revision);
		}
		($this->callback)($session);
	}
}
