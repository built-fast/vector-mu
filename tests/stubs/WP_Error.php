<?php
/**
 * A minimal WP_Error for unit tests, which run without WordPress.
 */

declare(strict_types=1);

// phpcs:ignoreFile -- a stand-in for core's class, named as core names it.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public function __construct(
			public string|int $code = '',
			public string $message = '',
			public mixed $data = ''
		) {
		}

		public function get_error_code(): string|int {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}
