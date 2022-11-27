	<div class="wrap">
		<form action="options.php" method="POST">
			<?php settings_fields( $this->gcp_storage_cdn_group ); ?>
			<?php do_settings_sections( $this->gcp_storage_cdn_page ); ?>
			<?php submit_button(); ?>
		</form>
	</div>