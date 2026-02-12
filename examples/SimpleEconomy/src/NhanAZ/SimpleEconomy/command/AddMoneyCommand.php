<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

/**
 * /addmoney <player> <amount> — Add money to a player's balance (OP only).
 *
 * Supports name prefix matching and offline players.
 */
class AddMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"addmoney",
			"Add money to a player's balance (OP only).",
			"/addmoney <player> <amount>",
		);
		$this->setPermission("simpleeconomy.command.addmoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		if (count($args) < 2) {
			$sender->sendMessage("§cUsage: /addmoney <player> <amount>");
			return;
		}

		// ── Validate amount ──
		$amountRaw = $args[1];
		if (!is_numeric($amountRaw)) {
			$sender->sendMessage("§cAmount must be a number.");
			return;
		}

		$amount = (int) floor((float) $amountRaw);
		if ($amount <= 0) {
			$sender->sendMessage("§cAmount must be greater than zero.");
			return;
		}

		// ── Resolve target ──
		$input = $args[0];
		$player = $this->plugin->resolvePlayer($input);
		$targetName = $player !== null ? $player->getName() : $input;

		$this->plugin->withPlayerSession(
			$targetName,
			onSession: function (Session $session, bool $temporary) use ($sender, $targetName, $amount): void {
				$oldBalance = (int) $session->get("balance", 0);
				$newBalance = $oldBalance + $amount;
				$session->set("balance", $newBalance);
				$this->plugin->updateBalanceCache(strtolower($targetName), $newBalance);

				$session->save(function (bool $success) use ($sender, $targetName, $amount, $newBalance, $temporary): void {
					if ($success) {
						$sender->sendMessage(
							"§aAdded §e" . $this->plugin->formatMoney($amount)
							. "§a to §b{$targetName}§a. New balance: §e"
							. $this->plugin->formatMoney($newBalance)
						);

						$target = $this->plugin->getServer()->getPlayerExact($targetName);
						if ($target !== null && strtolower($target->getName()) !== strtolower($sender->getName())) {
							$target->sendMessage(
								"§aYou received §e" . $this->plugin->formatMoney($amount)
								. "§a from an administrator. New balance: §e"
								. $this->plugin->formatMoney($newBalance)
							);
						}
					} else {
						$sender->sendMessage("§cFailed to save data for '$targetName'.");
					}

					if ($temporary) {
						$this->plugin->closeTempSession($targetName);
					}
				});
			},
			onError: function (string $msg) use ($sender): void {
				$sender->sendMessage($msg);
			},
		);
	}
}
