<?php
/**
 * Plugin Name: Amazon Simple Email Service
 *
 * Creates a function that send mails through the Amazon Simple Email Service.
 */

/**
 * Function that sends mails.
 *
 * @param string|array $to          Array or comma-separated list of email
 *                                  addresses to send message.
 * @param string       $subject     Email subject.
 * @param string       $message     Message contents.
 * @param string|array $headers     Optional. Additional headers.
 * @param string|array $attachments Optional. Files to attach.
 *
 * @return bool Whether the email contents were sent successfully.
 */
function amazon_ses_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );

	if ( isset( $atts['to'] ) ) {
		$to = $atts['to'];
	}

	if ( ! is_array( $to ) ) {
		$to = explode( ',', $to );
	}

	if ( isset( $atts['subject'] ) ) {
		$subject = $atts['subject'];
	}

	if ( isset( $atts['message'] ) ) {
		$message = $atts['message'];
	}

	if ( isset( $atts['headers'] ) ) {
		$headers = $atts['headers'];
	}

	if ( isset( $atts['attachments'] ) ) {
		$attachments = $atts['attachments'];
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
	}

	$cc       = array();
	$bcc      = array();
	$reply_to = array();

	if ( empty( $headers ) ) {
		$headers = array();
	} else {
		if ( ! is_array( $headers ) ) {
			$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		} else {
			$tempheaders = $headers;
		}

		$headers = array();

		if ( ! empty( $tempheaders ) ) {
			foreach ( (array) $tempheaders as $header ) {
				if ( false === strpos( $header, ':' ) ) {
					if ( false !== stripos( $header, 'boundary=' ) ) {
						$parts    = preg_split( '/boundary=/i', trim( $header ) );
						$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
					}

					continue;
				}

				list( $name, $content ) = explode( ':', trim( $header ), 2 );

				$name    = trim( $name );
				$content = trim( $content );

				switch ( strtolower( $name ) ) {
					case 'from':
						$bracket_pos = strpos( $content, '<' );

						if ( false !== $bracket_pos ) {
							if ( $bracket_pos > 0 ) {
								$from_name = substr( $content, 0, $bracket_pos - 1 );
								$from_name = str_replace( '"', '', $from_name );
								$from_name = trim( $from_name );
							}

							$from_email = substr( $content, $bracket_pos + 1 );
							$from_email = str_replace( '>', '', $from_email );
							$from_email = trim( $from_email );

						} elseif ( '' !== trim( $content ) ) {
							$from_email = trim( $content );
						}
						break;
					case 'content-type':
						if ( false !== strpos( $content, ';' ) ) {
							list( $type, $charset_content ) = explode( ';', $content );

							$content_type = trim( $type );

							if ( false !== stripos( $charset_content, 'charset=' ) ) {
								$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
							} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
								$boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );

								$charset = '';
							}
						} elseif ( '' !== trim( $content ) ) {
							$content_type = trim( $content );
						}
						break;
					case 'cc':
						$cc = array_merge( (array) $cc, explode( ',', $content ) );
						break;
					case 'bcc':
						$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
						break;
					case 'reply-to':
						$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
						break;
					default:
						$headers[ trim( $name ) ] = trim( $content );
						break;
				}
			}
		}
	}

	if ( ! isset( $from_name ) ) {
		$from_name = 'WordPress';
	}

	if ( ! isset( $from_email ) ) {
		$from_email = get_option( 'amazon_ses_from_email' );

		if ( ! $from_email ) {
			if ( isset( $_SERVER['SERVER_NAME'] ) ) { // WPCS: input var okay.
				$sitename = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) ); // WPCS: input var okay.

				if ( 'www.' === substr( $sitename, 0, 4 ) ) {
					$sitename = substr( $sitename, 4 );
				}

				$from_email = 'wordpress@' . $sitename;
			}
		}
	}

	$from_email = apply_filters( 'wp_mail_from', $from_email );
	$from_name  = apply_filters( 'wp_mail_from_name', $from_name );

	if ( ! isset( $content_type ) ) {
		$content_type = 'text/plain';
	}

	$content_type = apply_filters( 'wp_mail_content_type', $content_type );

	if ( ! isset( $charset ) ) {
		$charset = get_bloginfo( 'charset' );
	}

	$charset = apply_filters( 'wp_mail_charset', $charset );

	$raw_message  = 'From: ' . $from_name . ' <' . $from_email . '>' . "\n";
	$raw_message .= 'To: ' . implode( ',', $to ) . "\n";
	$raw_message .= 'Cc: ' . implode( ',', $cc ) . "\n";
	$raw_message .= 'Bcc: ' . implode( ',', $bcc ) . "\n";
	$raw_message .= 'Subject: ' . $subject . "\n";

	if ( ! empty( $attachments ) ) {
		$boundary = uniqid();

		$raw_message .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\n\n";

		foreach ( $attachments as $attachment ) {
			/**
			 * For now, for WP VIP we only support files that start using
			 * data protocol.
			 */
			if ( preg_match( '#^data:(.+)/(.+),(.+)$#sU', $attachment, $matches ) ) {
				$raw_message .= '--' . $boundary . "\n";

				$mime_type = $matches[1] . '/' . $matches[2];
				$name      = 'attachment.' . $matches[2];
				$data      = $matches[3];

				$raw_message .= 'Content-Type: ' . $mime_type . '; name="' . $name . '"' . "\n";
				$raw_message .= 'Content-Transfer-Encoding: base64' . "\n";
				$raw_message .= 'Content-Disposition: attachment; filename="' . $name . '"' . "\n";

				$raw_message .= base64_encode( $data ) . "\n";
			}
		}

		$raw_message .= '--' . $boundary . "\n";
	}

	$raw_message .= 'Content-Type: ' . $content_type . '; charset="' . $charset . '"' . "\n\n";

	$raw_message .= $message;

	$error = amazon_ses_send_raw_email( $raw_message );

	return ! is_wp_error( $error );
}

