<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #326 Protection report screen.
 *
 * Sibling of Bulk Prices and Grants. Read-only. The capability gate is
 * Grant_Admin::can_mutate() (manage_woocommerce and edit_products). No new
 * capability, no mutation, no outbound request.
 */
final class Protection_Report_Admin {
	public const SLUG = 'writeleash-protection-report';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/** Woo-first placement, same fallbacks as Bulk Prices and Grants. */
	public static function menu(): void {
		$title = __( 'WriteLeash Protection', 'writeleash' );
		if ( post_type_exists( 'product' ) ) {
			add_submenu_page( 'edit.php?post_type=product', $title, esc_html__( 'Protection', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} elseif ( function_exists( 'wc_get_product' ) ) {
			add_submenu_page( 'woocommerce', $title, esc_html__( 'Protection', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( $title, esc_html__( 'WriteLeash Protection', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function can_view(): bool {
		return Grant_Admin::can_mutate();
	}

	public static function render(): void {
		if ( ! self::can_view() ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'You are not allowed to view the protection report.', 'writeleash' ) . '</p></div>';
			return;
		}
		$window = Protection_Report_Query::normalize_window( filter_input( INPUT_GET, 'wl_window' ) );
		$page = Protection_Report_Query::normalize_page( filter_input( INPUT_GET, 'wl_page' ) );
		$report = Protection_Report_Query::load( $window, $page );
		$window = (int) $report['window_days'];
		$page = (int) $report['page'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Protection report', 'writeleash' ); ?></h1>
			<p><?php esc_html_e( 'This report stays on this site. It counts writes WriteLeash refused for a restricted account, and it lists standing grants that are still active. It does not show request bodies, and it does not send anything off the site.', 'writeleash' ); ?></p>
			<nav aria-label="<?php esc_attr_e( 'Report window', 'writeleash' ); ?>">
				<ul class="subsubsub">
					<?php
					$links = array();
					foreach ( Protection_Report_Query::WINDOWS as $days ) {
						$label = sprintf(
							/* translators: %d: number of days in the report window. */
							_n( '%d day', '%d days', $days, 'writeleash' ),
							$days
						);
						$class = $days === $window ? ' class="current" aria-current="page"' : '';
						$links[] = '<li><a href="' . esc_url( self::url( $days, 1 ) ) . '"' . $class . '>' . esc_html( $label ) . '</a></li>';
					}
					echo wp_kses( implode( ' | ', $links ), array( 'li' => array(), 'a' => array( 'href' => true, 'class' => true, 'aria-current' => true ) ) );
					?>
				</ul>
			</nav>
			<br class="clear" />
			<?php if ( empty( $report['log_enabled'] ) ) { ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Refusal lines are written only when WP_DEBUG_LOG is enabled. Until then this report cannot count blocked attempts, even if a write was refused.', 'writeleash' ); ?></p></div>
			<?php } ?>
			<?php if ( ! empty( $report['log_capped'] ) ) { ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'The denial log was larger than this screen reads. Older lines were left out of the counts.', 'writeleash' ); ?></p></div>
			<?php } ?>
			<h2><?php esc_html_e( 'Blocked attempts', 'writeleash' ); ?></h2>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: blocked attempt count, 2: window length in days. */
					__( 'Blocked attempts in the last %2$d days: %1$d.', 'writeleash' ),
					(int) $report['blocked'],
					$window
				) );
				?>
			</p>
			<?php if ( ! empty( $report['empty'] ) ) { ?>
				<p><?php esc_html_e( 'Nothing has been refused in this window. Blocked attempts show up here after a restricted account is used. Issue a standing grant, then use that account for a product write outside its grant job. The table will show the time, the user, the surface, the product id, and the reason code.', 'writeleash' ); ?></p>
			<?php } else { ?>
				<table class="widefat striped">
					<caption class="screen-reader-text"><?php esc_html_e( 'Blocked attempts by surface', 'writeleash' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Surface', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Blocked attempts', 'writeleash' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['surfaces'] as $surface => $count ) { ?>
						<tr>
							<td><code><?php echo esc_html( (string) $surface ); ?></code> <?php echo esc_html( self::surface_label( (string) $surface ) ); ?></td>
							<td><?php echo esc_html( (string) (int) $count ); ?></td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			<?php } ?>
			<h2><?php esc_html_e( 'Active grants', 'writeleash' ); ?></h2>
			<?php if ( empty( $report['grants_readable'] ) ) { ?>
				<p><?php esc_html_e( 'The grant store could not be read. Denial counts above are separate, and no grant budget is shown.', 'writeleash' ); ?></p>
			<?php } else { ?>
				<p>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: active grant count, 2: grants expiring inside the selected window. */
						__( 'Active grants: %1$d. Expiring in this window: %2$d.', 'writeleash' ),
						(int) $report['active_grant_count'],
						(int) $report['expiring_grant_count']
					) );
					?>
				</p>
				<p><?php esc_html_e( 'An active grant is still restricting its account: status active, expiry in the future, restriction not removed. Used is the consumed budget. Remaining is the maximum minus used and reserved.', 'writeleash' ); ?></p>
				<?php if ( 0 === (int) $report['active_grant_count'] ) { ?>
					<p><?php esc_html_e( 'No standing grant is active, so no account is restricted yet.', 'writeleash' ); ?></p>
				<?php } ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Grant', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actor', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Used', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reserved', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remaining', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Maximum', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Expires (UTC)', 'writeleash' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $report['grants'] ) { ?>
							<tr><td colspan="7"><?php esc_html_e( 'No active grants to list.', 'writeleash' ); ?></td></tr>
						<?php } else { ?>
							<?php foreach ( $report['grants'] as $grant ) { ?>
							<tr>
								<td><code><?php echo esc_html( substr( (string) $grant['public_id'], 0, 8 ) ); ?></code></td>
								<td><?php echo esc_html( self::actor_text( (int) $grant['actor_id'] ) ); ?></td>
								<td><?php echo esc_html( (string) (int) $grant['budget_used'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $grant['budget_reserved'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $grant['budget_remaining'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $grant['budget_max'] ); ?></td>
								<td>
									<?php echo esc_html( (string) $grant['expires_at'] ); ?>
									<?php if ( ! empty( $grant['expiring_soon'] ) ) { ?>
										<span class="description"><?php esc_html_e( 'Expiring in this window', 'writeleash' ); ?></span>
									<?php } ?>
								</td>
							</tr>
							<?php } ?>
						<?php } ?>
					</tbody>
				</table>
				<?php if ( ! empty( $report['grants_list_limited'] ) ) { ?>
					<p class="description"><?php esc_html_e( 'The list shows the grants that expire soonest. The counts above include the rest.', 'writeleash' ); ?></p>
				<?php } ?>
			<?php } ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Grant_Admin::SLUG ) ); ?>"><?php esc_html_e( 'Manage grants', 'writeleash' ); ?></a></p>
			<h2><?php esc_html_e( 'Recent refusals', 'writeleash' ); ?></h2>
			<?php if ( ! empty( $report['empty'] ) ) { ?>
				<p><?php esc_html_e( 'No refusal rows in this window.', 'writeleash' ); ?></p>
			<?php } else { ?>
				<p class="description"><?php esc_html_e( 'REST refusals store the actor user id in the product column. That is the object id on the denial line. Other surfaces store the product id.', 'writeleash' ); ?></p>
				<?php if ( ! empty( $report['list_capped'] ) ) { ?>
					<p class="description"><?php esc_html_e( 'The table shows the newest refusals in this window. The counts include every refusal in the lines that were read.', 'writeleash' ); ?></p>
				<?php } ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time (UTC)', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actor', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Surface', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product ID', 'writeleash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'writeleash' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['rows'] as $row ) { ?>
						<tr>
							<td><?php echo esc_html( (string) $row['time'] ); ?></td>
							<td><?php echo esc_html( self::actor_text( (int) $row['actor_id'] ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['surface'] ); ?></code></td>
							<td><?php echo esc_html( (string) (int) $row['product_id'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['reason'] ); ?></code></td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
				<?php if ( (int) $report['pages'] > 1 ) { ?>
				<nav aria-label="<?php esc_attr_e( 'Refusal pages', 'writeleash' ); ?>">
					<p>
						<?php if ( $page > 1 ) { ?>
							<a href="<?php echo esc_url( self::url( $window, $page - 1 ) ); ?>"><?php esc_html_e( 'Previous page', 'writeleash' ); ?></a>
						<?php } ?>
						<?php
						echo esc_html( sprintf(
							/* translators: 1: current page, 2: page count. */
							__( 'Page %1$d of %2$d', 'writeleash' ),
							$page,
							(int) $report['pages']
						) );
						?>
						<?php if ( $page < (int) $report['pages'] ) { ?>
							<a href="<?php echo esc_url( self::url( $window, $page + 1 ) ); ?>"><?php esc_html_e( 'Next page', 'writeleash' ); ?></a>
						<?php } ?>
					</p>
				</nav>
				<?php } ?>
			<?php } ?>
		</div>
		<?php
	}

	/** Actor is only a WordPress user id plus the display name. No email or login. */
	public static function actor_text( int $user_id ): string {
		$label = '#' . $user_id;
		if ( $user_id < 1 || ! function_exists( 'get_userdata' ) ) { return $label; }
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) { return $label; }
		$name = isset( $user->display_name ) && is_string( $user->display_name ) ? $user->display_name : '';
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		if ( ! is_string( $name ) || '' === $name ) { return $label; }
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 80 );
		} else {
			$name = substr( $name, 0, 80 );
		}
		if ( '' === $name ) { return $label; }
		return $label . ' ' . $name;
	}

	private static function url( int $window, int $page ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&wl_window=' . $window . '&wl_page=' . $page );
	}

	private static function surface_label( string $surface ): string {
		$labels = array(
			'rest' => __( 'WooCommerce REST', 'writeleash' ),
			'product_save' => __( 'Product save', 'writeleash' ),
			'variation_save' => __( 'Variation save', 'writeleash' ),
			'product_set_stock' => __( 'Product stock', 'writeleash' ),
			'variation_set_stock' => __( 'Variation stock', 'writeleash' ),
			'update_product_stock_query' => __( 'Stock query', 'writeleash' ),
			'update_post_metadata' => __( 'Update meta', 'writeleash' ),
			'add_post_metadata' => __( 'Add meta', 'writeleash' ),
			'delete_post_metadata' => __( 'Delete meta', 'writeleash' ),
			'pre_trash_post' => __( 'Trash', 'writeleash' ),
			'pre_delete_post' => __( 'Delete', 'writeleash' ),
			'unrecognized' => __( 'Unrecognized', 'writeleash' ),
		);
		return $labels[ $surface ] ?? '';
	}
}
