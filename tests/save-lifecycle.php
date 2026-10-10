<?php

declare(strict_types=1);

namespace poggit\libasynql {
	interface DataConnector {
		public function executeGeneric(string $queryName, array $args = [], ?callable $onSuccess = null, ?callable $onError = null): void;
		public function executeMulti(string $queryName, array $args, int $mode, ?callable $handler = null, ?callable $onError = null): void;
		public function waitAll(): void;
		public function close(): void;
	}
	class SqlError extends \RuntimeException {}
	class SqlThread { public const MODE_SELECT = 3; }
}

namespace poggit\libasynql\result {
	class SqlSelectResult {
		public function __construct(private array $rows) {}
		public function getRows(): array { return $this->rows; }
	}
}

namespace {
	use NhanAZ\SimpleSQL\Session;
	use NhanAZ\SimpleSQL\SimpleSQL;
	use NhanAZ\SimpleSQL\WriteScheduler;
	use poggit\libasynql\DataConnector;
	use poggit\libasynql\SqlError;
	use poggit\libasynql\result\SqlSelectResult;

	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/SimpleSQL.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/Session.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/WriteState.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/WriteScheduler.php";

	final class DeferredSql implements DataConnector {
		/** @var list<array{name: string, args: array, success: callable, error: callable}> */
		public array $queries = [];
		public bool $failOnWait = false;
		public bool $silentlyDropWrites = false;
		/** @var array<string, array{id: string, data: string, revision: int}> */
		private array $stored = [];
		private int $handled = 0;

		public function executeGeneric(string $queryName, array $args = [], ?callable $onSuccess = null, ?callable $onError = null): void {
			if ($onSuccess === null || $onError === null) {
				throw new \RuntimeException("Save did not provide SQL result handlers.");
			}
			$this->queries[] = ["name" => $queryName, "args" => $args, "success" => $onSuccess, "error" => $onError];
		}

		public function executeMulti(string $queryName, array $args, int $mode, ?callable $handler = null, ?callable $onError = null): void {
			if ($handler === null || $onError === null) throw new \RuntimeException("Checked save did not provide result handlers.");
			$this->queries[] = ["name" => $queryName, "args" => $args, "success" => function () use ($queryName, $args, $handler): void {
				$writes = $queryName === "simplesql.save_pair" ? [
					[$args["first_id"], $args["first_data"], $args["first_revision"]],
					[$args["second_id"], $args["second_data"], $args["second_revision"]],
				] : [[$args["id"], $args["data"], $args["revision"]]];
				if (!$this->silentlyDropWrites) {
					foreach ($writes as [$id, $data, $revision]) $this->stored[$id] = ["id" => $id, "data" => $data, "revision" => $revision];
				}
				$rows = [];
				foreach ($writes as [$id]) if (isset($this->stored[$id])) $rows[] = $this->stored[$id];
				$handler([new \stdClass(), new SqlSelectResult($rows)]);
			}, "error" => $onError];
		}

		public function waitAll(): void {
			while ($this->handled < count($this->queries)) {
				$query = $this->queries[$this->handled++];
				if ($this->failOnWait) {
					($query["error"])(new SqlError("database unavailable"));
				} else {
					($query["success"])();
				}
			}
		}

		public function close(): void {}
	}

	$reflection = new \ReflectionClass(SimpleSQL::class);
	/** @var SimpleSQL $manager */
	$manager = $reflection->newInstanceWithoutConstructor();
	$db = new DeferredSql();
	$reflection->getProperty("db")->setValue($manager, $db);
	$reflection->getProperty("logger")->setValue($manager, null);
	$reflection->getProperty("writeScheduler")->setValue($manager, new WriteScheduler($manager));
	$session = new Session("player", [], 0, $manager);
	$reflection->getProperty("sessions")->setValue($manager, ["player" => $session]);

