<?php
/**
 * The full-page mobile view shown when a book's QR code is scanned.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Frontend;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\CommentRating;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;
use WP_Post;

use function sb_format_date;
use function sb_option;

/**
 * Takes over the entire response (bypassing the active theme's template,
 * though wp_head()/wp_footer()/wp_body_open() still run so the admin
 * bar and other plugins keep working) for exactly one URL: a book's own
 * permalink with "?sb_scan=1" appended -- the URL QrCodeManager encodes
 * into the QR image, so this view is what actually opens when the code
 * is scanned. Every other way of reaching the same permalink (site
 * navigation, search, a direct link without the query var) still
 * renders normally through the theme, where Frontend\BookContentDisplay
 * appends its own details panel as before.
 *
 * Shows cover, title, author, shelf, rating, reading progress, borrow
 * status, and notes, plus three quick-action forms (Update Progress,
 * Borrow, Return) that post to Frontend\BookScanActions and redirect
 * back here -- visible only to a user who can edit the book, so a
 * patron who scans the code sees the same information read-only.
 */
final class BookScanPage implements Hookable {

	/**
	 * Query variable that marks a request as a QR scan, present only in
	 * the URL QrCodeManager encodes into the QR image.
	 */
	public const QUERY_VAR = 'sb_scan';

	/**
	 * Nonce action prefix for this page's three quick-action forms, one
	 * nonce per book (suffixed with its post ID). Shared with
	 * BookScanActions, which verifies against the same prefix.
	 */
	public const NONCE_ACTION_PREFIX = 'sb_scan_action_';

	/**
	 * Nonce field name shared by every quick-action form on this page.
	 */
	public const NONCE_FIELD = 'sb_scan_nonce';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Render the scan page and stop WordPress's normal template loading,
	 * only when the current request is this exact book-scan URL.
	 */
	public function maybe_render(): void {
		if ( ! $this->is_scan_request() ) {
			return;
		}

		$this->render( get_queried_object() );
		exit;
	}

	/**
	 * Whether the current request is a book's own singular page, opened
	 * with the "sb_scan" query var, and isn't password-protected (a
	 * protected book still needs its password prompt, which this page
	 * does not provide, so it defers to the normal template instead).
	 */
	private function is_scan_request(): bool {
		if ( ! is_singular( BookPostType::SLUG ) ) {
			return false;
		}

		// Read-only: this query var only ever selects which template
		// renders the same public page, it never changes any data.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return false;
		}

