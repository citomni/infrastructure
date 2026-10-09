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
 * Base exception for the Curl service.
 *
 * Behavior:
 * - Carries normalized request context when available.
 * - Provides lightweight accessors for method, URL, and transfer info.
 *
 * Notes:
 * - This is the common parent for configuration and execution failures.
 * - Query values marked sensitive by Curl are redacted from URL-bearing exception context.
 *
 * @throws void
 */
class CurlException extends \RuntimeException {

	/** @var string|null */
	protected ?string $requestMethod;

	/** @var string|null */
	protected ?string $requestUrl;

	/** @var array<string,mixed> */
	protected array $transferInfo;

	/**
	 * Create a new Curl exception.
	 *
	 * @param string $message Exception message.
	 * @param int $code Exception code.
	 * @param string|null $requestMethod Normalized request method when available.
	 * @param string|null $requestUrl Request URL when available; marked sensitive query values may be redacted.
	 * @param array<string,mixed> $transferInfo Transfer info when available; URL-bearing fields may be redacted.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(string $message, int $code = 0, ?string $requestMethod = null, ?string $requestUrl = null, array $transferInfo = [], ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);

		$this->requestMethod = $requestMethod;
		$this->requestUrl = $requestUrl;
		$this->transferInfo = $transferInfo;
	}

	/**
	 * Get the request method, if available.
	 *
	 * @return string|null Request method or null.
	 */
	public function getRequestMethod(): ?string {
		return $this->requestMethod;
	}

	/**
	 * Get the request URL, if available.
	 *
	 * @return string|null Request URL or null; marked sensitive query values may be redacted.
	 */
	public function getRequestUrl(): ?string {
		return $this->requestUrl;
	}

	/**
	 * Get transfer info collected at failure time.
	 *
	 * @return array<string,mixed> Transfer info array with marked sensitive query values redacted from URL-bearing fields.
	 */
	public function getTransferInfo(): array {
		return $this->transferInfo;
	}

}