	$results = [];
	$session->set("coins", 1);
	$session->save(function (bool $success) use (&$results): void { $results[] = $success; });
	$session->set("coins", 2);
	$session->save(function (bool $success) use (&$results): void { $results[] = $success; });
	if (count($db->queries) !== 1 || $db->queries[0]["args"]["data"] !== '{"coins":1}') {
		throw new \RuntimeException("Overlapping saves were dispatched.");
	}
	($db->queries[0]["success"])();
	if (count($db->queries) !== 2 || $db->queries[1]["args"]["data"] !== '{"coins":2}' || $db->queries[1]["args"]["revision"] !== 2 || !$session->isDirty()) {
		throw new \RuntimeException("A newer mutation was lost after the first SQL write.");
	}
	($db->queries[1]["success"])();
	if ($session->isDirty() || $session->getRevision() !== 2 || $results !== [true, true]) {
		throw new \RuntimeException("Serialized saves did not finish cleanly.");
	}
	$db->silentlyDropWrites = true;
	$session->set("coins", 3);
	$session->save(function (bool $success) use (&$results): void { $results[] = $success; });
	($db->queries[2]["success"])();
	if ($results !== [true, true, false] || !$session->isDirty() || $session->getRevision() !== 2) {
		throw new \RuntimeException("Readback accepted a connector that reported an unwritten save as success.");
	}
	$db->silentlyDropWrites = false;
	$session->set("coins", 3);
	$session->save();
	$session->set("coins", 4);
	($db->queries[3]["success"])();
	if (!$session->isDirty() || $session->getRevision() !== 3 || $session->get("coins") !== 4) {
		throw new \RuntimeException("An edit made during a save was marked clean without a second save request.");
	}

	$closed = false;
	$manager->closeSession("player", function () use (&$closed): void { $closed = true; });
	($db->queries[4]["error"])(new SqlError("write failed"));
	if ($closed || $session->isClosed() || !$session->isDirty() || !$manager->hasSession("player")) {
		throw new \RuntimeException("Failed SQL save discarded the active session.");
	}
	$manager->closeSession("player", function () use (&$closed): void { $closed = true; });
	($db->queries[5]["success"])();
	if (!$closed || !$session->isClosed() || $manager->hasSession("player")) {
		throw new \RuntimeException("Successful retry did not close the session.");
	}

	/** @var SimpleSQL $shutdownManager */
	$shutdownManager = $reflection->newInstanceWithoutConstructor();
	$failedDb = new DeferredSql();
	$failedDb->failOnWait = true;
	$recoveryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "simplesql-recovery-" . bin2hex(random_bytes(6));
	$reflection->getProperty("db")->setValue($shutdownManager, $failedDb);
	$reflection->getProperty("logger")->setValue($shutdownManager, null);
	$reflection->getProperty("ownsDb")->setValue($shutdownManager, false);
	$reflection->getProperty("yamlDataPath")->setValue($shutdownManager, $recoveryRoot . DIRECTORY_SEPARATOR);
	$reflection->getProperty("writeScheduler")->setValue($shutdownManager, new WriteScheduler($shutdownManager));
	$unsaved = new Session("player", ["coins" => 10], 7, $shutdownManager);
	$unsaved->set("coins", 42);
	$reflection->getProperty("sessions")->setValue($shutdownManager, ["player" => $unsaved]);
	$shutdownManager->close();
	$files = glob($recoveryRoot . DIRECTORY_SEPARATOR . "p" . DIRECTORY_SEPARATOR . "player.yml.*.recovery.yml");
	if (!is_array($files) || count($files) !== 1) {
		throw new \RuntimeException("Failed shutdown SQL save did not create a recovery snapshot.");
	}
	$recovered = yaml_parse_file($files[0]);
	if (!is_array($recovered) || $recovered["data"]["coins"] !== 42 || $recovered["revision"] !== 8) {
		throw new \RuntimeException("Shutdown recovery snapshot did not retain the latest data.");
	}
	unlink($files[0]);
	rmdir(dirname($files[0]));
	rmdir($recoveryRoot);

