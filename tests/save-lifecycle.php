<?php

declare(strict_types=1);

namespace poggit\libasynql {
	interface DataConnector {
		public function executeGeneric(string $queryName, array $args = [], ?callable $onSuccess = null, ?callable $onError = null): void;
		public function waitAll(): void;
		public function close(): void;
	}
	class SqlError extends \RuntimeException {}
}

namespace {
	use NhanAZ\SimpleSQL\Session;
	use NhanAZ\SimpleSQL\SimpleSQL;
	use NhanAZ\SimpleSQL\WriteScheduler;
	use poggit\libasynql\DataConnector;
	use poggit\libasynql\SqlError;

	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/SimpleSQL.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/Session.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/WriteState.php";
	require dirname(__DIR__) . "/src/NhanAZ/SimpleSQL/WriteScheduler.php";

	final class DeferredSql implements DataConnector {
		/** @var list<array{args: array, success: callable, error: callable}> */
		public array $queries = [];
		public bool $failOnWait = false;
		private int $handled = 0;

		public function executeGeneric(string $queryName, array $args = [], ?callable $onSuccess = null, ?callable $onError = null): void {
			if ($onSuccess === null || $onError === null) {
				throw new \RuntimeException("Save did not provide SQL result handlers.");
			}
			$this->queries[] = ["args" => $args, "success" => $onSuccess, "error" => $onError];
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
	$session->set("coins", 3);
	$session->save();
	$session->set("coins", 4);
	($db->queries[2]["success"])();
	if (!$session->isDirty() || $session->getRevision() !== 3 || $session->get("coins") !== 4) {
		throw new \RuntimeException("An edit made during a save was marked clean without a second save request.");
	}

	$closed = false;
	$manager->closeSession("player", function () use (&$closed): void { $closed = true; });
	($db->queries[3]["error"])(new SqlError("write failed"));
	if ($closed || $session->isClosed() || !$session->isDirty() || !$manager->hasSession("player")) {
		throw new \RuntimeException("Failed SQL save discarded the active session.");
	}
	$manager->closeSession("player", function () use (&$closed): void { $closed = true; });
	($db->queries[4]["success"])();
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

	echo "PASS: serialized saves, failed close and shutdown recovery\n";
}