/**
 * Returns the date in a format that is suitable for making requests.
 *
 * @return string The current date.
 */
function amazon_ses_get_date() {
	return date( 'Ymd\\THis\\Z' );
}

/**
 * Function that sends a Raw Message using the Amazon SES Query API.
 *
 * @param string $raw_message A valid formatted email message.
 *
 * @return WP_Error An error object if some error happened or NULL if everything
 *                  went right.
 */
function amazon_ses_send_raw_email( $raw_message ) {
	$endpoint = amazon_ses_get_endpoint();

	$headers = array(
		'Content-Type' => 'application/x-www-form-urlencoded',
		'Host'         => wp_parse_url( $endpoint, PHP_URL_HOST ),
		'X-Amz-Date'   => amazon_ses_get_date(),
	);

	$body = http_build_query( array(
		'Action'          => 'SendRawEmail',
		'RawMessage.Data' => base64_encode( $raw_message ),
	) );

	$response = wp_remote_post( $endpoint, array(
		'headers' => array_merge( $headers, array(
			'Authorization' => amazon_ses_get_signature( 'POST', '/', '', $headers, $body ),
		) ),
		'body'    => $body,
	) );

	if ( ! is_wp_error( $response ) ) {
		$xml = simplexml_load_string( $response['body'] );

		if ( isset( $xml->SendRawEmailResult->MessageId ) ) {
			$response = (string) $xml->SendRawEmailResult->MessageId;
		} elseif ( isset( $xml->Error ) ) {
			$response = new WP_Error( (string) $xml->Error->Code, (string) $xml->Error->Message );
		} else {
			$response = new WP_Error( 'unknown', __( 'Unknown Error', 'amazon-ses' ) );
		}
	}

	return $response;
}

/**
 * Signs a request made to the Amazon Query API.
 *
 * Note: This function may be used to any Amazon Query request, so maybe in the
 * future it can be moved to a more generic Amazon plugin.
 *
 * @param string $method       The method to use (i.e.: GET or POST).
 * @param string $path         The path to call.
 * @param string $query_string The query string of the call.
 * @param array  $headers      An associative array with the headers.
 * @param string $body         The request body payload.
 *
 * @return string The signature.
 */
function amazon_ses_get_signature( $method, $path, $query_string, $headers, $body ) {
	$region       = get_option( 'amazon_ses_region' );
	$secret_key   = get_option( 'amazon_ses_secret_key' );
	$access_key   = get_option( 'amazon_ses_access_key' );
	$service      = 'ses';
	$date         = date( 'Ymd' );
	$signing      = 'aws4_request';
	$algorithm    = 'AWS4-HMAC-SHA256';
	$access_scope = $date . '/' . $region . '/' . $service . '/' . $signing;
	$header_names = array();

	$canonical_request  = $method . "\n";
	$canonical_request .= $path . "\n";
	$canonical_request .= $query_string . "\n";

	foreach ( $headers as $key => $value ) {
		$header_name = trim( strtolower( $key ) );

		$canonical_request .= $header_name . ':' . trim( $value ) . "\n";

		$header_names[] = $header_name;
	}

	$signed_headers = implode( ';', $header_names );

	$canonical_request .= "\n";
	$canonical_request .= $signed_headers . "\n";
	$canonical_request .= hash( 'sha256', $body );

	$string_to_sign  = $algorithm . "\n";
	$string_to_sign .= ( isset( $headers['X-Amz-Date'] ) ? $headers['X-Amz-Date'] : amazon_ses_get_date() ) . "\n";
	$string_to_sign .= $access_scope . "\n";
	$string_to_sign .= hash( 'sha256', $canonical_request );

	$hashed_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
	$hashed_region  = hash_hmac( 'sha256', $region, $hashed_date, true );
	$hashed_service = hash_hmac( 'sha256', $service, $hashed_region, true );
	$hashed_signing = hash_hmac( 'sha256', $signing, $hashed_service, true );

	$signature = hash_hmac( 'sha256', $string_to_sign, $hashed_signing );

	return $algorithm . ' Credential=' . $access_key . '/' . $access_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
}

