<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

/**
 * /money [player] — View your own or another player's balance.
 *
 * Usage:
 *   /money          — Shows your balance.
 *   /money Steve    — Shows Steve's balance (if online and session is ready).
 */
class MoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"money",
			"View your balance or another player's balance.",
			"/money [player]",
		);
		$this->setPermission("simpleeconomy.command.money");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		// Determine whose balance to check
		if (count($args) >= 1) {
			// Viewing another player's balance
			$targetName = $args[0];
		} elseif ($sender instanceof Player) {
			// Viewing own balance
			$targetName = $sender->getName();
		} else {
			$sender->sendMessage("§cUsage: /money <player>");
			return;
		}

		// Check if the target's session is loaded
		if (!$this->plugin->isSessionReady($targetName)) {
			// Session might still be loading, or the player is offline
			if ($this->plugin->getSimpleSQL()->isLoading(strtolower($targetName))) {
				$sender->sendMessage("§eData for '$targetName' is still loading. Please wait...");
			} else {
				$sender->sendMessage("§cPlayer '$targetName' is not online or has no data loaded.");
			}
			return;
		}

		$session = $this->plugin->getPlayerSession($targetName);
		if ($session === null) {
			$sender->sendMessage("§cCould not retrieve data for '$targetName'.");
			return;
		}

		$balance = $session->get("balance", 0);
		$formatted = $this->plugin->formatMoney($balance);

		if (strtolower($targetName) === strtolower($sender->getName())) {
			$sender->sendMessage("§aYour balance: §e{$formatted}");
		} else {
			$sender->sendMessage("§a{$targetName}'s balance: §e{$formatted}");
		}
	}
}
