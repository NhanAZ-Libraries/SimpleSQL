<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

use RuntimeException;

/**
 * Represents an active data session with hybrid SQL + YAML storage.
 *
 * Provides a simple, synchronous-feeling key-value API.
 * All persistence is handled asynchronously by the parent {@see SimpleSQL} manager.
 *
 * Usage:
 *   $session->get("coins", 0);
 *   $session->set("coins", 100);
 *   $session->save();          // async persist to SQL, then mirror to YAML
 *   $session->close();         // implicit save if dirty, then cleanup
 */
class Session {

	/** Whether this session has been closed (invalidated). */
	private bool $closed = false;

	/** Whether data has been modified since the last successful save. */
	private bool $dirty = false;

	/**
	 * @param string    $id       Unique session identifier (e.g. player UUID or name).
	 * @param array     $data     Key-value data map.
	 * @param int       $revision Monotonic revision counter (incremented after each SQL persist).
	 * @param SimpleSQL $manager  Parent manager reference for persistence operations.
	 */
	public function __construct(
		private readonly string $id,
		private array $data,
		private int $revision,
		private readonly SimpleSQL $manager,
	) {}

	/**
	 * Returns the unique session identifier.
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Retrieve a value by key with an optional default.
	 *
	 * @param string $key     The data key.
	 * @param mixed  $default Value returned if the key does not exist.
	 * @return mixed
	 */
	public function get(string $key, mixed $default = null): mixed {
		$this->assertOpen();
		return $this->data[$key] ?? $default;
	}

	/**
	 * Set a key-value pair.
	 *
	 * @param string $key   The data key.
	 * @param mixed  $value The value to store (must be YAML-safe: scalar, array, or null).
	 */
	public function set(string $key, mixed $value): void {
		$this->assertOpen();
		if (!isset($this->data[$key]) || $this->data[$key] !== $value) {
			$this->data[$key] = $value;
			$this->dirty = true;
		}
	}

	/**
	 * Remove a key from the data map.
	 */
	public function remove(string $key): void {
		$this->assertOpen();
		if (isset($this->data[$key]) || array_key_exists($key, $this->data)) {
			unset($this->data[$key]);
			$this->dirty = true;
		}
	}

	/**
	 * Check whether a key exists in the data map.
	 */
	public function has(string $key): bool {
		$this->assertOpen();
		return array_key_exists($key, $this->data);
	}

	/**
	 * Return all data as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function getAll(): array {
		$this->assertOpen();
		return $this->data;
	}

	/**
	 * Replace all data at once.
	 *
	 * @param array<string, mixed> $data
	 */
	public function setAll(array $data): void {
		$this->assertOpen();
		$this->data = $data;
		$this->dirty = true;
	}

	/**
	 * Returns the current revision number.
	 * Revisions are incremented strictly after successful SQL persistence.
	 */
	public function getRevision(): int {
		return $this->revision;
	}

	/**
	 * Whether the data has been modified since the last save.
	 */
	public function isDirty(): bool {
		return $this->dirty;
	}

	/**
	 * Whether this session has been closed.
	 */
	public function isClosed(): bool {
		return $this->closed;
	}

	/**
	 * Trigger an asynchronous save (SQL first, then YAML mirror).
	 * No-op if the data has not been modified.
	 *
	 * @param callable|null $onComplete Callback: fn(bool $success): void
	 */
	public function save(?callable $onComplete = null): void {
		$this->assertOpen();
		if (!$this->dirty) {
			if ($onComplete !== null) {
				$onComplete(true);
			}
			return;
		}
		$this->manager->saveSession($this, $onComplete);
	}

	/**
	 * Close this session, releasing all held data.
	 * An implicit save is triggered if the session is dirty.
	 *
	 * @param callable|null $onComplete Callback: fn(): void
	 */
	public function close(?callable $onComplete = null): void {
		$this->assertOpen();
		$this->manager->closeSession($this->id, $onComplete);
	}

	// ──────────────────────────────────────────────────────────
	//  Internal methods - called by SimpleSQL manager only.
	// ──────────────────────────────────────────────────────────

	/**
	 * @internal Update the revision after a successful SQL persist.
	 */
	public function _setRevision(int $revision): void {
		$this->revision = $revision;
	}

	/**
	 * @internal Mark the session as clean (no pending changes).
	 */
	public function _markClean(): void {
		$this->dirty = false;
	}

	/**
	 * @internal Mark the session as closed and release data to prevent memory leaks.
	 */
	public function _markClosed(): void {
		$this->closed = true;
		$this->data = [];
	}

	/**
	 * @internal Access the raw data array for persistence.
	 * @return array<string, mixed>
	 */
	public function _getData(): array {
		return $this->data;
	}

	/**
	 * Throws if the session is closed.
	 */
	private function assertOpen(): void {
		if ($this->closed) {
			throw new RuntimeException("Session '{$this->id}' has been closed");
		}
	}
}
