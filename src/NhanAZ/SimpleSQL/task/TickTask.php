<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL\task;

use NhanAZ\SimpleSQL\SimpleSQL;
use pocketmine\scheduler\Task;

/**
 * Repeating scheduler task that drives the {@see SimpleSQL} write scheduler.
 *
 * Registered automatically when using {@see SimpleSQL::create()}.
 * Runs every tick (50ms) to dispatch queued YAML writes up to the throttle limit.
 */
class TickTask extends Task {

	public function __construct(
		private readonly SimpleSQL $manager,
	) {}

	public function onRun(): void {
		$this->manager->tick();
	}
}
