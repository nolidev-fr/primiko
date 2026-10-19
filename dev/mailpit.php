<?php
/**
 * Test site only: sends every email to Mailpit, from a valid address
 * (the default wordpress@localhost is refused).
 *
 * @package Primiko
 */

add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host     = 'mailpit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Port     = 1025; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAuth = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
);

add_filter(
	'wp_mail_from',
	static function () {
		return 'site@couverture.test';
	}
);
