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

namespace CitOmni\Infrastructure\Controller;

use CitOmni\Kernel\Controller\BaseController;

class InfrastructureController extends BaseController {


/*
 *------------------------------------------------------------------
 * BASIC START-UP
 *------------------------------------------------------------------
 * The common fundamentals that are required for all public pages. 
 * 
 */
	protected function init(): void {
		// Start-up work (kept intentionally minimal).
	}


/*
 *------------------------------------------------------------------
 * PUBLIC PAGES
 *------------------------------------------------------------------
 * 
 */

	/**
	 * Handle contact form display and submission.
	 *
	 * Behavior:
	 * - Collect inputs from POST, normalize, and pass to CrmMessageModel::create().
	 * - Treat any 2xx model response as success; clear CSRF token afterward.
	 * - On 422, join multiple error messages with <br> and retain retype values.
	 * - Render the contact template with canonical URL, CSRF token, and feedback.
	 *
	 * Notes:
	 * - Submit detection uses "!== null" so the literal "0" is not treated as false.
	 * - Messages come from txt service; fallback string is provided for generic errors.
	 *
	 * Typical usage:
	 *   Invoked by GET/POST on "/kontakt.html" to show/process the public contact form.
	 *
	 * Examples:
	 *
	 *   // Core: Successful submission
	 *   // POST -> model returns 200; success_msg set; CSRF cleared; form re-rendered with notice
	 *
	 *   // Scenario: Validation error
	 *   // Model returns 422; messages joined with <br>; inputs preserved in retype_values
	 *
	 * Failure:
	 * - Exceptions from services (view, txt, request, etc.) bubble to ErrorHandler.
	 * - Server/IO failures are logged to "contact_errors.jsonl" when context is present.
	 *
	 * @return void
	 * @throws \Throwable Unhandled failure in services or rendering.
	 */
	public function contact(): void {
		$error_msg		= '';
		$success_msg	= '';
		$retype_values	= [];

        // Check if form was submitted
		if ($this->app->request->post('submit') !== null) {
			// Collect POST values
			$data = [
				'msg_subject'            => (string)$this->app->request->post('msg_subject'),
				'msg_body'               => (string)$this->app->request->post('msg_body'),
				'msg_from_name'          => (string)$this->app->request->post('msg_from_name'),
				'msg_from_company_name'  => (string)$this->app->request->post('msg_from_company_name'),
				'msg_from_company_no'    => (string)$this->app->request->post('msg_from_company_no'),
				'msg_from_email'         => (string)$this->app->request->post('msg_from_email'),
				'msg_from_phone'         => (string)$this->app->request->post('msg_from_phone'),
				'csrf_token'             => (string)$this->app->request->post($this->app->security->csrfFieldName()),
				'captcha'                => (string)$this->app->request->post('captcha'),
				'honeypot'               => (string)$this->app->request->post('website_hp'),
				'msg_from_ip'            => (string)$this->app->request->ip(),
			];

			$model  = new \CitOmni\Infrastructure\Model\CrmMessageModel($this->app);
			$result = $model->create($data);

			// Normalize result
			$result = \is_array($result) ? $result : [];
			$code   = (int)($result['response'] ?? 500);
			$msgRaw = $result['msg'] ?? '';
			$msg    = \is_string($msgRaw) ? $msgRaw : '';

			// Success (any 2xx)
			if ($code >= 200 && $code < 300) {
				// Success: Set user success message
				$success_msg = $msg;

				// Clear the CSRF token after successful form submission to prevent reuse
				$this->app->security->clearCsrf();

			} else {
				// Collect user-friendly error message(s)
				if ($code === 422) {
					if (\is_string($msg) && \str_contains($msg, '|')) {
						$error_msg = \str_replace('|', '<br>', $msg);
					} elseif (\is_array($msgRaw)) {
						$error_msg = \implode('<br>', \array_map('strval', $msgRaw));
					} else {
						$error_msg = (string)$msg;
					}
				} else {
					$error_msg = ($msg !== '')
						? $msg
						: $this->app->txt->get('err_generic', 'contact', 'citomni/infrastructure', 'An unexpected error occurred.');
				}

				// Retain entered values for better UX (keep numeric "0", drop null/empty strings)
				$retype_values = \array_filter($data, static fn($v) => $v !== null && (!\is_string($v) || \trim($v) !== ''));

				// Log server-side errors for easier tracking and debugging
				if (!empty($result['error_msg'])) {
					$this->app->log->write(
						'contact_errors.jsonl',
						'error',
						(string)$result['error_msg'],
						['ip' => (string)$this->app->request->ip()]
					);
				}
			}

		}

		// Render the contact form view, passing form state and feedback variables
		$this->app->tplEngine->render((string)$this->routeConfig['template_file'] . "@" . (string)$this->routeConfig['template_layer'], [
				
				// Controls whether to add <meta name="robots" content="noindex"> in the template (1 = add, 0 = do not add)
				'noindex'       => 0,
				
				// Canonical URL
				'canonical'     => \CITOMNI_PUBLIC_ROOT_URL . '/kontakt.html',
				
				// Provide a CSRF token for form submissions
				'token'         => $this->app->security->generateCsrf(),
				
				// Error message for form validation or processing
				'error_msg'     => $error_msg,
				
				// Success message shown after successful submission
				'success_msg'   => $success_msg,
				
				// Retained form values in case of validation error
				'retype_values' => $retype_values,
			]
		);
	}


