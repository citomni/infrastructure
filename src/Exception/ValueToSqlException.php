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
 * ValueToSqlException: Exception for form input that valueToSql rejects.
 *
 * Carries a stable message key with its placeholder values, so that an adapter can translate
 * the failure and show it at the field it concerns. The message itself is a fallback for
 * developers and logs; translating and showing it belongs to the adapter.
 *
 * Rules:
 * - Keep message keys stable: Language files and callers depend on them.
 */
class ValueToSqlException extends \InvalidArgumentException {

	/** Name of the field the failure belongs to, or null when none is named. */
	private ?string $field;

	/** Language key of the failure, such as err_value_to_sql_integer_invalid. */
	private string $messageKey;

	/** @var array<string,mixed> Values for the placeholders of the message key, keyed by placeholder name. */
	private array $messageParams;

	/**
	 * Create the exception with its message key and placeholder values.
	 *
	 * @param  string  $message  Fallback message for developers and logs.
	 * @param  string  $messageKey  Language key of the failure.
	 * @param  array<string,mixed>  $messageParams  Values for the placeholders of the message key.
	 * @param  string|null  $field  Name of the field the failure belongs to.
	 * @param  int  $code  Exception code.
	 * @param  \Throwable|null  $previous  Lower-layer cause.
	 */
	// Final, so that withField() can build a copy of the same class with new static.
	final public function __construct(string $message, string $messageKey, array $messageParams = [], ?string $field = null, int $code = 0, ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->field = $field;
		$this->messageKey = $messageKey;
		$this->messageParams = $messageParams;
	}

	/**
	 * Return the name of the field the failure belongs to.
	 *
	 * @return string|null  Null when no field is named.
	 */
	public function getField(): ?string {
		return $this->field;
	}

	/**
	 * Tell whether a non-empty field name is set.
	 *
	 * @return bool
	 */
	public function hasField(): bool {
		return $this->field !== null && $this->field !== '';
	}

	/**
	 * Return the language key of the failure.
	 *
	 * @return string  Such as err_value_to_sql_integer_invalid.
	 */
	public function getMessageKey(): string {
		return $this->messageKey;
	}

	/**
	 * Return the values for the placeholders of the message key.
	 *
	 * @return array<string,mixed>  Keyed by placeholder name, such as ['max' => 200].
	 */
	public function getMessageParams(): array {
		return $this->messageParams;
	}

	/**
	 * Return a copy of this exception that names the field it belongs to.
	 *
	 * Behavior:
	 * - Trims $field; an empty result returns this exception unchanged.
	 * - PHP exceptions cannot be cloned, so the copy is a new exception of the same class,
	 *   with the same message, message key, params and code, and this exception as previous.
	 * - The copy's file and line are set to the call of withField(), matching its trace;
	 *   the original throw site stays available through getPrevious().
	 *
	 * Typical usage:
	 *   throw $e->withField('price');
	 *
	 * @param  string  $field  Field name, e.g. a form input name.
	 * @return static  The copy, or this exception when $field is empty after trimming.
	 */
	public function withField(string $field): static {
		$field = \trim($field);
		if ($field === '') {
			return $this;
		}

		$copy = new static($this->getMessage(), $this->messageKey, $this->messageParams, $field, $this->getCode(), $this);

		// PHP sets file and line where the exception is created, i.e. inside this method.
		$caller = $copy->getTrace()[0] ?? [];
		$copy->file = $caller['file'] ?? $copy->file;
		$copy->line = $caller['line'] ?? $copy->line;

		return $copy;
	}

}
