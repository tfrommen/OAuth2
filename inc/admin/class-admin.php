<?php

namespace WP\OAuth2\Admin;

use WP\OAuth2\Client;
use WP_Error;

class Admin {
	const BASE_SLUG = 'rest-oauth2-apps';

	/**
	 * Register the admin page
	 */
	public static function register() {
		/**
		 * Include anything we need that relies on admin classes/functions
		 */
		include_once dirname( __FILE__ ) . '/class-listtable.php';

		$hook = add_users_page(
			__( 'Registered OAuth Applications', 'oauth2' ),
			_x( 'Applications', 'menu title', 'oauth2' ),
			'list_users',
			self::BASE_SLUG,
			[ get_class(), 'dispatch' ]
		);

		add_action( 'load-' . $hook, [ get_class(), 'load' ] );
	}

	/**
	 * Get the URL for an admin page.
	 *
	 * @param array|string $params Map of parameter key => value, or wp_parse_args string.
	 *
	 * @return string Requested URL.
	 */
	protected static function get_url( $params = [] ) {
		$url    = admin_url( 'users.php' );
		$params = [ 'page' => self::BASE_SLUG ] + wp_parse_args( $params );

		return add_query_arg( urlencode_deep( $params ), $url );
	}

	/**
	 * Get the current page action.
	 *
	 * @return string One of 'add', 'edit', 'delete', or '' for default (list)
	 */
	protected static function current_action() {
		return isset( $_GET['action'] ) ? $_GET['action'] : '';
	}

	/**
	 * Load data for our page.
	 */
	public static function load() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
				self::render_edit_page();
				break;

			case 'delete':
				self::handle_delete();
				break;

			case 'regenerate':
				self::handle_regenerate();
				break;

			case 'approve':
				self::handle_approve();
				break;

			default:
				global $wp_list_table;

				$wp_list_table = new ListTable();

				$wp_list_table->prepare_items();

