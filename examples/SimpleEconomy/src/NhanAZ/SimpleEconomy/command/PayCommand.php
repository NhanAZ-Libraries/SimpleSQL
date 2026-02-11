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
 * /pay <player> <amount> — Transfer money to another online player.
 *
 * Both sender and receiver must have loaded sessions. The transfer is
 * validated (sufficient funds, positive amount, not self-pay) before
 * any data is modified.
 */
class PayCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"pay",
			"Transfer money to another player.",
			"/pay <player> <amount>",
		);
		$this->setPermission("simpleeconomy.command.pay");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		// Only players can use /pay
		if (!$sender instanceof Player) {
			$sender->sendMessage("§cThis command can only be used in-game.");
			return;
		}

		// Validate arguments
		if (count($args) < 2) {
			$sender->sendMessage("§cUsage: /pay <player> <amount>");
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
		if ($amount <= 0) {
			$sender->sendMessage("§cAmount must be greater than zero.");
			return;
		}

		// ── Prevent self-pay ──
		if (strtolower($sender->getName()) === strtolower($targetName)) {
			$sender->sendMessage("§cYou cannot pay yourself.");
			return;
		}

		// ── Check sender's session ──
		if (!$this->plugin->isSessionReady($sender->getName())) {
			$sender->sendMessage("§eYour data is still loading. Please wait a moment.");
			return;
		}

		$senderSession = $this->plugin->getPlayerSession($sender->getName());
		if ($senderSession === null) {
			$sender->sendMessage("§cCould not access your data.");
			return;
		}

		// ── Check receiver's session ──
		if (!$this->plugin->isSessionReady($targetName)) {
			if ($this->plugin->getSimpleSQL()->isLoading(strtolower($targetName))) {
				$sender->sendMessage("§e{$targetName}'s data is still loading. Please wait...");
			} else {
				$sender->sendMessage("§c{$targetName} is not online.");
			}
			return;
		}

		$receiverSession = $this->plugin->getPlayerSession($targetName);
		if ($receiverSession === null) {
			$sender->sendMessage("§cCould not access {$targetName}'s data.");
			return;
		}

		// ── Check sufficient funds ──
		$senderBalance = (int) $senderSession->get("balance", 0);
		if ($senderBalance < $amount) {
			$sender->sendMessage(
				"§cInsufficient funds. Your balance: §e"
				. $this->plugin->formatMoney($senderBalance)
			);
			return;
		}

		// ── Execute transfer ──
		$receiverBalance = (int) $receiverSession->get("balance", 0);

		$senderSession->set("balance", $senderBalance - $amount);
		$receiverSession->set("balance", $receiverBalance + $amount);

		// Persist both sessions asynchronously.
		// SQL is written first (source of truth), then YAML mirror is queued.
		$senderSession->save();
		$receiverSession->save();

		$formatted = $this->plugin->formatMoney($amount);
		$sender->sendMessage("§aYou sent §e{$formatted}§a to §b{$targetName}§a.");

		// Notify the receiver if they are online
		$receiverPlayer = $this->plugin->getServer()->getPlayerExact($targetName);
		if ($receiverPlayer !== null) {
			$receiverPlayer->sendMessage(
				"§aYou received §e{$formatted}§a from §b{$sender->getName()}§a."
			);
		}
	}
}
