<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

/**
 * /setmoney <player> <amount> — Set a player's balance (OP only).
 *
 * The target player must be online with a loaded session. The balance
 * is updated immediately and persisted asynchronously.
 */
class SetMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"setmoney",
			"Set a player's balance (OP only).",
			"/setmoney <player> <amount>",
		);
		$this->setPermission("simpleeconomy.command.setmoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		// Validate arguments
		if (count($args) < 2) {
			$sender->sendMessage("§cUsage: /setmoney <player> <amount>");
			return;
		}

		$targetName = $args[0];
		$amountRaw = $args[1];

		// ── Validate amount ──
		if (!is_numeric($amountRaw)) {
			$sender->sendMessage("§cAmount must be a number.");
			return;
		}

		$amount = (int) floor((float) $amountRaw);
		if ($amount < 0) {
			$sender->sendMessage("§cAmount cannot be negative.");
			return;
		}

		// ── Check target's session ──
		if (!$this->plugin->isSessionReady($targetName)) {
			if ($this->plugin->getSimpleSQL()->isLoading(strtolower($targetName))) {
				$sender->sendMessage("§e{$targetName}'s data is still loading. Please wait...");
			} else {
				$sender->sendMessage("§c{$targetName} is not online or has no data loaded.");
			}
			return;
		}

		$session = $this->plugin->getPlayerSession($targetName);
		if ($session === null) {
			$sender->sendMessage("§cCould not access {$targetName}'s data.");
			return;
		}

		// ── Set balance ──
		$oldBalance = (int) $session->get("balance", 0);
		$session->set("balance", $amount);

		// Persist asynchronously (SQL → YAML mirror)
		$session->save(function (bool $success) use ($sender, $targetName, $oldBalance, $amount): void {
			if ($success) {
				$sender->sendMessage(
					"§aSet §b{$targetName}§a's balance: §e"
					. $this->plugin->formatMoney($oldBalance)
					. " §a→ §e"
					. $this->plugin->formatMoney($amount)
				);

				// Notify the target player if they are online and not the sender
				$targetPlayer = $this->plugin->getServer()->getPlayerExact($targetName);
				if ($targetPlayer !== null && strtolower($targetPlayer->getName()) !== strtolower($sender->getName())) {
					$targetPlayer->sendMessage(
						"§aYour balance has been set to §e"
						. $this->plugin->formatMoney($amount)
						. "§a by an administrator."
					);
				}
			} else {
				$sender->sendMessage("§cFailed to save {$targetName}'s balance. Check console for errors.");
			}
		});
	}
}