				return;
		}

	}

	public static function dispatch() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
			case 'delete':
			case 'approve':
				break;

			default:
				self::render();
				break;
		}
	}

	/**
	 * Render the list page.
	 */
	public static function render() {
		global $wp_list_table;

		?>
		<div class="wrap">
			<h2>
				<?php
				esc_html_e( 'Registered Applications', 'oauth2' );

				if ( current_user_can( 'create_users' ) ): ?>
					<a href="<?php echo esc_url( self::get_url( 'action=add' ) ) ?>"
					   class="add-new-h2"><?php echo esc_html_x( 'Add New', 'application', 'oauth2' ); ?></a>
					<?php
				endif;
				?>
			</h2>
			<?php
			if ( ! empty( $_GET['deleted'] ) ) {
				echo '<div id="message" class="updated"><p>' . esc_html__( 'Deleted application.', 'oauth2' ) . '</p></div>';
			} elseif ( ! empty( $_GET['approved'] ) ) {
				echo '<div id="message" class="updated"><p>' . esc_html__( 'Approved application.', 'oauth2' ) . '</p></div>';
			}
			?>

			<?php $wp_list_table->views(); ?>

			<form action="" method="get">

				<?php $wp_list_table->search_box( __( 'Search Applications', 'oauth2' ), 'oauth2' ); ?>

				<?php $wp_list_table->display(); ?>

			</form>

			<br class="clear"/>

		</div>
		<?php
	}

	/**
	 * Validates given parameters.
	 *
	 * @param array $params RAW parameters.
	 * @return array|WP_Error Validated parameters, or error on failure.
	 */
	protected static function validate_parameters( $params ) {
		$valid = [];

		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'rest_oauth2_missing_name', __( 'Client name is required', 'oauth2' ) );
		}
		$valid['name'] = wp_filter_post_kses( $params['name'] );

		if ( empty( $params['description'] ) ) {
			return new WP_Error( 'rest_oauth2_missing_description', __( 'Client description is required', 'oauth2' ) );
		}
		$valid['description'] = wp_filter_post_kses( $params['description'] );

		if ( empty( $params['type'] ) ) {
			return new WP_Error( 'rest_oauth2_missing_type', __( 'Type is required.', 'oauth2' ) );
		}
		$valid['type'] = wp_filter_post_kses( $params['type'] );

		if ( empty( $params['callback'] ) ) {
			return new WP_Error( 'rest_oauth2_missing_callback', __( 'Client callback is required and must be a valid URL.', 'oauth2' ) );
		}
		if ( ! empty( $params['callback'] ) ) {
			$valid['callback'] = $params['callback'];
		}

		return $valid;
	}

	/**
	 * Handle submission of the add page
	 *
	 * @param Client $consumer
	 *
	 * @return array|null List of errors. Issues a redirect and exits on success.
	 */
	protected static function handle_edit_submit( Client $consumer = null ) {
		$messages = [];
		if ( empty( $consumer ) ) {
			$did_action = 'add';
			check_admin_referer( 'rest-oauth2-add' );
		} else {
			$did_action = 'edit';
			check_admin_referer( 'rest-oauth2-edit-' . $consumer->get_post_id() );
		}

		// Check that the parameters are correct first
		$params = self::validate_parameters( wp_unslash( $_POST ) );

		if ( is_wp_error( $params ) ) {
			$messages[] = $params->get_error_message();

			return $messages;
		}

		if ( empty( $consumer ) ) {
			// Create the consumer
			$data     = [
				'name'        => $params['name'],
				'description' => $params['description'],
				'meta'        => [
					'type'     => $params['type'],
					'callback' => $params['callback'],
				],
			];

			$consumer = $result = Client::create( $data );
		} else {
			// Update the existing consumer post
			$data   = [
				'name'        => $params['name'],
				'description' => $params['description'],
				'meta'        => [
					'type'     => $params['type'],
					'callback' => $params['callback'],
				],
			];

			$result = $consumer->update( $data );
		}

		if ( is_wp_error( $result ) ) {
			$messages[] = $result->get_error_message();

			return $messages;
		}

		// Success, redirect to alias page
		$location = self::get_url(
			[
				'action'     => 'edit',
				'id'         => $consumer->get_post_id(),
				'did_action' => $did_action,
			]
		);
		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * Output alias editing page
	 */
	public static function render_edit_page() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'oauth2' ) );
		}

		// Are we editing?
		$consumer          = null;
		$form_action       = self::get_url( 'action=add' );
		$regenerate_action = '';
		if ( ! empty( $_REQUEST['id'] ) ) {
			$id       = absint( $_REQUEST['id'] );
			$consumer = Client::get_by_post_id( $id );
			if ( is_wp_error( $consumer ) || empty( $consumer ) ) {
				wp_die( __( 'Invalid client ID.', 'oauth2' ) );
			}

			$form_action       = self::get_url( [ 'action' => 'edit', 'id' => $id ] );
			$regenerate_action = self::get_url( [ 'action' => 'regenerate', 'id' => $id ] );
		}

		// Handle form submission
		$messages = [];
		if ( ! empty( $_POST['submit'] ) ) {
			$messages = self::handle_edit_submit( $consumer );
		}
		if ( ! empty( $_GET['did_action'] ) ) {
			switch ( $_GET['did_action'] ) {
				case 'edit':
					$messages[] = __( 'Updated application.', 'oauth2' );
					break;

				case 'regenerate':
					$messages[] = __( 'Regenerated secret.', 'oauth2' );
					break;

				default:
					$messages[] = __( 'Successfully created application.', 'oauth2' );
					break;
			}
		}

		$data = [];

		if ( empty( $consumer ) || ! empty( $_POST['_wpnonce'] ) ) {
			foreach ( [ 'name', 'description', 'callback', 'type' ] as $key ) {
				$data[ $key ] = empty( $_POST[ $key ] ) ? '' : wp_unslash( $_POST[ $key ] );
			}
		} else {
			$data['name']        = $consumer->get_name();
			$data['description'] = $consumer->get_description( true );
			$data['type']        = $consumer->get_type();
			$data['callback']    = $consumer->get_redirect_uris();

			if ( is_array( $data['callback'] ) ) {
				$data['callback'] = implode( ',', $data['callback'] );
			}
		}

		// Header time!
		global $title, $parent_file, $submenu_file;
		$title        = $consumer ? __( 'Edit Application', 'oauth2' ) : __( 'Add Application', 'oauth2' );
		$parent_file  = 'users.php';
		$submenu_file = self::BASE_SLUG;

		include( ABSPATH . 'wp-admin/admin-header.php' );
		?>

		<div class="wrap">
			<h2 id="edit-site"><?php echo esc_html( $title ) ?></h2>

			<?php
			if ( ! empty( $messages ) ) {
				foreach ( $messages as $msg ) {
					echo '<div id="message" class="updated"><p>' . esc_html( $msg ) . '</p></div>';
				}
			}
			?>

			<form method="post" action="<?php echo esc_url( $form_action ) ?>">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="oauth-name"><?php echo esc_html_x( 'Client Name', 'field name', 'oauth2' ) ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="name" id="oauth-name" value="<?php echo esc_attr( $data['name'] ) ?>"/>
							<p class="description"><?php esc_html_e( 'This is shown to users during authorization and in their profile.', 'oauth2' ) ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="oauth-description"><?php echo esc_html_x( 'Description', 'field name', 'oauth2' ) ?></label>
						</th>
						<td>
						<textarea class="regular-text" name="description" id="oauth-description" cols="30" rows="5" style="width: 500px"><?php echo esc_textarea( $data['description'] ) ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php echo esc_html_x( 'Type', 'field name', 'oauth2' ) ?>
						</th>
						<td>
							<ul>
								<li>
									<input
										type="radio"
										name="type"
										value="private"
										id="oauth-type-private"
										<?php checked( 'private', $data['type'] ); ?>
									/>
									<label for="oauth-type-private">
										<?php echo esc_html_x( 'Private', 'Client type select option', 'oauth2' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e(
											'Clients capable of maintaining confidentiality of credentials, such as server-side applications',
											'oauth2'
										) ?>
									</p>
								</li>
								<li>
									<input
										type="radio"
										name="type"
										value="public"
										id="oauth-type-public"
										<?php checked( 'public', $data['type'] ); ?>
									/>
									<label for="oauth-type-public">
										<?php echo esc_html_x( 'Public', 'Client type select option', 'oauth2' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e(
											'Clients incapable of keeping credentials secret, such as browser-based applications or desktop and mobile apps',
											'oauth2'
										) ?>
									</p>
								</li>
							</ul>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="oauth-callback"><?php echo esc_html_x( 'Callback', 'field name', 'oauth2' ) ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="callback" id="oauth-callback" value="<?php echo esc_attr( $data['callback'] ) ?>"/>
							<p class="description"><?php esc_html_e( "Your application's callback URI or a list of comma separated URIs. The callback passed with the request token must match the scheme, host, port, and path of this URL.", 'oauth2' ) ?></p>
						</td>
					</tr>
				</table>

				<?php

				if ( empty( $consumer ) ) {
					wp_nonce_field( 'rest-oauth2-add' );
					submit_button( __( 'Create Client', 'oauth2' ) );
				} else {
					echo '<input type="hidden" name="id" value="' . esc_attr( $consumer->get_post_id() ) . '" />';
					wp_nonce_field( 'rest-oauth2-edit-' . $consumer->get_post_id() );
					submit_button( __( 'Save Client', 'oauth2' ) );
				}

				?>
			</form>

			<?php if ( ! empty( $consumer ) ) : ?>
				<form method="post" action="<?php echo esc_url( $regenerate_action ) ?>">
					<h3><?php esc_html_e( 'OAuth Credentials', 'oauth2' ) ?></h3>

					<table class="form-table">
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Client Key', 'oauth2' ) ?>
							</th>
							<td>
								<code><?php echo esc_html( $consumer->get_id() ) ?></code>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Client Secret', 'oauth2' ) ?>
							</th>
							<td>
								<code><?php echo esc_html( $consumer->get_secret() ) ?></code>
							</td>
						</tr>
					</table>

					<?php
					wp_nonce_field( 'rest-oauth2-regenerate:' . $consumer->get_post_id() );
					submit_button( __( 'Regenerate Secret', 'oauth2' ), 'delete' );
					?>
				</form>
			<?php endif ?>
		</div>

		<?php
	}

	/**
	 * Delete the client.
	 */
	public static function handle_delete() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = absint( $_GET['id'] );
		check_admin_referer( 'rest-oauth2-delete:' . $id );

		if ( ! current_user_can( 'delete_post', $id ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?', 'oauth2' ) . '</h1>' .
				'<p>' . __( 'You are not allowed to delete this application.', 'oauth2' ) . '</p>',
				403
			);
		}

		$client = Client::get_by_post_id( $id );
		if ( is_wp_error( $client ) ) {
			wp_die( $client );

			return;
		}

		if ( ! $client->delete() ) {
			$message = 'Invalid client ID';
			wp_die( $message );

			return;
		}

		wp_safe_redirect( self::get_url( 'deleted=1' ) );
		exit;
	}

	/**
	 * Approve the client.
	 */
	public static function handle_approve() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = absint( $_GET['id'] );
		check_admin_referer( 'rest-oauth2-approve:' . $id );

		if ( ! current_user_can( 'publish_post', $id ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?', 'oauth2' ) . '</h1>' .
				'<p>' . __( 'You are not allowed to approve this application.', 'oauth2' ) . '</p>',
				403
			);
		}

		$client = Client::get_by_post_id( $id );
		if ( is_wp_error( $client ) ) {
			wp_die( $client );
		}

		$did_approve = $client->approve();
		if ( is_wp_error( $did_approve ) ) {
			wp_die( $did_approve );
		}

		wp_safe_redirect( self::get_url( 'approved=1' ) );
		exit;
	}

	/**
	 * Regenerate the client secret.
	 */
	public static function handle_regenerate() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = absint( $_GET['id'] );
		check_admin_referer( 'rest-oauth2-regenerate:' . $id );

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?', 'oauth2' ) . '</h1>' .
				'<p>' . __( 'You are not allowed to edit this application.', 'oauth2' ) . '</p>',
				403
			);
		}

		$client = Client::get_by_post_id( $id );
		$result = $client->regenerate_secret();
		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message() );
		}

		wp_safe_redirect( self::get_url( [ 'action' => 'edit', 'id' => $id, 'did_action' => 'regenerate' ] ) );
		exit;
	}
}
