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
 * MailerConfigException: Exception for a cfg mail value that the host app set wrongly.
 *
 * Marks a configuration error in the host app, not a refused send: Let it reach the global
 * error handler. Fixing the value belongs to the host app's cfg files.
 *
 * Rules:
 * - Name the cfg path of the invalid value, so the message and the log point at the value to fix.
 */
class MailerConfigException extends MailerException {

	/**
	 * Build the message from the cfg path, the requirement and the value found.
	 *
	 * @param  string  $path  Cfg path of the invalid value, such as mail.smtp.port.
	 * @param  string  $requirement  What the value must be, such as "an integer from 1 to 65535".
	 * @param  mixed  $value  The value found.
	 * @param  bool  $showValue  False to name only the type of the value, for a value that may be a credential.
	 */
	public function __construct(public readonly string $path, string $requirement, mixed $value, bool $showValue = true) {
		$found = $showValue && \is_scalar($value) ? \var_export($value, true) : \get_debug_type($value);
		parent::__construct('Cfg ' . $path . ' must be ' . $requirement . ', got ' . $found . '.');
	}


}
