<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use Closure;
use NhanAZ\SimpleEconomy\command\AddMoneyCommand;
use NhanAZ\SimpleEconomy\LangManager;
use NhanAZ\SimpleEconomy\command\MoneyCommand;
use NhanAZ\SimpleEconomy\command\PayCommand;
use NhanAZ\SimpleEconomy\command\ReduceMoneyCommand;
use NhanAZ\SimpleEconomy\command\SetMoneyCommand;
use NhanAZ\SimpleEconomy\command\TopMoneyCommand;
use NhanAZ\SimpleSQL\Session;
use NhanAZ\SimpleSQL\SimpleSQL;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * SimpleEconomy — A production-ready economy plugin powered by SimpleSQL.
 *
 * Features:
 *   - Simple API for other plugins (getMoney, setMoney, addMoney, reduceMoney)
 *   - Async API for offline player data (getMoneyAsync)
 *   - Leaderboard with YAML-based cache (works across restarts)
 *   - Name prefix matching for commands
 *   - Offline player support via temporary sessions
 */
class Main extends PluginBase implements Listener {

	private static ?self $instance = null;

	private SimpleSQL $simpleSQL;
	private LangManager $lang;
	private int $defaultBalance;
	private string $currencySymbol;
	private int $topmoneyPerPage;

	/** @var array<string, int> lowercased name => balance, sorted desc */
	private array $balanceCache = [];

	// ──────────────────────────────────────────────
	//  Static accessor
	// ──────────────────────────────────────────────

	/**
	 * Get the SimpleEconomy plugin instance.
	 *
	 * Usage from another plugin:
	 *   $eco = SimpleEconomy::getInstance();
	 *   $balance = $eco?->getMoney("Steve");
	 */
	public static function getInstance(): ?self {
		return self::$instance;
	}

	// ──────────────────────────────────────────────
	//  Plugin lifecycle
	// ──────────────────────────────────────────────

	protected function onEnable(): void {
		self::$instance = $this;

		$this->saveDefaultConfig();
		$this->defaultBalance = (int) $this->getConfig()->get("default-balance", 1000);
		$this->currencySymbol = (string) $this->getConfig()->get("currency-symbol", "$");
		$this->topmoneyPerPage = (int) $this->getConfig()->get("topmoney-per-page", 10);

		// Language
		$language = (string) $this->getConfig()->get("language", "en");
		$this->lang = new LangManager($this, $language);

		$this->simpleSQL = SimpleSQL::create(
			plugin: $this,
			dbConfig: $this->getConfig()->get("database"),
		);

		// Pre-populate leaderboard cache from YAML mirror files
		$this->scanYamlFiles();

		// Events
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		// Commands
		$map = $this->getServer()->getCommandMap();
		$map->register("simpleeconomy", new MoneyCommand($this));
		$map->register("simpleeconomy", new PayCommand($this));
		$map->register("simpleeconomy", new SetMoneyCommand($this));
		$map->register("simpleeconomy", new AddMoneyCommand($this));
		$map->register("simpleeconomy", new ReduceMoneyCommand($this));
		$map->register("simpleeconomy", new TopMoneyCommand($this));

		$this->getLogger()->info("SimpleEconomy enabled.");
	}

	protected function onDisable(): void {
		self::$instance = null;
		if (isset($this->simpleSQL)) {
			$this->simpleSQL->close();
		}
	}

	// ──────────────────────────────────────────────
	//  Event handlers
	// ──────────────────────────────────────────────

	public function onJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$name = strtolower($player->getName());

