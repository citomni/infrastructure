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

return [
	'package' => 'citomni/infrastructure',
	'version' => 1,
	'files' => [
		[
			'target' => 'var/secrets/app.secret.dev.php',
			'source' => 'install/scaffold/var/secrets/app.secret.dev.php.stub',
			'type' => 'secret-file',
			'policy' => 'create-only',
		],
		[
			'target' => 'var/secrets/app.secret.stage.php',
			'source' => 'install/scaffold/var/secrets/app.secret.stage.php.stub',
			'type' => 'secret-file',
			'policy' => 'create-only',
		],
		[
			'target' => 'var/secrets/app.secret.prod.php',
			'source' => 'install/scaffold/var/secrets/app.secret.prod.php.stub',
			'type' => 'secret-file',
			'policy' => 'create-only',
		],
	],
];
