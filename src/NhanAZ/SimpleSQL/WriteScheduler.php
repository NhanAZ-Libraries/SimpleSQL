<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

use Closure;

/**
 * Manages YAML write tasks with single-flight semantics and per-tick throttling.
 *
 * Each session ID has an independent state machine:
 *   IDLE    → QUEUED   (scheduleWrite called)
 *   QUEUED  → RUNNING  (dispatched by tick)
 *   RUNNING → IDLE     (completed, no coalesced data)
 *   RUNNING → QUEUED   (completed, has coalesced data waiting)
 *
 * If scheduleWrite is called while QUEUED, the data is overwritten (latest wins).
 * If scheduleWrite is called while RUNNING, the data is coalesced (stored for re-dispatch).
 *
 * A configurable dispatch limit prevents I/O spikes during mass disconnects.
 */
class WriteScheduler {

	/**
	 * Current state per session ID.
	 * @var array<string, WriteState>
	 */
	private array $states = [];

	/**
	 * Queued write payloads awaiting dispatch.
	 * @var array<string, array{data: array, revision: int}>
	 */
	private array $queued = [];

	/**
	 * Coalesced data for IDs that received a new write while RUNNING.
	 * @var array<string, array{data: array, revision: int}>
	 */
	private array $pendingCoalesce = [];

	/**
	 * @param SimpleSQL $manager     Parent manager for dispatching async tasks.
	 * @param int       $maxPerTick  Maximum number of YAML write tasks dispatched per tick.
	 */
	public function __construct(
		private readonly SimpleSQL $manager,
		private readonly int $maxPerTick = 3,
	) {}

	/**
	 * Schedule a YAML write for the given session ID.
	 *
	 * - IDLE    → enqueue and set QUEUED.
	 * - QUEUED  → overwrite pending data (latest snapshot wins).
	 * - RUNNING → store as coalesced data for re-dispatch after completion.
	 *
	 * @param string $id       Session identifier.
	 * @param array  $data     Data to write to YAML.
	 * @param int    $revision Revision number to embed in the YAML file.
	 */
	public function scheduleWrite(string $id, array $data, int $revision): void {
		$state = $this->states[$id] ?? WriteState::IDLE;

		switch ($state) {
			case WriteState::IDLE:
				$this->states[$id] = WriteState::QUEUED;
				$this->queued[$id] = ["data" => $data, "revision" => $revision];
				break;

			case WriteState::QUEUED:
				// Overwrite: latest snapshot always wins
				$this->queued[$id] = ["data" => $data, "revision" => $revision];
				break;

			case WriteState::RUNNING:
				// Task in flight: coalesce for next dispatch
				$this->pendingCoalesce[$id] = ["data" => $data, "revision" => $revision];
				break;
		}
	}

	/**
	 * Dispatch queued writes up to the per-tick limit.
	 * Called every server tick by the manager.
	 */
	public function tick(): void {
		if (count($this->queued) === 0) {
			return;
		}

		$dispatched = 0;
		foreach ($this->queued as $id => $payload) {
			if ($dispatched >= $this->maxPerTick) {
				break;
			}

			$this->states[$id] = WriteState::RUNNING;
			unset($this->queued[$id]);

			$this->manager->_dispatchYamlWrite(
				$id,
				$payload["data"],
				$payload["revision"],
				function () use ($id): void {
					$this->onWriteComplete($id);
				}
			);

			$dispatched++;
		}
	}

	/**
	 * Called when a YAML write AsyncTask completes.
	 *
	 * If coalesced data accumulated while the task was running,
	 * the ID is re-queued immediately for the next tick cycle.
	 */
	private function onWriteComplete(string $id): void {
		if (!isset($this->states[$id])) {
			// Session was cancelled while the write was in flight — ignore.
			unset($this->pendingCoalesce[$id]);
			return;
		}

		if (isset($this->pendingCoalesce[$id])) {
			// Re-queue with the latest coalesced data
			$this->queued[$id] = $this->pendingCoalesce[$id];
			$this->states[$id] = WriteState::QUEUED;
			unset($this->pendingCoalesce[$id]);
		} else {
			$this->states[$id] = WriteState::IDLE;
		}
	}

	/**
	 * Cancel all pending/coalesced writes for the given ID.
	 * Does NOT cancel an already-running AsyncTask (it will complete and be ignored).
	 */
	public function cancel(string $id): void {
		unset($this->states[$id], $this->queued[$id], $this->pendingCoalesce[$id]);
	}

	/**
	 * Returns the number of IDs with pending or in-flight writes.
	 */
	public function getPendingCount(): int {
		$running = 0;
		foreach ($this->states as $state) {
			if ($state === WriteState::RUNNING) {
				$running++;
			}
		}
		return count($this->queued) + $running;
	}

	/**
	 * Whether the given ID has any pending, coalesced, or in-flight write.
	 */
	public function hasPendingWrite(string $id): bool {
		return isset($this->states[$id]) && $this->states[$id] !== WriteState::IDLE;
	}
}
