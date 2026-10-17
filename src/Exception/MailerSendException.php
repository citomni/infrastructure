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
 * MailerSendException: Exception for a message that the transport did not confirm for every recipient.
 *
 * Marks an expected runtime failure, such as an unreachable, refusing or silent SMTP server,
 * a rejected sender or recipient, or a failing sendmail binary, so that a caller can tell it
 * from invalid input or configuration. Whether to retry, queue or give up belongs to the
 * caller.
 *
 * Rules:
 * - Put no message body and no credential into it; a server's reply goes in as the server
 *   wrote it.
 */
class MailerSendException extends MailerException {

	/**
	 * Create the exception with the transport's error and the recipients that it confirmed anyway.
	 *
	 * @param  string  $message  The transport's error, quoting the server's reply as the server wrote it when it gave one.
	 * @param  list<string>  $accepted  Recipient addresses for which the transport confirmed the message; [] when it confirmed none. Sending the message again reaches them twice.
	 * @param  \Throwable|null  $previous  The transport library's exception.
	 */
	public function __construct(string $message, public readonly array $accepted = [], ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}


}