		$this->simpleSQL->openSession($name, function (Session $session) use ($name): void {
			if (!$session->has("balance")) {
				$session->set("balance", $this->defaultBalance);
				$session->save();
			}

			$balance = (int) $session->get("balance", 0);
			$this->updateBalanceCache($name, $balance);
		});
	}

	public function onQuit(PlayerQuitEvent $event): void {
		$name = strtolower($event->getPlayer()->getName());
		$this->simpleSQL->closeSession($name);
	}

	// ──────────────────────────────────────────────
	//  Public API — Synchronous (online players only)
	// ──────────────────────────────────────────────

	/**
	 * Get a player's balance.
	 * Returns null if the player is offline or session is not loaded.
	 */
	public function getMoney(string $name): ?int {
		$session = $this->simpleSQL->getSession(strtolower($name));
		if ($session === null) {
			return null;
		}
		return (int) $session->get("balance", 0);
	}

	/**
	 * Set a player's balance.
	 * Returns false if the player is offline.
	 */
	public function setMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}
		$session->set("balance", $amount);
		$session->save();
		$this->updateBalanceCache($lower, $amount);
		return true;
	}

	/**
	 * Add money to a player's balance.
	 * Returns false if the player is offline.
	 */
	public function addMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}
		$newBalance = (int) $session->get("balance", 0) + $amount;
		$session->set("balance", $newBalance);
		$session->save();
		$this->updateBalanceCache($lower, $newBalance);
		return true;
	}

	/**
	 * Reduce money from a player's balance.
	 * Returns false if the player is offline or has insufficient funds.
	 */
	public function reduceMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}
		$balance = (int) $session->get("balance", 0);
		if ($balance < $amount) {
			return false;
		}
		$newBalance = $balance - $amount;
		$session->set("balance", $newBalance);
		$session->save();
		$this->updateBalanceCache($lower, $newBalance);
		return true;
	}

	// ──────────────────────────────────────────────
	//  Public API — Asynchronous (online + offline)
	// ──────────────────────────────────────────────

	/**
	 * Get a player's balance asynchronously. Works for both online and offline players.
	 *
	 * @param Closure(?int): void $callback — receives the balance, or null if the player has never played.
	 */
	public function getMoneyAsync(string $name, Closure $callback): void {
		$lower = strtolower($name);

		// Online — instant
		if ($this->simpleSQL->hasSession($lower)) {
			$session = $this->simpleSQL->getSession($lower);
			$callback($session !== null ? (int) $session->get("balance", 0) : null);
			return;
		}

		// Currently loading — return from cache if available
		if ($this->simpleSQL->isLoading($lower)) {
			$callback($this->balanceCache[$lower] ?? null);
			return;
		}

		// Offline — open temporary session
		$this->simpleSQL->openSession($lower, function (Session $session) use ($lower, $callback): void {
			$balance = $session->has("balance") ? (int) $session->get("balance", 0) : null;
			$callback($balance);
			$this->simpleSQL->closeSession($lower);
		});
	}

	// ──────────────────────────────────────────────
	//  Helpers for commands
	// ──────────────────────────────────────────────

	/**
	 * Resolve an online player by name prefix (case-insensitive).
	 * Returns null if no match or ambiguous.
	 */
	public function resolvePlayer(string $input): ?Player {
		return $this->getServer()->getPlayerByPrefix($input);
	}

	/**
	 * Execute a callback with a player's session.
	 *
	 * - For online players: uses the existing session, $temporary = false.
	 * - For offline players: opens a temp session, $temporary = true.
	 *   Caller must call closeTempSession() when done with a temporary session.
	 *
	 * @param Closure(Session, bool $temporary): void $onSession
	 * @param Closure(string $errorMessage): void $onError
	 */
	public function withPlayerSession(string $name, Closure $onSession, Closure $onError): void {
		$lower = strtolower($name);

		// Already loaded (online player)
		if ($this->simpleSQL->hasSession($lower)) {
			$session = $this->simpleSQL->getSession($lower);
			if ($session !== null) {
				$onSession($session, false);
			} else {
				$onError($this->lang->get("general.data-access-error", ["player" => $name]));
			}
			return;
		}

		// Currently loading
		if ($this->simpleSQL->isLoading($lower)) {
			$onError($this->lang->get("general.data-loading", ["player" => $name]));
			return;
		}

		// Offline — open temporary session
		$this->simpleSQL->openSession($lower, function (Session $session) use ($onSession): void {
			$onSession($session, true);
		});
	}

	/**
	 * Close a temporary session, saving first if dirty.
	 */
	public function closeTempSession(string $name): void {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session !== null && $session->isDirty()) {
			$session->save(function (bool $success) use ($lower): void {
				$this->simpleSQL->closeSession($lower);
			});
		} else {
			$this->simpleSQL->closeSession($lower);
		}
	}

	// ──────────────────────────────────────────────
	//  Leaderboard cache
	// ──────────────────────────────────────────────

	/**
	 * Update the balance cache for a player and re-sort.
	 */
	public function updateBalanceCache(string $name, int $balance): void {
		$this->balanceCache[strtolower($name)] = $balance;
		arsort($this->balanceCache);
	}

	/**
	 * Get top balances for the leaderboard.
	 *
	 * @return array<int, array{name: string, balance: int}>
	 */
	public function getTopBalances(int $limit = 10, int $offset = 0): array {
		$result = [];
		$i = 0;
		foreach ($this->balanceCache as $name => $balance) {
			if ($i >= $offset + $limit) {
				break;
			}
			if ($i >= $offset) {
				$result[] = ["name" => (string) $name, "balance" => $balance];
			}
			$i++;
		}
		return $result;
	}

	/**
	 * Get total number of entries in the balance cache.
	 */
	public function getBalanceCacheCount(): int {
		return count($this->balanceCache);
	}

	/**
	 * Scan YAML mirror files on startup to pre-populate the leaderboard cache.
	 * This ensures the leaderboard is available immediately after a server restart.
	 */
	private function scanYamlFiles(): void {
		$yamlPath = $this->simpleSQL->getYamlDataPath();
		if (!is_dir($yamlPath)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($yamlPath, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== "yml") {
				continue;
			}

			$content = @file_get_contents($file->getPathname());
			if ($content === false) {
				continue;
			}

			$data = @yaml_parse($content);
			if (!is_array($data) || !isset($data["data"]["balance"])) {
				continue;
			}

			$name = strtolower(pathinfo($file->getFilename(), PATHINFO_FILENAME));
			$this->balanceCache[$name] = (int) $data["data"]["balance"];
		}

		arsort($this->balanceCache);

		if (count($this->balanceCache) > 0) {
			$this->getLogger()->info("Loaded " . count($this->balanceCache) . " balance(s) into leaderboard cache.");
		}
	}

	// ──────────────────────────────────────────────
	//  Accessors
	// ──────────────────────────────────────────────

	public function getSimpleSQL(): SimpleSQL {
		return $this->simpleSQL;
	}

	public function getLang(): LangManager {
		return $this->lang;
	}

	public function getDefaultBalance(): int {
		return $this->defaultBalance;
	}

	public function getCurrencySymbol(): string {
		return $this->currencySymbol;
	}

	public function getTopmoneyPerPage(): int {
		return $this->topmoneyPerPage;
	}

	/**
	 * Format a monetary amount with the currency symbol.
	 */
	public function formatMoney(int|float $amount): string {
		return $this->currencySymbol . number_format((float) $amount, 0, ".", ",");
	}
}
