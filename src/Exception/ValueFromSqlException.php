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
 * Structured exception for SQL -> UI/form normalization failures.
 *
 * Carries a stable message key and placeholder params so higher layers
 * can translate and compose user-facing field errors.
 *
 * Notes:
 * - $message is primarily a developer/debug fallback.
 * - $messageKey is the canonical Txt lookup key.
 * - $field is optional and is typically attached by the caller.
 */
final class ValueFromSqlException extends \InvalidArgumentException {

	private ?string $field;
	private string $messageKey;
	private array $messageParams;

	public function __construct(string $message, string $messageKey, array $messageParams = [], ?string $field = null, int $code = 0, ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->field = $field;
		$this->messageKey = $messageKey;
		$this->messageParams = $messageParams;
	}

	public function getField(): ?string {
		return $this->field;
	}

	public function hasField(): bool {
		return $this->field !== null && $this->field !== '';
	}

	public function getMessageKey(): string {
		return $this->messageKey;
	}

	public function getMessageParams(): array {
		return $this->messageParams;
	}

	/**
	 * Return a copy of this exception that names the field it belongs to.
	 *
	 * Behavior:
	 * - Trims $field; an empty result returns this exception unchanged.
	 * - PHP exceptions cannot be cloned, so the copy is a new exception with the same
	 *   message, message key, params and code, and this exception as previous.
	 * - The copy's file and line are set to the call of withField(), matching its trace;
	 *   the original throw site stays available through getPrevious().
	 *
	 * Typical usage:
	 *   throw $e->withField('price');
	 *
	 * @param  string  $field  Field name, e.g. a form input name.
	 * @return self  The copy, or this exception when $field is empty after trimming.
	 */
	public function withField(string $field): self {
		$field = \trim($field);
		if ($field === '') {
			return $this;
		}

		$copy = new self($this->getMessage(), $this->messageKey, $this->messageParams, $field, $this->getCode(), $this);

		// PHP sets file and line where the exception is created, i.e. inside this method.
		$caller = $copy->getTrace()[0] ?? [];
		$copy->file = $caller['file'] ?? $copy->file;
		$copy->line = $caller['line'] ?? $copy->line;

		return $copy;
	}

}
