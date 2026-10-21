<?php
/**
 * My Account → Licenses.
 *
 * Override by copying to yourtheme/beltoft-licenses/myaccount/licenses.php.
 *
 * @package BeltoftLicenses
 *
 * @var array<int, array<string, mixed>> $licenses    Display values, see BeltoftLicenses\Account\View::license().
 * @var string                           $account_url This page's URL.
 */

defined( 'ABSPATH' ) || exit;

if ( ! $licenses ) : ?>
	<p class="beltoft-licenses__empty"><?php esc_html_e( 'You have no licenses yet.', 'beltoft-licenses' ); ?></p>
	<?php
	return;
endif;
?>
<div class="beltoft-licenses">
	<?php foreach ( $licenses as $beltoft_licenses_view ) : ?>
		<section class="beltoft-licenses__license beltoft-licenses__license--<?php echo esc_attr( $beltoft_licenses_view['status'] ); ?>" aria-labelledby="beltoft-license-<?php echo esc_attr( (string) $beltoft_licenses_view['id'] ); ?>">
			<header class="beltoft-licenses__head">
				<h3 class="beltoft-licenses__product" id="beltoft-license-<?php echo esc_attr( (string) $beltoft_licenses_view['id'] ); ?>"><?php echo esc_html( $beltoft_licenses_view['product'] ); ?></h3>
				<span class="beltoft-licenses__status"><?php echo esc_html( $beltoft_licenses_view['status_label'] ); ?></span>
			</header>

			<dl class="beltoft-licenses__facts">
				<?php if ( $beltoft_licenses_view['show_key'] ) : ?>
					<dt><?php esc_html_e( 'License key', 'beltoft-licenses' ); ?></dt>
					<dd>
						<code class="beltoft-licenses__key"><?php echo esc_html( $beltoft_licenses_view['key'] ); ?></code>
						<button type="button" class="beltoft-licenses__copy button" data-beltoft-copy="<?php echo esc_attr( $beltoft_licenses_view['key'] ); ?>"><?php esc_html_e( 'Copy', 'beltoft-licenses' ); ?></button>
					</dd>
				<?php endif; ?>

				<dt><?php esc_html_e( 'Sites', 'beltoft-licenses' ); ?></dt>
				<dd><?php echo esc_html( $beltoft_licenses_view['sites'] ); ?></dd>

				<dt><?php esc_html_e( 'Valid', 'beltoft-licenses' ); ?></dt>
				<dd><?php echo esc_html( $beltoft_licenses_view['expiry'] ); ?></dd>

				<?php if ( '' !== $beltoft_licenses_view['version'] ) : ?>
					<dt><?php esc_html_e( 'Latest version', 'beltoft-licenses' ); ?></dt>
					<dd><?php echo esc_html( $beltoft_licenses_view['version'] ); ?></dd>
				<?php endif; ?>
			</dl>

			<?php if ( $beltoft_licenses_view['domains'] ) : ?>
				<ul class="beltoft-licenses__sites">
					<?php foreach ( $beltoft_licenses_view['domains'] as $beltoft_licenses_domain ) : ?>
						<li class="beltoft-licenses__site">
							<span class="beltoft-licenses__domain"><?php echo esc_html( $beltoft_licenses_domain ); ?></span>
							<form method="post" action="<?php echo esc_url( $account_url ); ?>">
								<?php wp_nonce_field( 'beltoft_licenses_free_site_' . $beltoft_licenses_view['id'] ); ?>
								<input type="hidden" name="beltoft_licenses_free_site" value="1">
								<input type="hidden" name="license_id" value="<?php echo esc_attr( (string) $beltoft_licenses_view['id'] ); ?>">
								<input type="hidden" name="domain" value="<?php echo esc_attr( $beltoft_licenses_domain ); ?>">
								<button type="submit" class="beltoft-licenses__free button"><?php esc_html_e( 'Free this site', 'beltoft-licenses' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="beltoft-licenses__actions">
				<?php if ( $beltoft_licenses_view['can_download'] ) : ?>
					<a class="beltoft-licenses__download button" href="<?php echo esc_url( $beltoft_licenses_view['download_url'] ); ?>">
						<?php
						/* translators: %s: version. */
						echo esc_html( sprintf( __( 'Download %s', 'beltoft-licenses' ), $beltoft_licenses_view['version'] ) );
						?>
					</a>
				<?php endif; ?>
				<?php if ( '' !== $beltoft_licenses_view['subscription'] ) : ?>
					<a class="beltoft-licenses__subscription" href="<?php echo esc_url( $beltoft_licenses_view['subscription'] ); ?>"><?php esc_html_e( 'Manage subscription', 'beltoft-licenses' ); ?></a>
				<?php endif; ?>
			</p>

			<?php
			/**
			 * Lets plugins built on top of this one add their own panel to a license, such as
			 * the controls of a provisioned service.
			 *
			 * @param array<string, mixed> $license The license.
			 * @param array<string, mixed> $view    Its display values.
			 */
			do_action( 'beltoft_licenses_account_license', $beltoft_licenses_view['license'], $beltoft_licenses_view );
			?>
		</section>
	<?php endforeach; ?>
</div>
