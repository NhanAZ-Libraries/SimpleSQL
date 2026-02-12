<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

/**
 * /topmoney [page] — Display the richest players leaderboard.
 *
 * The leaderboard is pre-populated from YAML files on startup and
 * updated in real-time as balances change. Supports pagination.
 */
class TopMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct(
			"topmoney",
			"View the richest players leaderboard.",
			"/topmoney [page]",
		);
		$this->setPermission("simpleeconomy.command.topmoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$perPage = $this->plugin->getTopmoneyPerPage();

		// Parse page number
		$page = 1;
		if (count($args) >= 1 && is_numeric($args[0])) {
			$page = max(1, (int) $args[0]);
		}

		$total = $this->plugin->getBalanceCacheCount();
		if ($total === 0) {
			$sender->sendMessage("§eNo economy data available yet.");
			return;
		}

		$maxPage = max(1, (int) ceil($total / $perPage));
		$page = min($page, $maxPage);
		$offset = ($page - 1) * $perPage;

		$top = $this->plugin->getTopBalances($perPage, $offset);

		$sender->sendMessage("§6══ Top Money §7(Page {$page}/{$maxPage}) §6══");
		foreach ($top as $i => $entry) {
			$rank = $offset + $i + 1;
			$name = $entry["name"];
			$balance = $this->plugin->formatMoney($entry["balance"]);

			// Highlight if it's the sender
			$highlight = strtolower($name) === strtolower($sender->getName()) ? "§a" : "§f";
			$sender->sendMessage("§e#{$rank} {$highlight}{$name} §7- §a{$balance}");
		}
		$sender->sendMessage("§6══════════════════════");
	}
}