	/** @var SimpleSQL $pairManager */
	$pairManager = $reflection->newInstanceWithoutConstructor();
	$pairDb = new DeferredSql();
	$reflection->getProperty("db")->setValue($pairManager, $pairDb);
	$reflection->getProperty("logger")->setValue($pairManager, null);
	$reflection->getProperty("writeScheduler")->setValue($pairManager, new WriteScheduler($pairManager));
	$reflection->getProperty("atomicPairAvailable")->setValue($pairManager, true);
	$sender = new Session("sender", ["balance" => 10], 2, $pairManager);
	$recipient = new Session("recipient", ["balance" => 2], 5, $pairManager);
	$reflection->getProperty("sessions")->setValue($pairManager, ["sender" => $sender, "recipient" => $recipient]);
	$pairResults = [];
	$pairManager->saveSessionPair($sender, ["balance" => 7], $recipient, ["balance" => 5], function (bool $success) use (&$pairResults): void { $pairResults[] = $success; });
	if (count($pairDb->queries) !== 1 || $pairDb->queries[0]["name"] !== "simplesql.save_pair" ||
		$pairDb->queries[0]["args"]["first_data"] !== '{"balance":7}' ||
		$pairDb->queries[0]["args"]["second_data"] !== '{"balance":5}' ||
		$sender->get("balance") !== 10 || $recipient->get("balance") !== 2) {
		throw new \RuntimeException("Paired save changed visible balances before SQL confirmed both rows.");
	}
	try {
		$sender->set("balance", 99);
		throw new \RuntimeException("Paired save allowed a concurrent mutation.");
	} catch (\RuntimeException $error) {
		if (!str_contains($error->getMessage(), "cannot be changed")) throw $error;
	}
	$pairManager->saveSessionPair($sender, ["balance" => 6], $recipient, ["balance" => 6], function (bool $success) use (&$pairResults): void { $pairResults[] = $success; });
	if ($pairResults !== [false] || count($pairDb->queries) !== 1) {
		throw new \RuntimeException("Overlapping paired save was not rejected.");
	}
	($pairDb->queries[0]["success"])();
	if ($pairResults !== [false, true] || $sender->get("balance") !== 7 || $recipient->get("balance") !== 5 ||
		$sender->getRevision() !== 3 || $recipient->getRevision() !== 6 || $sender->isDirty() || $recipient->isDirty()) {
		throw new \RuntimeException("Successful paired save did not commit both sessions together.");
	}
	$queuedMirrors = (new \ReflectionProperty(WriteScheduler::class, "queued"))->getValue($reflection->getProperty("writeScheduler")->getValue($pairManager));
	if (count($queuedMirrors) !== 2 || $queuedMirrors["sender"]["revision"] !== 3 || $queuedMirrors["recipient"]["revision"] !== 6) {
		throw new \RuntimeException("Successful paired save did not queue both YAML mirrors.");
	}
	$pairManager->saveSessionPair($sender, ["balance" => 4], $recipient, ["balance" => 8], function (bool $success) use (&$pairResults): void { $pairResults[] = $success; });
	($pairDb->queries[1]["error"])(new SqlError("second write rejected"));
	if ($pairResults !== [false, true, false] || $sender->get("balance") !== 7 || $recipient->get("balance") !== 5 ||
		$sender->getRevision() !== 3 || $recipient->getRevision() !== 6) {
		throw new \RuntimeException("Failed paired save exposed or committed a partial transfer.");
	}
	$pairDb->silentlyDropWrites = true;
	$pairManager->saveSessionPair($sender, ["balance" => 4], $recipient, ["balance" => 8], function (bool $success) use (&$pairResults): void { $pairResults[] = $success; });
	($pairDb->queries[2]["success"])();
	if ($pairResults !== [false, true, false, false] || $sender->get("balance") !== 7 || $recipient->get("balance") !== 5) {
		throw new \RuntimeException("Readback accepted a connector that reported an unwritten pair as success.");
	}
	$sender->set("balance", 8);
	$recipient->set("balance", 4);
	$reflection->getProperty("atomicPairAvailable")->setValue($pairManager, false);
	$pairManager->saveSessionPair($sender, ["balance" => 7], $recipient, ["balance" => 5], function (bool $success) use (&$pairResults): void { $pairResults[] = $success; });
	if ($pairResults !== [false, true, false, false, false] || count($pairDb->queries) !== 3) {
		throw new \RuntimeException("Unsupported database engine was allowed to run a paired save.");
	}

	echo "PASS: serialized saves, paired saves, failed close and shutdown recovery\n";
}