	/**
	 * Generate a PNG CAPTCHA image and terminate.
	 *
	 * Behavior:
	 * - Create a 6-character challenge and store it in session.
	 * - Draw noise (dots + lines) and render glyphs (TTF if available, else GD fallback).
	 * - Clear output buffers, set anti-cache headers, output PNG, release session, and exit.
	 *
	 * Notes:
	 * - Binary endpoint: No template; uses "inline" Content-Disposition to avoid downloads.
	 * - Uses random_int() for robust randomness and disables caching via "no-store".
	 *
	 * Typical usage:
	 *   Invoked by GET on "/captcha.png" as referenced by the contact form template.
	 *
	 * Examples:
	 *
	 *   // Core: Browser requests /captcha.png
	 *   // Controller emits PNG and exits; session now contains the challenge
	 *
	 *   // Scenario: No FreeType available
	 *   // Built-in GD font is used; readability remains acceptable
	 *
	 * Failure:
	 * - If GD/headers/session fail, exceptions bubble to ErrorHandler; partial output is avoided by buffer clearing.
	 *
	 * @return void
	 * @throws \Throwable Unhandled failure in GD, headers, or session handling.
	 */
	public function captcha(): void {
	
		// 1) Generate a random CAPTCHA code without confusing characters
		$stringLength = 6;
		$alphabet = '234578ABCDEFGHJKLMNPQRSTUVWXYZ';
		$captchaCode = '';
		for ($i = 0; $i < $stringLength; $i++) {
			$captchaCode .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
		}
		$this->app->session->set('captcha', $captchaCode);

		// Create an empty image/canvas with true color
		$width  = 150;
		$height = 50;
		$img = \imagecreatetruecolor($width, $height);

		// Set the background in our image
		$bg = \imagecolorallocate($img, 241, 245, 248);
		\imagefilledrectangle($img, 0, 0, $width, $height, $bg);

		// 3) Add noise to make it harder for bots to read the captcha code
		// Add background noise with random dots
		for ($i = 0; $i < 300; $i++) {
			$nc = \imagecolorallocate($img, \rand(180, 250), \rand(180, 250), \rand(180, 250));
			\imagesetpixel($img, \rand(0, $width - 1), \rand(0, $height - 1), $nc);
		}
		// Add background noise with random lines
		for ($i = 0; $i < 10; $i++) {
			$lc = \imagecolorallocate($img, \rand(100, 200), \rand(100, 200), \rand(100, 200));
			\imageline(
				$img,
				\rand(0, $width - 1), \rand(0, $height - 1),
				\rand(0, $width - 1), \rand(0, $height - 1),
				$lc
			);
		}

		// 4) Set text colors
		$c1 = \imagecolorallocate($img, 68, 68, 68);
		$c2 = \imagecolorallocate($img, 13, 71, 161);
		$textColors = [$c1, $c2];

		// 5) Draw text (TTF if available, else GD fallback)
		$hasTtf = \function_exists('imagettftext');
		if ($hasTtf) {
			$fonts = [
				__DIR__ . '/../../assets/fonts/Roboto-Regular.ttf',
				__DIR__ . '/../../assets/fonts/RobotoSlab-Regular.ttf',
			];
			$fonts = \array_values(\array_filter($fonts, static fn(string $f) => \is_readable($f)));
		}

		if ($hasTtf && $fonts !== []) {
			$initial = 16;
			$letterSpace = (int)\floor(135 / $stringLength);
			for ($i = 0; $i < $stringLength; $i++) {
				\imagettftext(
					$img,
					(int)\rand(12, 17),  // Random font size
					(int)\rand(-10, 10),  // Random rotation angle
					(int)($initial + $i * $letterSpace),  // X-coordinate position
					(int)\rand(27, 38),  // Y-coordinate position
					$textColors[\rand(0, 1)],  // Random text color
					$fonts[\array_rand($fonts)],  // Random font selection
					$captchaCode[$i]
				);
			}
		} else {
			// Fallback without FreeType
			$font = 5; // built-in GD font
			$x = 12;
			for ($i = 0; $i < $stringLength; $i++) {
				$y = \rand(15, 25);
				\imagestring($img, $font, $x, $y, $captchaCode[$i], $textColors[\rand(0, 1)]);
				$x += 20;
			}
		}

		// 6) Release session lock (avoid blocking next request)
		if (\PHP_SESSION_ACTIVE === \session_status()) {
			@\session_write_close();
		}

		// 7) Clear all output buffers (no stray bytes around PNG)
		while (\ob_get_level() > 0) {
			@\ob_end_clean();
		}

		// 8) Set anti-cache headers
		if (!\headers_sent()) {
			\header('Content-Type: image/png');
			\header('Content-Disposition: inline; filename="captcha.png"');
			\header('Cache-Control: no-store, max-age=0');
			\header('Pragma: no-cache');
			\header('Expires: 0');
			\header('X-Content-Type-Options: nosniff');
		}
		
		// 9) Output the image
		\imagepng($img);
		
		// 10) Free up memory
		\imagedestroy($img);

		// 11) Flush FastCGI quickly (optional) and hard-stop
		if (\function_exists('fastcgi_finish_request')) {
			\fastcgi_finish_request();
		}
		exit;
	}
	
	
}
