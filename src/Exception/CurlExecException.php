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
 * Thrown when cURL fails at transport level.
 *
 * Behavior:
 * - Represents execution-time failures such as DNS, TLS, timeout, connection, or option application errors.
 *
 * Notes:
 * - HTTP 4xx/5xx responses are not transport failures and should not use this exception.
 *
 * @throws void
 */
final class CurlExecException extends CurlException {

	/** @var int */
	private int $curlErrno;

	/**
	 * Create a new transport-level cURL exception.
	 *
	 * @param string $message Exception message.
	 * @param int $curlErrno cURL errno value.
	 * @param string|null $requestMethod Normalized request method when available.
	 * @param string|null $requestUrl Request URL when available.
	 * @param array<string,mixed> $transferInfo Transfer info when available.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message,
		int $curlErrno = 0,
		?string $requestMethod = null,
		?string $requestUrl = null,
		array $transferInfo = [],
		?\Throwable $previous = null
	) {
		parent::__construct($message, $curlErrno, $requestMethod, $requestUrl, $transferInfo, $previous);

		$this->curlErrno = $curlErrno;
	}

	/**
	 * Get the cURL errno value.
	 *
	 * @return int cURL errno.
	 */
	public function getCurlErrno(): int
	{
		return $this->curlErrno;
	}

}
