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
 * CurlExecException: Exception for a request that libcurl could not carry out.
 *
 * Marks a failure at transport level, such as DNS, TLS, a timeout, a refused connection or a
 * cURL option that libcurl rejects, so that a caller can tell it from an invalid request. An
 * HTTP error status is not one; the response reports it.
 */
class CurlExecException extends CurlException {

	/** cURL error number; 0 when the failure did not come from the transfer itself. */
	private readonly int $curlErrno;

	/**
	 * Create the exception with the cURL error number and the request context.
	 *
	 * @param  string               $message        Human-readable message.
	 * @param  int                  $curlErrno      cURL error number, also used as exception code; 0 when the transfer did not run.
	 * @param  string|null          $requestMethod  Normalized request method, when known.
	 * @param  string|null          $requestUrl     Request URL, when known, with sensitive query values already redacted.
	 * @param  array<string,mixed>  $transferInfo   curl_getinfo() data, when collected, with sensitive query values already redacted.
	 * @param  \Throwable|null      $previous       Lower-layer cause.
	 */
	public function __construct(string $message, int $curlErrno = 0, ?string $requestMethod = null, ?string $requestUrl = null, array $transferInfo = [], ?\Throwable $previous = null) {
		parent::__construct($message, $curlErrno, $requestMethod, $requestUrl, $transferInfo, $previous);

		$this->curlErrno = $curlErrno;
	}

	/**
	 * Return the cURL error number.
	 *
	 * @return int  0 when the failure did not come from the transfer itself.
	 */
	public function getCurlErrno(): int {
		return $this->curlErrno;
	}

}
