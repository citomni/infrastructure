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
 * Structured exception for UI/form -> SQL normalization failures.
 *
 * Carries a stable message key and placeholder params so higher layers
 * can translate and compose user-facing field errors.
 *
 * Notes:
 * - $message is primarily a developer/debug fallback.
 * - $messageKey is the canonical Txt lookup key.
 * - $field is optional and is typically attached by the caller.
 */
final class ValueToSqlException extends \InvalidArgumentException {

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

	public function withField(string $field): self {
		$field = \trim($field);
		if ($field === '') {
			return $this;
		}

		$clone = clone $this;
		$clone->field = $field;
		return $clone;
	}

}
