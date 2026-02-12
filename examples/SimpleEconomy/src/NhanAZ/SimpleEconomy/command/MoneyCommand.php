<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

/**
 * /money [player] — View your own or another player's balance.
 *
 * Features:
 *   - Name prefix matching for online players (e.g. /money nh → NhanAZ)
 *   - Offline player lookup via temporary session
 *   - Console must provide a player name
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
		if (count($args) >= 1) {
			$input = $args[0];

			// Try prefix match among online players
			$player = $this->plugin->resolvePlayer($input);
			if ($player !== null) {
				$this->showBalance($sender, $player->getName());
				return;
			}

			// No online match — try offline (exact name) via temp session
			$this->plugin->withPlayerSession(
				$input,
				onSession: function (Session $session, bool $temporary) use ($sender, $input): void {
					if (!$session->has("balance")) {
						$sender->sendMessage("§cPlayer '$input' has no economy data.");
					} else {
						$balance = (int) $session->get("balance", 0);
						$sender->sendMessage("§a{$input}'s balance: §e" . $this->plugin->formatMoney($balance));
					}
					if ($temporary) {
						$this->plugin->closeTempSession($input);
					}
				},
				onError: function (string $msg) use ($sender): void {
					$sender->sendMessage($msg);
				},
			);
		} elseif ($sender instanceof Player) {
			$this->showBalance($sender, $sender->getName());
		} else {
			$sender->sendMessage("§cUsage: /money <player>");
		}
	}

	private function showBalance(CommandSender $sender, string $targetName): void {
		$balance = $this->plugin->getMoney($targetName);
		if ($balance === null) {
			$sender->sendMessage("§cCould not retrieve data for '$targetName'.");
			return;
		}

		$formatted = $this->plugin->formatMoney($balance);
		if ($sender instanceof Player && strtolower($targetName) === strtolower($sender->getName())) {
			$sender->sendMessage("§aYour balance: §e{$formatted}");
		} else {
			$sender->sendMessage("§a{$targetName}'s balance: §e{$formatted}");
		}
	}
}
