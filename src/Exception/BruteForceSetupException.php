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
 * BruteForceSetupException: Exception for brute force storage that is not installed.
 *
 * Marks a deployment error, such as a missing table, so that an installer or a health check
 * can tell it from a cfg error and from a database failure. Installing the schema belongs to
 * the app's deployment.
 */
class BruteForceSetupException extends BruteForceException {

	/** Name of the missing table, or null when the failure is not about one table. */
	private ?string $missingTable;

	/** Human-readable remediation hint, or null for none. */
	private ?string $hint;


	/**
	 * Create the exception with the missing table and a remediation hint.
	 *
	 * @param  string  $message  Human-readable setup error.
	 * @param  string|null  $missingTable  Name of the missing table, when the failure is about one.
	 * @param  string|null  $hint  Human-readable remediation hint.
	 * @param  \Throwable|null  $previous  Lower-layer cause.
	 */
	public function __construct(string $message = '', ?string $missingTable = null, ?string $hint = null, ?\Throwable $previous = null) {
		$this->missingTable = $missingTable;
		$this->hint = $hint;
		parent::__construct($message, 0, $previous);
	}


	/**
	 * Return the name of the missing table.
	 *
	 * @return string|null  Null when the failure is not about one table.
	 */
	public function getMissingTable(): ?string {
		return $this->missingTable;
	}


	/**
	 * Return the remediation hint.
	 *
	 * @return string|null  Null when no hint was given.
	 */
	public function getHint(): ?string {
		return $this->hint;
	}

}
