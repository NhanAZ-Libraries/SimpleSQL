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
 * /reducemoney <player> <amount> — Reduce money from a player's balance (OP only).
 *
 * Balance cannot go below zero.
 * Supports name prefix matching and offline players.
 */
class ReduceMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"reducemoney",
			"Reduce money from a player's balance (OP only).",
			"/reducemoney <player> <amount>",
		);
		$this->setPermission("simpleeconomy.command.reducemoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		if (count($args) < 2) {
			$sender->sendMessage("§cUsage: /reducemoney <player> <amount>");
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

				if ($oldBalance < $amount) {
					$sender->sendMessage(
						"§c{$targetName} only has §e" . $this->plugin->formatMoney($oldBalance)
						. "§c. Cannot reduce §e" . $this->plugin->formatMoney($amount) . "§c."
					);
					if ($temporary) {
						$this->plugin->closeTempSession($targetName);
					}
					return;
				}

				$newBalance = $oldBalance - $amount;
				$session->set("balance", $newBalance);
				$this->plugin->updateBalanceCache(strtolower($targetName), $newBalance);

				$session->save(function (bool $success) use ($sender, $targetName, $amount, $newBalance, $temporary): void {
					if ($success) {
						$sender->sendMessage(
							"§aRemoved §e" . $this->plugin->formatMoney($amount)
							. "§a from §b{$targetName}§a. New balance: §e"
							. $this->plugin->formatMoney($newBalance)
						);

						$target = $this->plugin->getServer()->getPlayerExact($targetName);
						if ($target !== null && strtolower($target->getName()) !== strtolower($sender->getName())) {
							$target->sendMessage(
								"§c§e" . $this->plugin->formatMoney($amount)
								. "§c was removed from your balance by an administrator. New balance: §e"
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