		return ! post_password_required();
	}

	/**
	 * Print the complete standalone HTML document for one book.
	 */
	private function render( WP_Post $book ): void {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( get_the_title( $book ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'sb-scan-page' ); ?>>
	<?php wp_body_open(); ?>
	<main class="sb-scan">
		<?php echo $this->notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by notice(). ?>

		<div class="sb-scan__card">
			<?php echo $this->cover( $book ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by cover(). ?>

			<h1 class="sb-scan__title"><?php echo esc_html( get_the_title( $book ) ); ?></h1>

			<?php echo $this->fact_row( __( 'Author', 'smartbook' ), $this->authors( $book->ID ) ); ?>
			<?php echo $this->fact_row( __( 'Shelf', 'smartbook' ), $this->shelf( $book->ID ) ); ?>
			<?php echo $this->fact_row( __( 'Rating', 'smartbook' ), $this->rating( $book->ID ) ); ?>

			<?php if ( sb_option( 'enable_reading_tracker', true ) ) : ?>
				<?php echo $this->progress_section( $book->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
			<?php endif; ?>

			<?php if ( sb_option( 'enable_borrow', true ) ) : ?>
				<?php echo $this->borrow_section( $book->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
			<?php endif; ?>

			<?php echo $this->notes( $book->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
		</div>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * A fixed, safe success banner for the "sb_notice" query arg left by
	 * BookScanActions's redirect, or '' when absent/unrecognised.
	 */
	private function notice(): string {
		$key     = isset( $_GET['sb_notice'] ) ? sanitize_key( wp_unslash( $_GET['sb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = $this->notice_message( $key );

		if ( '' === $message ) {
			return '';
		}

		return sprintf( '<p class="sb-scan__notice">%s</p>', esc_html( $message ) );
	}

	/**
	 * Fixed, translated copy for every "sb_notice" query value
	 * BookScanActions can redirect back with. Anything else (query args
	 * are user-controllable) returns '' rather than being echoed.
	 */
	private function notice_message( string $key ): string {
		return match ( $key ) {
			'progress_updated' => __( 'Reading progress updated.', 'smartbook' ),
			'borrowed'          => __( 'Book marked as borrowed.', 'smartbook' ),
			'returned'          => __( 'Book marked as returned.', 'smartbook' ),
			default             => '',
		};
	}

	/**
	 * The book's cover image, or a plain placeholder box when it has none.
	 */
	private function cover( WP_Post $book ): string {
		if ( has_post_thumbnail( $book ) ) {
			return get_the_post_thumbnail( $book, 'medium_large', array( 'class' => 'sb-scan__cover' ) );
		}

		return '<div class="sb-scan__cover sb-scan__cover--placeholder" aria-hidden="true"></div>';
	}

	/**
	 * One label/value row, omitted entirely when the value is empty.
	 *
	 * @param string $value_html Already-escaped value markup.
	 */
	private function fact_row( string $label, string $value_html ): string {
		if ( '' === $value_html ) {
			return '';
		}

		return sprintf(
			'<div class="sb-scan__row"><span class="sb-scan__row-label">%s</span><span class="sb-scan__row-value">%s</span></div>',
			esc_html( $label ),
			$value_html
		);
	}

	/**
	 * Comma-separated author term names, already escaped.
	 */
	private function authors( int $post_id ): string {
		return $this->terms( $post_id, AuthorTaxonomy::SLUG );
	}

	/**
	 * Comma-separated shelf term names, already escaped.
	 */
	private function shelf( int $post_id ): string {
		return $this->terms( $post_id, ShelfTaxonomy::SLUG );
	}

	/**
	 * Comma-separated term names for one taxonomy, already escaped.
	 */
	private function terms( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		return esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * A star rating, already escaped -- the average reader rating
	 * (Services\CommentRating::average(), from front-end commenters' own
	 * ratings), rounded to the nearest whole star. Omitted when nobody
	 * has rated the book yet.
	 */
	private function rating( int $post_id ): string {
		[ $average, $count ] = CommentRating::average( $post_id );

		if ( 0 === $count ) {
			return '';
		}

		$rounded = (int) round( $average );

		return esc_html( str_repeat( '★', $rounded ) . str_repeat( '☆', 5 - $rounded ) );
	}

	/**
	 * The "Reading Progress" block: current status/percentage plus the
	 * "Update Progress" form (only for a user who can edit this book).
	 */
	private function progress_section( int $post_id ): string {
		$progress = max( 0, min( 100, (int) get_post_meta( $post_id, 'sb_progress', true ) ) );
		$status   = (string) get_post_meta( $post_id, 'sb_status', true );
		$options  = BookFields::definitions()['sb_status']['options'] ?? array();
		$label    = ( '' !== $status && isset( $options[ $status ] ) ) ? $options[ $status ] : __( 'Unread', 'smartbook' );

		$html  = '<div class="sb-scan__section">';
		$html .= sprintf( '<h2 class="sb-scan__section-title">%s</h2>', esc_html__( 'Reading Progress', 'smartbook' ) );
		$html .= sprintf(
			'<div class="sb-progress-bar"><div class="sb-progress-bar__track"><div class="sb-progress-bar__fill" style="width:%1$d%%"></div></div><span>%1$d%% &middot; %2$s</span></div>',
			$progress,
			esc_html( $label )
		);

		if ( current_user_can( 'edit_post', $post_id ) ) {
			$html .= $this->progress_form( $post_id, $progress, $status, $options );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * The "Update Progress" form: a 0-100 number field plus a reading
	 * status dropdown, both pre-filled with the current values.
	 *
	 * @param array<string, string> $options Reading-status value => label pairs.
	 */
	private function progress_form( int $post_id, int $progress, string $status, array $options ): string {
		$html  = sprintf(
			'<form class="sb-scan__form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$html .= $this->hidden_fields( $post_id, 'sb_scan_update_progress' );

		$html .= sprintf(
			'<label class="sb-scan__field"><span>%s</span><input type="number" name="sb_progress" min="0" max="100" step="1" value="%d" /></label>',
			esc_html__( 'Progress (%)', 'smartbook' ),
			$progress
		);

		$html .= '<label class="sb-scan__field"><span>' . esc_html__( 'Status', 'smartbook' ) . '</span><select name="sb_status">';

		foreach ( $options as $value => $option_label ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $status, $value, false ),
				esc_html( $option_label )
			);
		}

		$html .= '</select></label>';
		$html .= sprintf( '<button type="submit" class="sb-scan__button">%s</button>', esc_html__( 'Update Progress', 'smartbook' ) );
		$html .= '</form>';

		return $html;
	}

	/**
	 * The "Borrow Status" block: current loan state plus whichever of
	 * the "Borrow"/"Return" forms applies (only for a user who can edit
	 * this book) -- "Borrow" when the book is currently available,
	 * "Return" when it's out on loan, mirroring the same
	 * borrowed-and-not-returned rule Services\BookStats uses.
	 */
	private function borrow_section( int $post_id ): string {
		$borrowed    = '1' === (string) get_post_meta( $post_id, 'sb_borrowed', true );
		$returned    = '1' === (string) get_post_meta( $post_id, 'sb_returned', true );
		$on_loan     = $borrowed && ! $returned;
		$borrowed_to = (string) get_post_meta( $post_id, 'sb_borrowed_to', true );
		$borrow_date = (string) get_post_meta( $post_id, 'sb_borrow_date', true );

		$html  = '<div class="sb-scan__section">';
		$html .= sprintf( '<h2 class="sb-scan__section-title">%s</h2>', esc_html__( 'Borrow Status', 'smartbook' ) );
		$html .= sprintf(
			'<p class="sb-scan__status sb-scan__status--%1$s">%2$s</p>',
			$on_loan ? 'out' : 'available',
			$on_loan ? esc_html( $this->loan_status_text( $borrowed_to, $borrow_date ) ) : esc_html__( 'Available.', 'smartbook' )
		);

		if ( current_user_can( 'edit_post', $post_id ) ) {
			$html .= $on_loan ? $this->return_form( $post_id ) : $this->borrow_form( $post_id );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Human-readable "on loan" status line, not yet escaped.
	 */
	private function loan_status_text( string $borrowed_to, string $borrow_date ): string {
		if ( '' === $borrowed_to ) {
			return __( 'Currently on loan.', 'smartbook' );
		}

		if ( '' === $borrow_date ) {
			/* translators: %s: name of the person the book is borrowed to. */
			return sprintf( __( 'Borrowed by %s.', 'smartbook' ), $borrowed_to );
		}

		/* translators: 1: name of the person the book is borrowed to, 2: formatted borrow date. */
		return sprintf( __( 'Borrowed by %1$s since %2$s.', 'smartbook' ), $borrowed_to, sb_format_date( $borrow_date ) );
	}

	/**
	 * The "Borrow" form: an optional "Borrowed To" name, defaulting
	 * server-side to the current user when left blank.
	 */
	private function borrow_form( int $post_id ): string {
		$html  = sprintf(
			'<form class="sb-scan__form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$html .= $this->hidden_fields( $post_id, 'sb_scan_borrow' );
		$html .= sprintf(
			'<label class="sb-scan__field"><span>%s</span><input type="text" name="sb_borrowed_to" placeholder="%s" /></label>',
			esc_html__( 'Borrowed To (optional)', 'smartbook' ),
			esc_attr__( 'Your name', 'smartbook' )
		);
		$html .= sprintf( '<button type="submit" class="sb-scan__button">%s</button>', esc_html__( 'Borrow', 'smartbook' ) );
		$html .= '</form>';

		return $html;
	}

	/**
	 * The "Return" form: a single confirmation button, no other input.
	 */
	private function return_form( int $post_id ): string {
		$html  = sprintf(
			'<form class="sb-scan__form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$html .= $this->hidden_fields( $post_id, 'sb_scan_return' );
		$html .= sprintf( '<button type="submit" class="sb-scan__button sb-scan__button--secondary">%s</button>', esc_html__( 'Return', 'smartbook' ) );
		$html .= '</form>';

		return $html;
	}

	/**
	 * The hidden action/post-id/nonce fields every quick-action form
	 * shares.
	 */
	private function hidden_fields( int $post_id, string $action ): string {
		$html  = sprintf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		$html .= sprintf( '<input type="hidden" name="sb_post_id" value="%d" />', $post_id );
		$html .= wp_nonce_field( self::NONCE_ACTION_PREFIX . $post_id, self::NONCE_FIELD, true, false );

		return $html;
	}

	/**
	 * The book's free-text notes, already escaped, omitted when unset.
	 */
	private function notes( int $post_id ): string {
		$notes = (string) get_post_meta( $post_id, 'sb_notes', true );

		if ( '' === $notes ) {
			return '';
		}

		$html  = '<div class="sb-scan__section">';
		$html .= sprintf( '<h2 class="sb-scan__section-title">%s</h2>', esc_html__( 'Notes', 'smartbook' ) );
		$html .= sprintf( '<p class="sb-scan__notes">%s</p>', esc_html( $notes ) );
		$html .= '</div>';

		return $html;
	}
}
