<?php

declare(strict_types=1);

namespace NhanAZ\SimpleSQL;

use JsonException;
use UnexpectedValueException;

final class SqlRowDecoder {
	/**
	 * @param array<array-key, mixed> $row
	 * @return array{data: array<array-key, mixed>, revision: int}
	 */
	public static function decode(array $row): array {
		$encoded = $row["data"] ?? null;
		if (!is_string($encoded)) {
			throw new UnexpectedValueException("SQL row data is not a JSON string.");
		}

		try {
			$data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new UnexpectedValueException("SQL row contains invalid JSON.", previous: $error);
		}
		if (!is_array($data)) {
			throw new UnexpectedValueException("SQL row data must decode to an array.");
		}

		$revision = $row["revision"] ?? null;
		if (is_string($revision) && $revision !== "" && ctype_digit($revision)) {
			$revision = filter_var($revision, FILTER_VALIDATE_INT);
		}
		if (!is_int($revision) || $revision < 0) {
			throw new UnexpectedValueException("SQL row revision must be a non-negative integer.");
		}

		return ["data" => $data, "revision" => $revision];
	}
}
