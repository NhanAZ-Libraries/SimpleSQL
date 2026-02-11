<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

/**
 * Represents the write state of a single session ID in the WriteScheduler.
 *
 * State transitions:
 *   IDLE    → QUEUED   (scheduleWrite called)
 *   QUEUED  → RUNNING  (dispatched by tick)
 *   RUNNING → IDLE     (completed, no coalesced data)
 *   RUNNING → QUEUED   (completed, has coalesced data)
 */
enum WriteState {
	/** No pending or in-flight write. */
	case IDLE;

	/** Queued for dispatch on next available tick slot. */
	case QUEUED;

	/** An AsyncTask is currently executing the write. */
	case RUNNING;
}
