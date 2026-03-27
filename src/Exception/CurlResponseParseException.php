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
 * Thrown when a received response cannot be parsed as expected.
 *
 * Behavior:
 * - Represents post-transfer parsing failures inside the Curl service.
 *
 * Notes:
 * - Typical causes include invalid header/body split boundaries when headers were captured.
 *
 * @throws void
 */
final class CurlResponseParseException extends CurlException {
	/**
	 * Create a new response parsing exception.
	 *
	 * @param string $message Exception message.
	 * @param string|null $requestMethod Normalized request method when available.
	 * @param string|null $requestUrl Request URL when available.
	 * @param array<string,mixed> $transferInfo Transfer info when available.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(string $message, ?string $requestMethod = null, ?string $requestUrl = null, array $transferInfo = [], ?\Throwable $previous = null) {
		parent::__construct($message, 0, $requestMethod, $requestUrl, $transferInfo, $previous);
	}

}
