<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL\task;

use Closure;
use pocketmine\scheduler\AsyncTask;

/**
 * AsyncTask that loads and parses a YAML file from disk.
 *
 * Runs entirely on a worker thread (S1 compliant - no main thread I/O).
 *
 * Handles:
 *   - Missing files (returns missing=true).
 *   - Corrupt YAML (renames to .broken with timestamp, returns corrupt=true).
 *   - Valid YAML (extracts 'data' and 'revision' fields).
 */
class YamlLoadTask extends AsyncTask {

	/**
	 * Serialized result string (set in onRun, read in onCompletion).
	 * Format: serialized array with keys: missing, corrupt, data, revision, reason.
	 */
	private string $resultSerialized = "";

	/**
	 * @param string  $filePath  Absolute path to the YAML file.
	 * @param string  $sessionId Session identifier (passed through to callback).
	 * @param Closure $onComplete Callback: fn(string $sessionId, array $result): void
	 */
	public function __construct(
		private readonly string $filePath,
		private readonly string $sessionId,
		Closure $onComplete,
	) {
		$this->storeLocal("onComplete", $onComplete);
	}

	public function onRun(): void {
		// ── Check existence ──
		if (!file_exists($this->filePath)) {
			$this->resultSerialized = serialize([
				"missing" => true,
				"corrupt" => false,
				"data" => null,
				"revision" => 0,
			]);
			return;
		}

		// ── Read file content ──
		$content = @file_get_contents($this->filePath);
		if ($content === false || trim($content) === "") {
			$this->handleCorruption("File is unreadable or empty");
			return;
		}

		// ── Parse YAML ──
		$parsed = @yaml_parse($content);
		if (!is_array($parsed)) {
			$this->handleCorruption("Invalid YAML syntax");
			return;
		}

		// ── Validate structure ──
		$revision = (int) ($parsed["revision"] ?? 0);
		$data = $parsed["data"] ?? [];

		if (!is_array($data)) {
			$this->handleCorruption("'data' field is not an array");
			return;
		}

		$this->resultSerialized = serialize([
			"missing" => false,
			"corrupt" => false,
			"data" => $data,
			"revision" => $revision,
		]);
	}

	/**
	 * Handle a corrupt YAML file:
	 * 1. Rename to .broken with timestamp for forensic analysis.
	 * 2. Return corrupt result so the caller falls back to SQL.
	 */
	private function handleCorruption(string $reason): void {
		$brokenPath = $this->filePath . "." . time() . ".broken";
		@rename($this->filePath, $brokenPath);

		$this->resultSerialized = serialize([
			"missing" => false,
			"corrupt" => true,
			"data" => null,
			"revision" => 0,
			"reason" => $reason,
		]);
	}

	public function onCompletion(): void {
		/** @var array $result */
		$result = unserialize($this->resultSerialized);
		/** @var Closure $onComplete */
		$onComplete = $this->fetchLocal("onComplete");
		$onComplete($this->sessionId, $result);
	}
}
