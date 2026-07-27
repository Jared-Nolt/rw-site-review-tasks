<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Maker_Dashboard {

	public static function init() {
		add_shortcode( 'srt_maker_dashboard', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets() {
		wp_register_style( 'srt-dashboard', SRT_PLUGIN_URL . 'assets/dashboard.css', array(), '1.0.0' );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Please log in to view your review tasks.', 'rw-site-review-tasks' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'rw-site-review-tasks' )
			);
		}

		wp_enqueue_style( 'srt-dashboard' );

		$user_id = get_current_user_id();
		$args    = array(
			'post_type'      => SRT_CPT_Company::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		if ( get_option( 'srt_restrict_to_maker', 1 ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => 'maker',
					'value' => $user_id,
				),
			);
		}

		$companies = get_posts( $args );

		$sections = array(
			'needs_work' => array(),
			'upcoming'   => array(),
			'completed'  => array(),
		);

		foreach ( $companies as $company ) {
			$task = self::get_latest_task( $company->ID );

			if ( ! $task ) {
				continue;
			}

			$slug = srt_get_task_tag_slug( $task->ID );
			$row  = array(
				'company_title' => get_the_title( $company->ID ),
				'task_id'       => $task->ID,
				'tag_slug'      => $slug,
			);

			if ( 'needs_work' === $slug ) {
				$sections['needs_work'][] = $row;
			} elseif ( 'completed' === $slug ) {
				$sections['completed'][] = $row;
			} else {
				$sections['upcoming'][] = $row;
			}
		}

		// Within Upcoming, surface Overdue tiles before Ready ones.
		usort(
			$sections['upcoming'],
			function ( $a, $b ) {
				return ( 'overdue' === $a['tag_slug'] ? 0 : 1 ) <=> ( 'overdue' === $b['tag_slug'] ? 0 : 1 );
			}
		);

		ob_start();
		?>
		<div class="srt-dashboard">
			<?php
			self::render_section( __( 'Needs Work', 'rw-site-review-tasks' ), $sections['needs_work'] );
			self::render_section( __( 'Upcoming', 'rw-site-review-tasks' ), $sections['upcoming'] );
			self::render_section( __( 'Completed', 'rw-site-review-tasks' ), $sections['completed'] );
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function get_latest_task( $company_id ) {
		$tasks = get_posts(
			array(
				'post_type'      => SRT_CPT_Task::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => 'company',
						'value' => $company_id,
					),
				),
			)
		);

		if ( ! $tasks ) {
			return null;
		}

		// Sort by Period (Y-m-d) descending. Tasks with no Period fall back to
		// their creation date so manually created tasks aren't silently dropped
		// (they surface as "Ready" via srt_get_task_tag_slug()).
		usort(
			$tasks,
			function ( $a, $b ) {
				$a_key = get_field( 'period', $a->ID );
				$b_key = get_field( 'period', $b->ID );
				$a_key = $a_key ? $a_key : substr( $a->post_date, 0, 10 );
				$b_key = $b_key ? $b_key : substr( $b->post_date, 0, 10 );
				return strcmp( (string) $b_key, (string) $a_key );
			}
		);

		return $tasks[0];
	}

	private static function render_section( $title, $rows ) {
		?>
		<div class="srt-dashboard-section">
			<h2 class="srt-dashboard-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="srt-dashboard-empty"><?php esc_html_e( 'Nothing here.', 'rw-site-review-tasks' ); ?></p>
			<?php else : ?>
				<div class="srt-tile-grid">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$url = srt_task_page_url( $row['task_id'] );
						$url = $url ? $url : '#';
						?>
						<div class="srt-tile srt-tag-<?php echo esc_attr( $row['tag_slug'] ); ?>">
							<a class="srt-tile-link" href="<?php echo esc_url( $url ); ?>">
								<span class="srt-tile-company"><?php echo esc_html( $row['company_title'] ); ?></span>
								<span class="srt-tile-tag"><?php echo esc_html( srt_get_task_tag_label( $row['tag_slug'] ) ); ?></span>
							</a>
							<a class="srt-tile-pdf" href="<?php echo esc_url( SRT_Task_Print::url( $row['task_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF', 'rw-site-review-tasks' ); ?>
							</a>
							<a class="srt-tile-pdf" href="<?php echo esc_url( SRT_Task_Print::mg_url( $row['task_id'] ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF (MG)', 'rw-site-review-tasks' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

SRT_Maker_Dashboard::init();
