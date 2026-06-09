<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Infrastructure\Exception;

/**
 * BruteForceSetupException: Thrown when brute force storage is not installed correctly.
 *
 * Covers runtime setup failures that prevent brute force protection from using
 * its persistence layer safely, such as a missing bruteforce_counters table.
 *
 * Notes:
 * - This is a setup/deployment error, not invalid user input.
 * - Configuration shape errors use BruteForceConfigException.
 * - Db connection/query failures still propagate as DbConnectException /
 *   DbQueryException from the Db layer.
 */
final class BruteForceSetupException extends BruteForceException {

	private ?string $missingTable;
	private ?string $hint;


	/**
	 * @param string $message Human-readable setup error.
	 * @param ?string $missingTable Missing table name when relevant.
	 * @param ?string $hint Human-readable remediation hint.
	 * @param ?\Throwable $previous Previous exception for chaining.
	 */
	public function __construct(string $message = '', ?string $missingTable = null, ?string $hint = null, ?\Throwable $previous = null) {
		$this->missingTable = $missingTable;
		$this->hint = $hint;
		parent::__construct($message, 0, $previous);
	}


	/**
	 * Return the missing table name, or null when the setup error is not table-specific.
	 *
	 * @return ?string
	 */
	public function getMissingTable(): ?string {
		return $this->missingTable;
	}


	/**
	 * Return the remediation hint, or null when no hint was provided.
	 *
	 * @return ?string
	 */
	public function getHint(): ?string {
		return $this->hint;
	}

}
