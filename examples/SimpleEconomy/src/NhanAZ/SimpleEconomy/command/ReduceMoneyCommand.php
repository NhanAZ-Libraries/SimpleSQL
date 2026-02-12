<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class ReduceMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct("reducemoney", "Reduce money from a player's balance (OP only).", "/reducemoney <player> <amount>");
		$this->setPermission("simpleeconomy.command.reducemoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$lang = $this->plugin->getLang();

		if (count($args) < 2) {
			$sender->sendMessage($lang->get("reducemoney.usage"));
			return;
		}

		$amountRaw = $args[1];
		if (!is_numeric($amountRaw)) {
			$sender->sendMessage($lang->get("general.amount-not-number"));
			return;
		}

		$amount = (int) floor((float) $amountRaw);
		if ($amount <= 0) {
			$sender->sendMessage($lang->get("general.amount-positive"));
			return;
		}

		$input = $args[0];
		$player = $this->plugin->resolvePlayer($input);
		$targetName = $player !== null ? $player->getName() : $input;

		$this->plugin->withPlayerSession(
			$targetName,
			onSession: function (Session $session, bool $temporary) use ($sender, $targetName, $amount, $lang): void {
				$oldBalance = (int) $session->get("balance", 0);

				if ($oldBalance < $amount) {
					$sender->sendMessage($lang->get("reducemoney.insufficient", [
						"player" => $targetName,
						"balance" => $this->plugin->formatMoney($oldBalance),
						"amount" => $this->plugin->formatMoney($amount),
					]));
					if ($temporary) {
						$this->plugin->closeTempSession($targetName);
					}
					return;
				}

				$newBalance = $oldBalance - $amount;
				$session->set("balance", $newBalance);
				$this->plugin->updateBalanceCache(strtolower($targetName), $newBalance);

				$session->save(function (bool $success) use ($sender, $targetName, $amount, $newBalance, $temporary, $lang): void {
					if ($success) {
						$sender->sendMessage($lang->get("reducemoney.success", [
							"amount" => $this->plugin->formatMoney($amount),
							"player" => $targetName,
							"balance" => $this->plugin->formatMoney($newBalance),
						]));

						$target = $this->plugin->getServer()->getPlayerExact($targetName);
						if ($target !== null && strtolower($target->getName()) !== strtolower($sender->getName())) {
							$target->sendMessage($lang->get("reducemoney.notify", [
								"amount" => $this->plugin->formatMoney($amount),
								"balance" => $this->plugin->formatMoney($newBalance),
							]));
						}
					} else {
						$sender->sendMessage($lang->get("general.save-failed", ["player" => $targetName]));
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
