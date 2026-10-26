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
 * SluggerConfigException: Exception for a slug policy value that the slugger service rejects.
 *
 * Marks a configuration error in the app's cfg or in the service map entry of the slugger,
 * not a rejected slug: Let it reach the global error handler. Fixing the value belongs to the
 * app's cfg files or to the service map.
 *
 * Rules:
 * - Name where the invalid value is set, so the message and the log point at the value to fix.
 */
class SluggerConfigException extends \RuntimeException {

	/**
	 * Build the message from the location of the value, the requirement and the value found.
	 *
	 * @param  string  $path  Where the value is set: a cfg path such as slug.max_length, or a service map option such as slugger.options.max_length.
	 * @param  string  $requirement  What the value must be, such as "an integer >= 1".
	 * @param  mixed  $value  The value found.
	 */
	public function __construct(public readonly string $path, string $requirement, mixed $value) {
		parent::__construct('Slug policy ' . $path . ' must be ' . $requirement . ', got ' . (\is_scalar($value) ? \var_export($value, true) : \get_debug_type($value)) . '.');
	}

}