/**
 * Returns the endpoint to be used to make API calls.
 *
 * @return string The endpoint.
 */
function amazon_ses_get_endpoint() {
	$region = get_option( 'amazon_ses_region' );

	return 'https://email.' . $region . '.amazonaws.com';
}

/**
 * Returns a list of Amazon Web Services regions.
 *
 * @return array List of regions.
 */
function amazon_ses_get_regions() {
	return array(
		'us-east-1' => __( 'US East (N. Virginia)', 'amazon-ses' ),
		'us-west-2' => __( 'US West (Oregon)', 'amazon-ses' ),
		'eu-west-1' => __( 'EU (Ireland)', 'amazon-ses' ),
	);
}

add_action( 'admin_menu', function() {
	add_menu_page(
		__( 'Amazon SES', 'amazon-ses' ),
		__( 'Amazon SES', 'amazon-ses' ),
		'manage_options',
		'amazon-ses',
		function() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
			}

			?>
<div class="wrap">
	<h2><?php esc_html_e( 'Amazon Simple Email Service', 'amazon-ses' ); ?></h2>
	<form action="options.php" method="post">
		<?php settings_fields( 'amazon_ses' ); ?>
		<?php do_settings_sections( 'amazon_ses' ); ?>
		<?php submit_button(); ?>
	</form>
</div>
			<?php
		},
		'dashicons-email'
	);
} );

add_action( 'admin_init', function() {
	add_settings_section(
		'amazon_ses_settings',
		__( 'Settings', 'amazon-ses' ),
		null,
		'amazon_ses'
	);

	add_settings_field(
		'amazon_ses_region',
		__( 'Region', 'amazon-ses' ),
		function() {
			$regions = amazon_ses_get_regions();
			$region  = get_option( 'amazon_ses_region' );

			print( '<select name="amazon_ses_region" required>' );

			foreach ( $regions as $key => $value ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $key ),
					selected( $key, $region, false ),
					esc_html( $value )
				);
			}

			print( '</select>' );
		},
		'amazon_ses',
		'amazon_ses_settings'
	);

	add_settings_field(
		'amazon_ses_access_key',
		__( 'Access Key', 'amazon-ses' ),
		function() {
			$access_key = get_option( 'amazon_ses_access_key', '' );

			printf(
				'<input type="text" class="widefat" name="amazon_ses_access_key" value="%s" required />',
				esc_attr( $access_key )
			);
		},
		'amazon_ses',
		'amazon_ses_settings'
	);

	add_settings_field(
		'amazon_ses_secret_key',
		__( 'Secret Key', 'amazon-ses' ),
		function() {
			$secret_key = get_option( 'amazon_ses_secret_key', '' );

			printf(
				'<input type="password" class="widefat" name="amazon_ses_secret_key" value="%s" required />',
				esc_attr( $secret_key )
			);
		},
		'amazon_ses',
		'amazon_ses_settings'
	);

	add_settings_field(
		'amazon_ses_from_email',
		__( 'From Email', 'amazon-ses' ),
		function() {
			$from_email = get_option( 'amazon_ses_from_email', '' );

			printf(
				'<input type="email" class="widefat" name="amazon_ses_from_email" value="%s" />',
				esc_attr( $from_email )
			);
		},
		'amazon_ses',
		'amazon_ses_settings'
	);

	register_setting(
		'amazon_ses',
		'amazon_ses_region',
		function( $value ) {
			$regions = amazon_ses_get_regions();
			$region  = '';

			if ( isset( $regions[ $value ] ) ) {
				$region = $value;
			}

			return $region;
		}
	);

	register_setting(
		'amazon_ses',
		'amazon_ses_access_key'
	);

	register_setting(
		'amazon_ses',
		'amazon_ses_secret_key'
	);

	register_setting(
		'amazon_ses',
		'amazon_ses_from_email',
		'sanitize_email'
	);
} );

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Function that overrides the wp_mail function.
	 *
	 * @param string|array $to          Array or comma-separated list of email.
	 *                                  addresses to send message.
	 * @param string       $subject     Email subject.
	 * @param string       $message     Message contents.
	 * @param string|array $headers     Optional. Additional headers.
	 * @param string|array $attachments Optional. Files to attach.
	 *
	 * @return bool Whether the email contents were sent successfully.
	 */
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		return amazon_ses_mail( $to, $subject, $message, $headers, $attachments );
	}
}
