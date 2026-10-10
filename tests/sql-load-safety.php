<?php

declare(strict_types=1);

use NhanAZ\SimpleSQL\PendingSession;
use NhanAZ\SimpleSQL\Session;
use NhanAZ\SimpleSQL\SimpleSQL;
use NhanAZ\SimpleSQL\SqlRowDecoder;

require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/SimpleSQL.php";
require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/PendingSession.php";
require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/Session.php";
require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/SqlRowDecoder.php";

final class AbortProbe extends SimpleSQL {
	/** @var list<string> */
	public array $aborted = [];
	/** @var list<Session> */
	public array $created = [];
	/** @var list<array{data: array, revision: int}> */
	public array $yamlWrites = [];
	public ?Closure $sqlCompletion = null;

	public function __construct() {
	}

	public function _abortPending(string $id, PendingSession $pending): void {
		$this->aborted[] = $id;
	}

	public function _persistToSql(string $id, array $data, int $revision, Closure $onComplete): void {
		$this->sqlCompletion = $onComplete;
	}

	public function _createSession(string $id, array $data, int $revision): Session {
		$session = new Session($id, $data, $revision, $this);
		$this->created[] = $session;
		return $session;
	}

	public function _persistToYaml(string $id, array $data, int $revision): void {
		$this->yamlWrites[] = ["data" => $data, "revision" => $revision];
	}
}

$valid = SqlRowDecoder::decode(["data" => '{"coins":25}', "revision" => "3"]);
if ($valid !== ["data" => ["coins" => 25], "revision" => 3]) {
	throw new RuntimeException("A valid SQL row was not decoded correctly.");
}

foreach ([
	["data" => "broken", "revision" => 3],
	["data" => "null", "revision" => 3],
	["data" => '{"coins":25}', "revision" => -1],
	["data" => '{"coins":25}', "revision" => "invalid"],
	["revision" => 3],
] as $row) {
	try {
		SqlRowDecoder::decode($row);
		throw new RuntimeException("Invalid SQL row was accepted.");
	} catch (UnexpectedValueException) {
	}
}

foreach ([true, false] as $yamlFirst) {
	$manager = new AbortProbe();
	$ready = false;
	$error = null;
	$pending = new PendingSession(
		"player",
		$manager,
		function () use (&$ready): void { $ready = true; },
		function (string $reason) use (&$error): void { $error = $reason; }
	);
	if ($yamlFirst) {
		$pending->onYamlLoaded(["coins" => 99], 9, false, false);
	}
	$pending->onLoadFailed("read failed");
	if (!$yamlFirst) {
		$pending->onYamlLoaded(["coins" => 99], 9, false, false);
	}
	$pending->onSqlLoaded(null, 0);
	if ($ready || !$pending->isCancelled() || $manager->aborted !== ["player"] || $error !== "read failed") {
		throw new RuntimeException("Failed SQL read activated or retained a session.");
	}
}

$manager = new AbortProbe();
$ready = false;
$error = null;
$pending = new PendingSession(
	"player",
	$manager,
	function () use (&$ready): void { $ready = true; },
	function (string $reason) use (&$error): void { $error = $reason; }
);
$pending->onSqlLoaded(null, 0);
$pending->onYamlLoaded(null, 0, false, true);
if ($ready || !$pending->isCancelled() || $error !== "YAML is corrupt and SQL has no row to recover from.") {
	throw new RuntimeException("Corrupt YAML and missing SQL row opened an empty session.");
}

$reflection = new ReflectionClass(SimpleSQL::class);
$reflection->getProperty("yamlDataPath")->setValue($manager, "data" . DIRECTORY_SEPARATOR);
$getYamlPath = $reflection->getMethod("getYamlPath");
foreach (["../outside", "CON", "player"] as $id) {
	$path = $getYamlPath->invoke($manager, $id);
	if (!is_string($path) || !str_starts_with($path, "data" . DIRECTORY_SEPARATOR) || str_contains($path, "../") || str_contains($path, "..\\") || rawurldecode(pathinfo($path, PATHINFO_FILENAME)) !== $id) {
		throw new RuntimeException("A session ID escaped the YAML data directory.");
	}
}

foreach ([true, false] as $yamlFirst) {
	foreach ([true, false] as $sqlSyncSucceeds) {
		$manager = new AbortProbe();
		$ready = null;
		$error = null;
		$pending = new PendingSession(
			"player",
			$manager,
			function (Session $session) use (&$ready): void { $ready = $session; },
			function (string $reason) use (&$error): void { $error = $reason; }
		);
		if ($yamlFirst) {
			$pending->onYamlLoaded(["coins" => 99], 9, false, false);
			$pending->onSqlLoaded(["coins" => 25], 3);
		} else {
			$pending->onSqlLoaded(["coins" => 25], 3);
			$pending->onYamlLoaded(["coins" => 99], 9, false, false);
		}
		if ($manager->sqlCompletion === null || $ready !== null || $manager->created !== []) {
			throw new RuntimeException("Session became active before YAML-to-SQL synchronization completed.");
		}
		($manager->sqlCompletion)($sqlSyncSucceeds);
		if ($sqlSyncSucceeds) {
			if (!($ready instanceof Session) || $ready->get("coins") !== 99 || $ready->getRevision() !== 9 || $error !== null) {
				throw new RuntimeException("Successful SQL sync did not create the expected session.");
			}
		} elseif ($ready !== null || $manager->created !== [] || !$pending->isCancelled() || $error !== "SQL synchronization failed.") {
			throw new RuntimeException("Failed SQL sync exposed an unsynchronized session.");
		}
	}
}

echo "PASS: SQL row validation, load failure barrier and synchronization gate\n";
