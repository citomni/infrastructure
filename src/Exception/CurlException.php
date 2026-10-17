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
 * CurlException: Base exception for failures of the curl service.
 *
 * Lets a caller catch every failure of an outbound request in one catch, and carries the
 * request context needed to report it: the method, the URL and the transfer info, each when
 * known. An HTTP error status is not a failure; the response reports it.
 *
 * Rules:
 * - Never carry a query value that the request marked sensitive.
 */
class CurlException extends \RuntimeException {

	/** Normalized request method, or null when unknown. */
	protected ?string $requestMethod;

	/** Request URL with sensitive query values redacted, or null when unknown. */
	protected ?string $requestUrl;

	/** @var array<string,mixed> curl_getinfo() data at the time of the failure, or [] when none was collected. */
	protected array $transferInfo;

	/**
	 * Create the exception with the context of the failed request.
	 *
	 * @param  string  $message  Human-readable message.
	 * @param  int  $code  Exception code.
	 * @param  string|null  $requestMethod  Normalized request method, when known.
	 * @param  string|null  $requestUrl  Request URL, when known, with sensitive query values already redacted.
	 * @param  array<string,mixed>  $transferInfo  curl_getinfo() data, when collected, with sensitive query values already redacted.
	 * @param  \Throwable|null  $previous  Lower-layer cause.
	 */
	public function __construct(string $message, int $code = 0, ?string $requestMethod = null, ?string $requestUrl = null, array $transferInfo = [], ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);

		$this->requestMethod = $requestMethod;
		$this->requestUrl = $requestUrl;
		$this->transferInfo = $transferInfo;
	}

	/**
	 * Return the normalized request method.
	 *
	 * @return string|null  Null when unknown.
	 */
	public function getRequestMethod(): ?string {
		return $this->requestMethod;
	}

	/**
	 * Return the request URL.
	 *
	 * @return string|null  Sensitive query values redacted; null when unknown.
	 */
	public function getRequestUrl(): ?string {
		return $this->requestUrl;
	}

	/**
	 * Return the transfer info collected at the time of the failure.
	 *
	 * @return array<string,mixed>  curl_getinfo() data with sensitive query values redacted; [] when none was collected.
	 */
	public function getTransferInfo(): array {
		return $this->transferInfo;
	}

}
