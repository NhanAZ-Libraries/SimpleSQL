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
 * Both sender and receiver must be online with loaded sessions.
 * Supports name prefix matching for the receiver.
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
		if (!$sender instanceof Player) {
			$sender->sendMessage("§cThis command can only be used in-game.");
			return;
		}

		if (count($args) < 2) {
			$sender->sendMessage("§cUsage: /pay <player> <amount>");
			return;
		}

		// ── Resolve receiver via prefix matching ──
		$input = $args[0];
		$receiver = $this->plugin->resolvePlayer($input);
		if ($receiver === null) {
			$sender->sendMessage("§cPlayer '$input' is not online.");
			return;
		}
		$targetName = $receiver->getName();

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

		// ── Prevent self-pay ──
		if (strtolower($sender->getName()) === strtolower($targetName)) {
			$sender->sendMessage("§cYou cannot pay yourself.");
			return;
		}

		// ── Check sender session ──
		$senderBalance = $this->plugin->getMoney($sender->getName());
		if ($senderBalance === null) {
			$sender->sendMessage("§eYour data is still loading. Please wait a moment.");
			return;
		}

		// ── Check receiver session ──
		$receiverBalance = $this->plugin->getMoney($targetName);
		if ($receiverBalance === null) {
			$sender->sendMessage("§e{$targetName}'s data is still loading. Please wait...");
			return;
		}

		// ── Check sufficient funds ──
		if ($senderBalance < $amount) {
			$sender->sendMessage("§cInsufficient funds. Your balance: §e" . $this->plugin->formatMoney($senderBalance));
			return;
		}

		// ── Execute transfer ──
		$this->plugin->setMoney($sender->getName(), $senderBalance - $amount);
		$this->plugin->setMoney($targetName, $receiverBalance + $amount);

		$formatted = $this->plugin->formatMoney($amount);
		$sender->sendMessage("§aYou sent §e{$formatted}§a to §b{$targetName}§a.");

		if ($receiver->isOnline()) {
			$receiver->sendMessage("§aYou received §e{$formatted}§a from §b{$sender->getName()}§a.");
		}
	}
}
