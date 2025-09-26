<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Infrastructure - Lean cross-mode infrastructure services for CitOmni applications (HTTP & CLI).
 * Source:  https://github.com/citomni/infrastructure
 * License: See the LICENSE file for full terms.
 */




// Contact-page language settings
return [

		// Metadata
		'meta_title' => 'Kontakt os | CitOmni',
		'meta_description' => 'Kontakt os for spørgsmål, support eller mere info om vores løsninger. Ring, skriv eller udfyld vores formular – vi vender tilbage hurtigst muligt!',
		'meta_keywords' => 'kontakt,formular,demo',


		// Page content
		'header' => 'Kontakt os',
		'bodytext' => 'Har du spørgsmål, brug for support eller ønsker at høre mere om vores løsninger? Vi står klar til at hjælpe dig!',


		
		// Form labels
		'lbl_your_subject' => 'Emne',
		'lbl_your_msg' => 'Din besked',
		'lbl_your_name' => 'Dit navn',
		'lbl_your_company_name' => 'Virksomhed',
		'lbl_your_company_no' => 'CVR-nummer',
		'lbl_your_company_no_short' => 'CVR-nr',
		'lbl_your_email' => 'Din email-adresse',
		'lbl_your_email_never_share' => 'Vi deler ikke din email-adresse med nogen.',
		'lbl_your_phone' => 'Dit telefonnummer',
		
		'lbl_bank' => 'Bank',
		'lbl_bank_account' => 'Konto',
		'lbl_bank_regno' => 'Regnr.',
		'lbl_bank_accountno' => 'Kontonr.',
		'lbl_bank_iban' => 'IBAN',
		'lbl_bank_swift' => 'SWIFT/BIC',
		
		'lbl_send_msg' => 'Send besked',
		
		// Contact info column texts		
		'header_contact_info' => 'Kontaktinformation',
		'header_address_info' => 'Træf os på kontoret',
		'header_phone_info' => 'Ring til os',
		'header_email_info' => 'Skriv til os',
		'header_legal_info' => 'Juridisk information',
		'header_bank_info' => 'Bankforbindelse',

		
		
		// Form placeholders
		'placehold_your_subject' => 'Vælg emne',
		'placehold_your_msg' => 'Skriv din besked her',
		'placehold_your_name' => 'Indtast dit navn',
		'placehold_your_company_name' => 'Indtast virksomhedens navn',
		'placehold_your_company_no' => 'Indtast CVR-nummer',
		'placehold_your_email' => 'Indtast din email',
		'placehold_your_phone' => 'Indtast telefonnummer',
		'placehold_captcha' => 'Indtast koden',
		
		
		// Email-labels	
		'lbl_from'    => 'Afsender',
		'lbl_name'    => 'Navn',
		'lbl_company' => 'Firma',
		'lbl_cvr'     => 'CVR',
		'lbl_phone'   => 'Telefon',
		'lbl_email'   => 'Email',
		'lbl_subject' => 'Emne',
		'lbl_message' => 'Besked',		
		
				
		// Error messages
		'form_general_error' => 'Der opstod en fejl, da systemet skulle håndtere din besked. Prøv venligst igen eller kontakt os på telefonen.',
			
		'err_subject_required'    => 'Emne skal udfyldes.',
		'err_body_required'       => 'Besked skal udfyldes.',
		'err_name_required'       => 'Navn skal udfyldes.',
		'err_invalid_email'       => 'Ugyldig email.',
		
		'err_invalid_csrf'        => 'Ugyldig CSRF token.',
		'err_invalid_captcha'     => 'Forkert eller manglende CAPTCHA.',
		'err_honeypot'            => 'Formularen blev udfyldt forkert.',
		
		'err_db_save'             => 'Beskeden kunne ikke gemmes. Prøv igen senere.',
		'err_db_exception'        => 'Der opstod en fejl under lagring af beskeden.',
		'err_mail_failed'         => 'Beskeden blev gemt, men mailen kunne ikke sendes.',		
		
		'err_generic'             => 'Der opstod en uventet fejl. Prøv venligst igen.',		
		
		// Success messages
		'form_send_success' => 'Din besked er nu sendt til os. Vi svarer hurtigst muligt. Tak fordi du kontakter os!',		
		
		'form_send_success_email_subject' => 'Ny kontaktbesked fra %APP_NAME%',
		'form_send_success_email_body' => 'En besøgende har sendt nedenstående via kontaktformularen på %APP_NAME%: <br><br>',
		
		
		// Captcha
		'enter_captcha_code' => 'Indtast captcha-koden',
		'enter_captcha_code_please' => 'Indtast venligst captcha-koden',

];
