<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use NhanAZ\SimpleEconomy\command\MoneyCommand;
use NhanAZ\SimpleEconomy\command\PayCommand;
use NhanAZ\SimpleEconomy\command\SetMoneyCommand;
use NhanAZ\SimpleSQL\Session;
use NhanAZ\SimpleSQL\SimpleSQL;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * SimpleEconomy — A lightweight economy plugin powered by SimpleSQL.
 *
 * Demonstrates the full lifecycle of the Hybrid SQL-YAML pattern:
 *   - Async session loading on join (with "loading" guard)
 *   - Key-value data access via Session
 *   - Auto-save + close on quit (memory safety)
 *   - Graceful shutdown
 */
class Main extends PluginBase implements Listener {

	/** @var SimpleSQL The SimpleSQL instance managing all economy data. */
	private SimpleSQL $simpleSQL;

	/** Default starting balance for new players. */
	private int $defaultBalance;

	/** Currency symbol for display. */
	private string $currencySymbol;

	// ──────────────────────────────────────────────
	//  Plugin lifecycle
	// ──────────────────────────────────────────────

	protected function onEnable(): void {
		// Save default config and read settings
		$this->saveDefaultConfig();
		$this->defaultBalance = (int) $this->getConfig()->get("default-balance", 1000);
		$this->currencySymbol = (string) $this->getConfig()->get("currency-symbol", "$");

		// Initialize SimpleSQL with the database config from config.yml.
		// The factory method handles libasynql setup, table creation, and the tick task.
		$this->simpleSQL = SimpleSQL::create(
			plugin: $this,
			dbConfig: $this->getConfig()->get("database"),
		);

		// Register event listeners (this class implements Listener)
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		// Register commands
		$map = $this->getServer()->getCommandMap();
		$map->register("simpleeconomy", new MoneyCommand($this));
		$map->register("simpleeconomy", new PayCommand($this));
		$map->register("simpleeconomy", new SetMoneyCommand($this));

		$this->getLogger()->info("SimpleEconomy enabled — using SimpleSQL hybrid storage.");
	}

	protected function onDisable(): void {
		// Graceful shutdown: persists all dirty sessions to SQL, cancels pending loads,
		// and closes the database connection.
		$this->simpleSQL->close();
	}

	// ──────────────────────────────────────────────
	//  Event handlers
	// ──────────────────────────────────────────────

	/**
	 * On player join: open an async session.
	 *
	 * The session is NOT available immediately — it loads asynchronously from
	 * both SQL and YAML. Commands must check isSessionReady() before use.
	 */
	public function onJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$name = strtolower($player->getName());

		$this->simpleSQL->openSession($name, function (Session $session) use ($player, $name): void {
			// Session is now loaded and conflict-resolved.

			// Initialize default balance for first-time players.
			if (!$session->has("balance")) {
				$session->set("balance", $this->defaultBalance);
				$session->save(); // Persist initial balance immediately
				$this->getLogger()->info("Created new economy profile for '$name' with balance {$this->defaultBalance}.");
			}

			// Only send welcome message if the player is still online
			// (they might have disconnected during async loading).
			if ($player->isOnline()) {
				$balance = $session->get("balance", 0);
				$player->sendMessage("§aWelcome! Your balance: §e{$this->currencySymbol}{$balance}");
			}
		});
	}

	/**
	 * On player quit: save dirty data and close the session.
	 *
	 * This is CRITICAL for memory safety (S3). Without this, session data
	 * would accumulate in RAM forever.
	 */
	public function onQuit(PlayerQuitEvent $event): void {
		$name = strtolower($event->getPlayer()->getName());

		// closeSession() handles both scenarios:
		//   - Session is active → auto-saves if dirty, then frees memory.
		//   - Session is still loading → cancels the pending load.
		$this->simpleSQL->closeSession($name);
	}

	// ──────────────────────────────────────────────
	//  Public API (used by commands)
	// ──────────────────────────────────────────────

	/**
	 * Check if a player's session is fully loaded and ready.
	 *
	 * Commands MUST call this before accessing session data.
	 * Returns false if:
	 *   - The session is still loading (async barrier not yet resolved).
	 *   - The player has no session (not online or already quit).
	 */
	public function isSessionReady(string $name): bool {
		return $this->simpleSQL->hasSession(strtolower($name));
	}

	/**
	 * Get a player's active session, or null if not ready.
	 */
	public function getPlayerSession(string $name): ?Session {
		return $this->simpleSQL->getSession(strtolower($name));
	}

	/**
	 * Get the configured default balance.
	 */
	public function getDefaultBalance(): int {
		return $this->defaultBalance;
	}

	/**
	 * Get the configured currency symbol.
	 */
	public function getCurrencySymbol(): string {
		return $this->currencySymbol;
	}

	/**
	 * Get the SimpleSQL instance (for advanced use).
	 */
	public function getSimpleSQL(): SimpleSQL {
		return $this->simpleSQL;
	}

	/**
	 * Format a monetary amount with the currency symbol.
	 */
	public function formatMoney(int|float $amount): string {
		return $this->currencySymbol . number_format((float) $amount, 0, ".", ",");
	}
}
