<?php

declare(strict_types=1);

use poggit\libasynql\generic\GenericStatementFileParser;

$libasynqlSource = dirname(__DIR__) . "/.devtools-dependencies/libasynql/libasynql/src/poggit/libasynql/";
if (!is_dir($libasynqlSource)) throw new RuntimeException("Pinned libasynql source is required to validate SQL query files");
spl_autoload_register(static function (string $class) use ($libasynqlSource): void {
	$prefix = "poggit\\libasynql\\";
	if (!str_starts_with($class, $prefix)) return;
	$path = $libasynqlSource . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
	if (is_file($path)) require $path;
});

foreach (["sqlite", "mysql"] as $driver) {
	$path = dirname(__DIR__) . "/resources/simplesql/{$driver}.sql";
	$handle = fopen($path, "rb");
	if ($handle === false) throw new RuntimeException("Could not open {$driver} SQL resource");
	$parser = new GenericStatementFileParser($path, $handle);
	$parser->parse();
	$queries = $parser->getResults();
	if (!isset($queries["simplesql.pair_engine"], $queries["simplesql.save"], $queries["simplesql.save_pair"]) ||
		count($queries["simplesql.save_pair"]->getVariables()) !== 6 ||
		count($queries["simplesql.save"]->getQuery()) !== 2 ||
		count($queries["simplesql.save_pair"]->getQuery()) !== 2) {
		throw new RuntimeException("Pinned libasynql did not recognize {$driver} paired-save queries");
	}
}

function statement(string $driver, string $name): string {
	$source = file_get_contents(dirname(__DIR__) . "/resources/simplesql/{$driver}.sql");
	if ($source === false) throw new RuntimeException("Could not read {$driver} SQL resource");
	$source = str_replace("\r\n", "\n", $source);
	$start = strpos($source, "-- #{ {$name}\n");
	if ($start === false) throw new RuntimeException("Missing {$name} query in {$driver} SQL resource");
	$end = strpos($source, "-- #}", $start);
	if ($end === false) throw new RuntimeException("Unclosed {$name} query in {$driver} SQL resource");
	$lines = explode("\n", substr($source, $start, $end - $start));
	if ($name === "save" || $name === "save_pair") {
		$separator = array_search("-- #&", array_map("trim", $lines), true);
		if ($separator === false) throw new RuntimeException("Missing checked-save delimiter in {$driver} SQL resource");
		$lines = array_slice($lines, 0, $separator);
	}
	$query = implode("\n", array_filter($lines, static fn(string $line): bool => !str_starts_with(ltrim($line), "--")));
	if (trim($query) === "") throw new RuntimeException("Empty {$name} query in {$driver} SQL resource");
	return $query;
}

function readRows(PDO $db): array {
	$rows = $db->query("SELECT id, data, revision FROM simplesql_data ORDER BY id")?->fetchAll(PDO::FETCH_ASSOC);
	if (!is_array($rows)) throw new RuntimeException("Could not read paired-save rows");
	return $rows;
}

function verifyAtomicPair(PDO $db, string $driver): void {
	$db->exec(statement($driver, "init"));
	$engineQuery = $db->query(statement($driver, "pair_engine"));
	$engineRow = $engineQuery === false ? false : $engineQuery->fetch(PDO::FETCH_ASSOC);
	$engine = is_array($engineRow) ? ($engineRow["engine"] ?? null) : null;
	if (($driver === "sqlite" && $engine !== "sqlite") || ($driver === "mysql" && $engine !== "InnoDB")) {
		throw new RuntimeException("Unexpected {$driver} paired-save engine: " . var_export($engine, true));
	}
	$query = $db->prepare(statement($driver, "save_pair"));
	$query->execute([
		"first_id" => "sender", "first_data" => '{"balance":10}', "first_revision" => 1,
		"second_id" => "recipient", "second_data" => '{"balance":2}', "second_revision" => 1,
	]);
	$query->execute([
		"first_id" => "sender", "first_data" => '{"balance":7}', "first_revision" => 2,
		"second_id" => "recipient", "second_data" => '{"balance":5}', "second_revision" => 2,
	]);
	$expected = [
		["id" => "recipient", "data" => '{"balance":5}', "revision" => 2],
		["id" => "sender", "data" => '{"balance":7}', "revision" => 2],
	];
	$before = readRows($db);
	foreach ($before as $index => $row) {
		if (($row["id"] ?? null) !== $expected[$index]["id"] || ($row["data"] ?? null) !== $expected[$index]["data"] ||
			(int) ($row["revision"] ?? -1) !== $expected[$index]["revision"]) {
			throw new RuntimeException("{$driver} paired save did not update both accounts");
		}
	}

	if ($driver === "sqlite") {
		$db->exec("CREATE TRIGGER reject_recipient BEFORE INSERT ON simplesql_data WHEN NEW.id = 'recipient' BEGIN SELECT RAISE(ABORT, 'recipient write rejected'); END");
	} else {
		$db->exec("CREATE TRIGGER reject_recipient BEFORE INSERT ON simplesql_data FOR EACH ROW BEGIN IF NEW.id = 'recipient' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient write rejected'; END IF; END");
	}
	try {
		$query->execute([
			"first_id" => "sender", "first_data" => '{"balance":0}', "first_revision" => 3,
			"second_id" => "recipient", "second_data" => '{"balance":12}', "second_revision" => 3,
		]);
		throw new RuntimeException("{$driver} accepted the injected second-row failure");
	} catch (PDOException $error) {
		if (!str_contains($error->getMessage(), "recipient write rejected")) throw $error;
	}
	if (readRows($db) !== $before) {
		throw new RuntimeException("{$driver} persisted only one side after the second-row failure");
	}
	$db->exec("DROP TRIGGER reject_recipient");
}

$sqlite = new PDO("sqlite::memory:", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
verifyAtomicPair($sqlite, "sqlite");
echo "PASS: SQLite paired write and second-row rollback\n";

$mysqlDsn = getenv("SIMPLESQL_MYSQL_DSN");
if (is_string($mysqlDsn) && $mysqlDsn !== "") {
	$mysql = new PDO($mysqlDsn, getenv("SIMPLESQL_MYSQL_USER") ?: "root", getenv("SIMPLESQL_MYSQL_PASSWORD") ?: "", [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
	verifyAtomicPair($mysql, "mysql");
	echo "PASS: MySQL InnoDB paired write and second-row rollback\n";
}
