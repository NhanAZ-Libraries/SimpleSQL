<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL\task;

use Closure;
use pocketmine\scheduler\AsyncTask;

/**
 * AsyncTask that atomically writes YAML data to disk.
 *
 * Runs entirely on a worker thread (S1 compliant — no main thread I/O).
 *
 * Write Strategy (Atomic + Windows-safe):
 *   1. Write to .tmp file with LOCK_EX.
 *   2. Attempt rename(.tmp → .yml) — atomic on most filesystems.
 *   3. If rename fails (Windows lock): copy(.tmp → .yml) + unlink(.tmp).
 *   4. If all fail: direct file_put_contents as last resort.
 */
class YamlWriteTask extends AsyncTask {

	/**
	 * Data serialized for cross-thread transfer.
	 * Using PHP serialize for full type fidelity (closures stay via storeLocal).
	 */
	private readonly string $serializedData;

	/** Whether the write succeeded. */
	private bool $success = false;

	/** Error message if the write failed. */
	private string $error = "";

	/**
	 * @param string  $filePath   Absolute path to the target .yml file.
	 * @param array   $data       Data map to write.
	 * @param int     $revision   Revision to embed in the YAML document.
	 * @param Closure $onComplete Callback: fn(bool $success, string $error): void
	 */
	public function __construct(
		private readonly string $filePath,
		array $data,
		private readonly int $revision,
		Closure $onComplete,
	) {
		$this->serializedData = serialize($data);
		$this->storeLocal("onComplete", $onComplete);
	}

	public function onRun(): void {
		// ── Deserialize data ──
		/** @var array $data */
		$data = unserialize($this->serializedData);

		// ── Build YAML document ──
		$yamlDocument = [
			"revision" => $this->revision,
			"data" => $data,
		];

		$yamlContent = @yaml_emit($yamlDocument, YAML_UTF8_ENCODING);
		if ($yamlContent === false) {
			// Extreme fallback: shouldn't happen with valid data
			$this->success = false;
			$this->error = "yaml_emit() failed for: " . $this->filePath;
			return;
		}

		// ── Ensure directory exists (sharding creates subdirectories) ──
		$dir = dirname($this->filePath);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		// ── Strategy 1: Atomic write via .tmp + rename ──
		$tmpPath = $this->filePath . ".tmp";

		$written = @file_put_contents($tmpPath, $yamlContent, LOCK_EX);
		if ($written === false) {
			// Temp write failed — try direct write
			$this->directWrite($yamlContent);
			return;
		}

		// Attempt atomic rename
		if (@rename($tmpPath, $this->filePath)) {
			$this->success = true;
			return;
		}

		// ── Strategy 2: Windows fallback — copy + unlink ──
		if (@copy($tmpPath, $this->filePath)) {
			@unlink($tmpPath);
			$this->success = true;
			return;
		}

		// ── Strategy 3: Direct write as last resort ──
		@unlink($tmpPath);
		$this->directWrite($yamlContent);
	}

	/**
	 * Direct file write without atomic guarantees (last resort).
	 */
	private function directWrite(string $content): void {
		$written = @file_put_contents($this->filePath, $content, LOCK_EX);
		$this->success = ($written !== false);
		if (!$this->success) {
			$this->error = "All write strategies failed for: " . $this->filePath;
		}
	}

	public function onCompletion(): void {
		/** @var Closure $onComplete */
		$onComplete = $this->fetchLocal("onComplete");
		$onComplete($this->success, $this->error);
	}
}
