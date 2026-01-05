<?php
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


// Format number language settings
return [

	// Generic / common
	'err_format_number_sign_without_digits' => 'Ugyldigt tal. Fortegn uden cifre.',
	'err_format_number_unsupported_chars' => 'Ugyldigt tal. Indeholder tegn der ikke er tilladt.',
	'err_format_number_multiple_decimal_separators' => 'Ugyldigt tal. Flere decimal-separatorer.',
	'err_format_number_dot_decimal_not_supported' => 'Ugyldigt tal. Punkt-decimal understøttes ikke. Brug komma som decimal-separator.',
	'err_format_number_decimals_not_allowed_scale0' => 'Ugyldigt tal. Decimaler er ikke tilladt for scale=0.',
	'err_format_number_missing_integer_digits' => 'Ugyldigt tal. Mangler heltalscifre.',
	'err_format_number_fraction_must_be_digits' => 'Ugyldigt tal. Decimaldelen må kun indeholde cifre.',
	'err_format_number_too_many_fraction_digits' => 'Ugyldigt tal. For mange decimaler. Maksimalt %SCALE% decimaler er tilladt.',
	'err_format_number_too_many_integer_digits' => 'Ugyldigt tal. For mange heltalscifre til DECIMAL(%PRECISION%,%SCALE%).',

	// fromDb() / DB parsing
	'err_format_number_invalid_scale_fromdb' => 'Ugyldig scale. Skal være >= 0.',
	'err_format_number_db_sign_without_digits' => 'Ugyldigt DB-tal. Fortegn uden cifre.',
	'err_format_number_db_invalid_format' => 'Ugyldigt DB-tal. Forventer punkt-decimal uden tusindtals-separator.',
	'err_format_number_db_too_many_fraction_digits' => 'Ugyldigt DB-tal. For mange decimaler i forhold til ønsket scale=%SCALE%.',

	// Internal validation helpers
	'err_format_number_invalid_precision' => 'Ugyldig precision. Skal være >= 1.',
	'err_format_number_invalid_scale' => 'Ugyldig scale. Skal være mellem 0 og precision.',
	'err_format_number_invalid_thousands_sep' => 'Ugyldig tusindtals-separator. Skal være "", "." eller " ".',
	'err_format_number_invalid_decimal_sep' => 'Ugyldig decimal-separator. Skal være "," eller ".".',
	'err_format_number_invalid_separators_same' => 'Ugyldige separatorer. Tusindtals- og decimal-separator skal være forskellige.',
	'err_format_number_missing_integer_part' => 'Ugyldigt tal. Mangler heltalsdel.',
	'err_format_number_mixed_thousands_seps' => 'Ugyldigt tal. Blandede tusindtals-separatorer understøttes ikke.',
	'err_format_number_dot_grouping_malformed' => 'Ugyldigt tal. Tusindtals-gruppering med "." er forkert.',
	'err_format_number_space_grouping_malformed' => 'Ugyldigt tal. Tusindtals-gruppering med mellemrum er forkert.',
	'err_format_number_integer_must_be_digits' => 'Ugyldigt tal. Heltalsdelen må kun indeholde cifre.',
];
