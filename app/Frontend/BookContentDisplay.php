<?php
/**
 * Frontend book details panel.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\CommentRating;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\CollectionTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\PublisherTaxonomy;
use SmartBook\Taxonomies\SeriesTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;
use WP_Comment;

use function sb_format_currency;
use function sb_format_date;
use function sb_option;

/**
 * Dresses up a single book's own page (the page its QR code links to):
 * a hero (cover, byline, genre/format badges, rating, price, reading
 * progress) prepended before the post content, a bibliographic details
 * panel and any Summary/Notes appended after it, and a photo gallery
 * (the "sb_gallery" meta AddBookPage/EditBookPage's media picker
 * writes) at the very end. Also opens comments on every published book
 * for logged-in users only (force_comments_open()/require_login()) --
 * the theme's own comments_template() call still decides where on the
 * page the actual comment list/form appears, same as it does for any
 * other post type -- and lets a commenter attach a 1-5 star rating to
 * their comment (add_rating_field()/save_comment_rating()/
 * append_comment_rating()), the same pattern WooCommerce's own product
 * reviews use for star ratings on top of ordinary comments.
 *
 * Only ever shown on the book's own singular page, never in a loop/
 * archive/excerpt context (is_singular()/in_the_loop()/is_main_query()
 * guard this). Deliberately never surfaces the Borrow Management fields
 * (who a copy is lent to, borrow/return/reminder dates) or the
 * Favorite/Wishlist flags -- those are library-management bookkeeping
 * for site admins, not something to broadcast to an arbitrary visitor.
 */
final class BookContentDisplay implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_filter( 'the_content', array( $this, 'append_panel' ) );
		add_filter( 'comments_open', array( $this, 'force_comments_open' ), 10, 2 );
		add_filter( 'pre_option_comment_registration', array( $this, 'require_login_for_book_comments' ) );
		add_filter( 'comment_form_fields', array( $this, 'add_rating_field' ) );
		add_action( 'comment_post', array( $this, 'save_comment_rating' ) );
		add_filter( 'comment_text', array( $this, 'append_comment_rating' ), 10, 2 );
	}

	/**
	 * Every published book accepts comments, regardless of its own
	 * stored "comment_status" -- necessary because that status is fixed
	 * at creation time from the post type's default, so any book created
	 * before "comments" was added to BookPostType's supports would
	 * otherwise stay stuck closed forever.
	 */
	public function force_comments_open( bool $open, int $post_id ): bool {
		if ( BookPostType::SLUG !== get_post_type( $post_id ) ) {
			return $open;
		}

		return 'publish' === get_post_status( $post_id );
	}

	/**
	 * Require a logged-in account to actually post a book comment
	 * (reading existing comments stays open to everyone), by making
	 * WordPress's own "comment_registration" option -- which
	 * comment_form() and wp-comments-post.php both already check --
	 * read as "on" specifically for a book, regardless of the sitewide
	 * Settings > Discussion value.
	 *
	 * Checked two different ways because this same option is consulted
	 * in two different contexts that don't share one signal: is_singular()
	 * identifies the book while comment_form() is rendering the page, but
	 * wp-comments-post.php (processing the actual submission) never runs
	 * the normal query/template cycle, so is_singular() is always false
	 * there -- $_POST['comment_post_ID'] is what it relies on instead.
	 *
	 * @param mixed $value The site's actual "comment_registration" option value.
	 */
	public function require_login_for_book_comments( mixed $value ): mixed {
		$post_id = 0;

		if ( isset( $_POST['comment_post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only lookup of which post this is about; wp-comments-post.php itself owns the actual nonce/capability checks for the submission.
			$post_id = absint( $_POST['comment_post_ID'] );
		} elseif ( is_singular( BookPostType::SLUG ) ) {
			$post_id = get_queried_object_id();
		}

		if ( $post_id > 0 && BookPostType::SLUG === get_post_type( $post_id ) ) {
			return '1';
		}

		return $value;
	}

	/**
	 * Add a 1-5 star rating field to the comment form on a book's own
	 * page. Inserted ahead of the "comment" textarea so it reads as
	 * "rate, then write your comment". Left out entirely on every other
	 * post type's comment form.
	 *
	 * @param array<string, string> $fields Comment form fields, keyed by field name.
	 *
	 * @return array<string, string>
	 */
	public function add_rating_field( array $fields ): array {
		if ( ! is_singular( BookPostType::SLUG ) ) {
			return $fields;
		}

		return array_merge( array( CommentRating::META_KEY => $this->rating_field_html() ), $fields );
	}

	/**
	 * Build the star-rating radio group's markup. A pure-CSS widget (see
	 * sb-public.css' ".sb-comment-rating" rules) -- the radio inputs are
	 * in descending value order specifically so the ":checked ~ label"/
	 * "label:hover ~ label" sibling-selector trick can light up "this
	 * star and everything before it" using only CSS, no JavaScript.
	 */
	private function rating_field_html(): string {
		$stars = '';

		for ( $value = 5; $value >= 1; $value-- ) {
			$stars .= sprintf(
				'<input type="radio" id="sb-comment-rating-%1$d" name="%2$s" value="%1$d" /><label for="sb-comment-rating-%1$d" title="%3$s">&#9733;</label>',
				$value,
				esc_attr( CommentRating::META_KEY ),
				esc_attr(
					sprintf(
						/* translators: %d: number of stars, 1-5. */
						_n( '%d star', '%d stars', $value, 'smartbook' ),
						$value
					)
				)
			);
		}

		return sprintf(
			'<p class="comment-form-rating"><label>%1$s</label><span class="sb-comment-rating">%2$s</span></p>',
			esc_html__( 'Your Rating', 'smartbook' ),
			$stars
		);
	}

	/**
	 * Save a comment's submitted star rating as comment meta, if the
	 * comment is on a book and a valid 1-5 value was submitted.
	 */
	public function save_comment_rating( int $comment_id ): void {
		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof WP_Comment || BookPostType::SLUG !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		if ( ! isset( $_POST[ CommentRating::META_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-comments-post.php has already run its own nonce/referer checks by the time the "comment_post" action fires; this only reads one more field off that same already-validated submission.
			return;
		}

		$rating = absint( wp_unslash( $_POST[ CommentRating::META_KEY ] ) );

		if ( $rating < 1 || $rating > 5 ) {
			return;
		}

		update_comment_meta( $comment_id, CommentRating::META_KEY, $rating );
	}

	/**
	 * Append the star rating (if any) after a book comment's own text.
	 * Left untouched on every other post type's comments.
	 *
	 * @param string          $comment_text Comment content, ready for display.
	 * @param WP_Comment|null $comment      The comment being displayed.
	 */
	public function append_comment_rating( string $comment_text, ?WP_Comment $comment ): string {
		if ( ! $comment instanceof WP_Comment || BookPostType::SLUG !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $comment_text;
		}

		$rating = (int) get_comment_meta( $comment->comment_ID, CommentRating::META_KEY, true );

		if ( $rating < 1 || $rating > 5 ) {
			return $comment_text;
		}

		return $comment_text . sprintf(
			'<p class="sb-comment-rating-display" aria-label="%1$s">%2$s</p>',
			esc_attr(
				sprintf(
					/* translators: %d: rating out of 5. */
					__( '%d out of 5 stars', 'smartbook' ),
					$rating
				)
			),
			esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) )
		);
	}

	/**
	 * Render the average reader rating (Services\CommentRating::average(),
	 * from commenters' own star ratings) plus how many ratings it's based
	 * on. '' when nobody has rated yet.
	 */
	private function render_average_rating( int $post_id ): string {
		[ $average, $count ] = CommentRating::average( $post_id );

		if ( 0 === $count ) {
			return '';
		}

		$rounded = (int) round( $average );

		return sprintf(
			'<p class="sb-book-hero__reader-rating"><span class="sb-book-panel__rating" aria-hidden="true">%1$s</span> <span class="sb-book-hero__reader-rating-text">%2$s</span></p>',
			esc_html( str_repeat( '★', $rounded ) . str_repeat( '☆', 5 - $rounded ) ),
			esc_html(
				sprintf(
					/* translators: 1: average rating out of 5, one decimal place, 2: number of reader ratings it's based on. */
					_n( '%1$s average (%2$d rating)', '%1$s average (%2$d ratings)', $count, 'smartbook' ),
					number_format_i18n( $average, 1 ),
					$count
				)
			)
		);
	}

	/**
	 * Wrap the post content with the hero (before) and details
	 * panel/gallery (after), on a book's own singular page, in the main
	 * loop only.
	 */
	public function append_panel( string $content ): string {
		if ( ! is_singular( BookPostType::SLUG ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		return $this->render_borrow_notice()
			. $this->render_hero( $post_id )
			. $content
			. $this->render_details_panel( $post_id )
			. $this->render_gallery( $post_id );
	}

	/**
	 * A one-time result banner for a just-submitted borrow or return
	 * request (see Frontend\BorrowRequestController's redirects), read
	 * from its "sb_borrow_notice" query flag. Read-only and nothing
	 * here mutates state, so no nonce is needed to display it.
	 */
	private function render_borrow_notice(): string {
		if ( ! isset( $_GET['sb_borrow_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$code = sanitize_key( wp_unslash( $_GET['sb_borrow_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'requested'                => array( 'success', __( 'Your request to borrow this book has been submitted. An admin will review it soon.', 'smartbook' ) ),
			'unavailable'              => array( 'error', __( 'This book is currently unavailable to request.', 'smartbook' ) ),
			'already_requested'        => array( 'error', __( 'This book already has a pending request.', 'smartbook' ) ),
			'return_requested'         => array( 'success', __( 'Your return request has been submitted. An admin will confirm it soon.', 'smartbook' ) ),
			'not_your_book'            => array( 'error', __( 'This book isn\'t currently borrowed to you.', 'smartbook' ) ),
			'return_already_requested' => array( 'error', __( 'A return has already been requested for this book.', 'smartbook' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}

		[ $type, $message ] = $messages[ $code ];

		return sprintf(
			'<div class="sb-book-notice sb-book-notice--%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Build the "at a glance" hero: cover, byline, genre/format badges,
	 * price, reader-rating summary, and (when enabled) a reading-status/
	 * progress block.
	 */
	private function render_hero( int $post_id ): string {
		$html  = '<div class="sb-book-hero">';
		$html .= $this->cover( $post_id );
		$html .= '<div class="sb-book-hero__info">';

		$badges = $this->badges( $post_id );

		if ( '' !== $badges ) {
			$html .= sprintf( '<div class="sb-book-hero__badges">%s</div>', $badges );
		}

		$authors = $this->terms( $post_id, AuthorTaxonomy::SLUG );

		if ( '' !== $authors ) {
			$html .= sprintf(
				'<p class="sb-book-hero__authors">%s</p>',
				sprintf(
					/* translators: %s: comma-separated author names, already escaped. */
					esc_html__( 'by %s', 'smartbook' ),
					$authors
				)
			);
		}

		$price = $this->price( $post_id );

		if ( '' !== $price ) {
			$html .= sprintf( '<p class="sb-book-hero__meta">%s</p>', $price );
		}

		$html .= $this->render_average_rating( $post_id );
		$html .= $this->render_availability( $post_id );

		if ( sb_option( 'enable_reading_tracker', true ) ) {
			$html .= $this->reading_block( $post_id );
		}

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Genre + format pills, already escaped, empty when the book has
	 * neither.
	 */
	private function badges( int $post_id ): string {
		$badges = array();

		foreach ( $this->term_list( $post_id, GenreTaxonomy::SLUG ) as $genre ) {
			$badges[] = sprintf( '<span class="sb-book-badge">%s</span>', esc_html( $genre ) );
		}

		$format = $this->format_label( $post_id );

		if ( '' !== $format ) {
			$badges[] = sprintf( '<span class="sb-book-badge sb-book-badge--format">%s</span>', esc_html( $format ) );
		}

		return implode( '', $badges );
	}

	/**
	 * Reading-status badge plus progress bar, already escaped. Omits the
	 * status badge when unset and the progress bar when 0%, and returns
	 * '' entirely when there is nothing to show.
	 */
	private function reading_block( int $post_id ): string {
		$status   = $this->status_label( $post_id );
		$progress = $this->progress( $post_id );

		if ( '' === $status && '' === $progress ) {
			return '';
		}

		$html = '<div class="sb-book-hero__reading">';

		if ( '' !== $status ) {
			$html .= sprintf( '<span class="sb-book-badge sb-book-badge--status">%s</span>', $status );
		}

		if ( '' !== $progress ) {
			$html .= $progress;
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Availability status/action: "Currently Borrowed" for anyone else's
	 * loan, or the viewer's own loan status (see render_own_loan_status())
	 * when it's borrowed to themself -- see is_borrowed_by_current_user()
	 * -- a "Request Pending" notice (worded differently for the requester
	 * themself than for anyone else), a "Request to Borrow" button for a
	 * logged-in visitor when the book is actually available, or a login
	 * prompt for a logged-out one. '' entirely when Borrow Management
	 * is disabled (Settings\Settings' "enable_borrow").
	 */
	private function render_availability( int $post_id ): string {
		if ( ! sb_option( 'enable_borrow', true ) ) {
			return '';
		}

		if ( $this->is_borrowed( $post_id ) ) {
			if ( $this->is_borrowed_by_current_user( $post_id ) ) {
				return $this->render_own_loan_status( $post_id );
			}

			return sprintf(
				'<p class="sb-book-hero__availability sb-book-hero__availability--borrowed">%s</p>',
				esc_html__( 'Currently Borrowed', 'smartbook' )
			);
		}

		$requester_id = (int) get_post_meta( $post_id, 'sb_borrow_request_user', true );

		if ( $requester_id > 0 ) {
			$message = get_current_user_id() === $requester_id
				? __( 'Your request to borrow this book is pending approval.', 'smartbook' )
				: __( 'Request Pending', 'smartbook' );

			return sprintf(
				'<p class="sb-book-hero__availability sb-book-hero__availability--pending">%s</p>',
				esc_html( $message )
			);
		}

		if ( is_user_logged_in() ) {
			return $this->render_request_form( $post_id );
		}

		return sprintf(
			'<p class="sb-book-hero__availability"><a href="%s">%s</a></p>',
			esc_url( wp_login_url( (string) get_permalink( $post_id ) ) ),
			esc_html__( 'Log in to Borrow this book', 'smartbook' )
		);
	}

	/**
	 * The "Request to Borrow" form, posting to
	 * Frontend\BorrowRequestController::ACTION.
	 */
	private function render_request_form( int $post_id ): string {
		$html  = sprintf( '<form method="post" action="%s" class="sb-borrow-request-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		$html .= wp_nonce_field( BorrowRequestController::nonce_action( $post_id ), BorrowRequestController::NONCE_NAME, true, false );
		$html .= sprintf( '<input type="hidden" name="action" value="%s" />', esc_attr( BorrowRequestController::ACTION ) );
		$html .= sprintf( '<input type="hidden" name="sb_post_id" value="%d" />', $post_id );
		$html .= sprintf( '<button type="submit" class="sb-borrow-request-form__submit">%s</button>', esc_html__( 'Request to Borrow', 'smartbook' ) );
		$html .= '</form>';

		return $html;
	}

	/**
	 * The viewer's own loan status: "You Borrowed This Book" plus either
	 * a "Return Book" button (no return requested yet) or a
	 * "Return requested -- awaiting approval" status (sb_return_request
	 * already set by BorrowRequestController::handle_return_request()).
	 */
	private function render_own_loan_status( int $post_id ): string {
		$html  = sprintf(
			'<p class="sb-book-hero__availability sb-book-hero__availability--borrowed">%s</p>',
			esc_html__( 'You Borrowed This Book', 'smartbook' )
		);

		if ( '1' === (string) get_post_meta( $post_id, 'sb_return_request', true ) ) {
			$html .= sprintf(
				'<p class="sb-book-hero__availability sb-book-hero__availability--pending">%s</p>',
				esc_html__( 'Return requested -- awaiting approval.', 'smartbook' )
			);
		} else {
			$html .= $this->render_return_form( $post_id );
		}

		return $html;
	}

	/**
	 * The "Return Book" form, posting to
	 * Frontend\BorrowRequestController::RETURN_ACTION.
	 */
	private function render_return_form( int $post_id ): string {
		$html  = sprintf( '<form method="post" action="%s" class="sb-borrow-request-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		$html .= wp_nonce_field( BorrowRequestController::return_nonce_action( $post_id ), BorrowRequestController::RETURN_NONCE_NAME, true, false );
		$html .= sprintf( '<input type="hidden" name="action" value="%s" />', esc_attr( BorrowRequestController::RETURN_ACTION ) );
		$html .= sprintf( '<input type="hidden" name="sb_post_id" value="%d" />', $post_id );
		$html .= sprintf( '<button type="submit" class="sb-borrow-request-form__submit">%s</button>', esc_html__( 'Return Book', 'smartbook' ) );
		$html .= '</form>';

		return $html;
	}

	/**
	 * Whether a book is currently on loan.
	 */
	private function is_borrowed( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, 'sb_borrowed', true )
			&& '1' !== (string) get_post_meta( $post_id, 'sb_returned', true );
	}

	/**
	 * Whether the logged-in visitor is themself who this book is
	 * borrowed to. Only ever true for a loan that started from an
	 * approved request (BookFields::user_options()'s id-keyed
	 * "sb_borrowed_to" -- see its own doc comment): a free-typed name
	 * from the book scan page's "Borrow" quick action can't be reliably
	 * tied to a specific account, so that case always reads as "someone
	 * else has it", never as "you".
	 */
	private function is_borrowed_by_current_user( int $post_id ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$borrowed_to = (string) get_post_meta( $post_id, 'sb_borrowed_to', true );

		return '' !== $borrowed_to && ctype_digit( $borrowed_to ) && (int) $borrowed_to === get_current_user_id();
	}

	/**
	 * Build the bibliographic details panel (shelf, publisher, series,
	 * collection, ISBNs, pages, edition, language, condition, purchase
	 * date) plus any Summary/Notes text, omitted entirely when every
	 * field is empty.
	 */
	private function render_details_panel( int $post_id ): string {
		$rows  = $this->render_row( __( 'Shelf', 'smartbook' ), $this->terms( $post_id, ShelfTaxonomy::SLUG ) );
		$rows .= $this->render_row( __( 'Publisher', 'smartbook' ), $this->terms( $post_id, PublisherTaxonomy::SLUG ) );
		$rows .= $this->render_row( __( 'Series', 'smartbook' ), $this->terms( $post_id, SeriesTaxonomy::SLUG ) );
		$rows .= $this->render_row( __( 'Collection', 'smartbook' ), $this->terms( $post_id, CollectionTaxonomy::SLUG ) );
		$rows .= $this->render_row( __( 'ISBN-10', 'smartbook' ), $this->field( $post_id, 'sb_isbn' ) );
		$rows .= $this->render_row( __( 'Pages', 'smartbook' ), $this->field( $post_id, 'sb_pages' ) );
		$rows .= $this->render_row( __( 'Edition', 'smartbook' ), $this->field( $post_id, 'sb_edition' ) );
		$rows .= $this->render_row( __( 'Language', 'smartbook' ), $this->field( $post_id, 'sb_language' ) );
		$rows .= $this->render_row( __( 'Condition', 'smartbook' ), $this->choice_label( $post_id, 'sb_condition' ) );
		$rows .= $this->render_row( __( 'Purchase Date', 'smartbook' ), $this->date_field( $post_id, 'sb_purchase_date' ) );

		$summary = (string) get_post_meta( $post_id, 'sb_summary', true );
		$notes   = (string) get_post_meta( $post_id, 'sb_notes', true );

		if ( '' === $rows && '' === $summary && '' === $notes ) {
			return '';
		}

		$html  = '<div class="sb-book-panel">';
		$html .= sprintf( '<h2 class="sb-book-panel__title">%s</h2>', esc_html__( 'Book Details', 'smartbook' ) );

		if ( '' !== $rows ) {
			$html .= '<dl class="sb-book-panel__list">' . $rows . '</dl>';
		}

		if ( '' !== $summary ) {
			$html .= sprintf( '<h3 class="sb-book-panel__notes-title">%s</h3>', esc_html__( 'Summary', 'smartbook' ) );
			$html .= sprintf( '<p class="sb-book-panel__notes">%s</p>', esc_html( $summary ) );
		}

		if ( '' !== $notes ) {
			$html .= sprintf( '<h3 class="sb-book-panel__notes-title">%s</h3>', esc_html__( 'Notes', 'smartbook' ) );
			$html .= sprintf( '<p class="sb-book-panel__notes">%s</p>', esc_html( $notes ) );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * A grid of the book's gallery photos (the "sb_gallery" meta the Add/
	 * Edit Book media picker writes). Each thumbnail is progressively
	 * enhanced by sb-public.js' sb_initGalleryModal() into a click-to-open
	 * modal with its full-size image (data-sb-gallery-full/-alt carry
	 * what that needs); without JavaScript, the link still works as a
	 * plain "open the full image" link. Empty when the book has no
	 * gallery.
	 */
	private function render_gallery( int $post_id ): string {
		$items = '';

		foreach ( $this->gallery_ids( $post_id ) as $attachment_id ) {
			$thumb = wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'sb-book-gallery__image' ) );

			if ( '' === $thumb ) {
				continue;
			}

			$full_url = wp_get_attachment_image_url( $attachment_id, 'full' );
			$full_url = false !== $full_url ? $full_url : '';
			$alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$alt      = '' !== $alt ? (string) $alt : get_the_title( $attachment_id );

			$items .= sprintf(
				'<a class="sb-book-gallery__item" href="%1$s" target="_blank" rel="noopener noreferrer" data-sb-gallery-full="%1$s" data-sb-gallery-alt="%2$s">%3$s</a>',
				esc_url( $full_url ),
				esc_attr( $alt ),
				$thumb
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return '<div class="sb-book-gallery">'
			. sprintf( '<h2 class="sb-book-gallery__title">%s</h2>', esc_html__( 'Gallery', 'smartbook' ) )
			. '<div class="sb-book-gallery__grid" data-sb-gallery>' . $items . '</div>'
			. '</div>';
	}

	/**
	 * One label/value row, omitted entirely when the value is empty.
	 *
	 * @param string $label      Row label text.
	 * @param string $value_html Already-escaped value markup.
	 */
	private function render_row( string $label, string $value_html ): string {
		if ( '' === $value_html ) {
			return '';
		}

		return sprintf(
			'<div class="sb-book-panel__row"><dt>%s</dt><dd>%s</dd></div>',
			esc_html( $label ),
			$value_html
		);
	}

	/**
	 * The book's cover image, linked-page-ready markup straight from
	 * core, or a placeholder glyph when it has none.
	 */
	private function cover( int $post_id ): string {
		if ( has_post_thumbnail( $post_id ) ) {
			return get_the_post_thumbnail( $post_id, 'medium', array( 'class' => 'sb-book-hero__cover' ) );
		}

		return '<span class="sb-book-hero__cover sb-book-hero__cover--placeholder" aria-hidden="true">&#128214;</span>';
	}

	/**
	 * Comma-separated term names for one taxonomy, already escaped.
	 */
	private function terms( int $post_id, string $taxonomy ): string {
		$names = $this->term_list( $post_id, $taxonomy );

		return array() !== $names ? esc_html( implode( ', ', $names ) ) : '';
	}

	/**
	 * Raw (unescaped) term names for one taxonomy.
	 *
	 * @return string[]
	 */
	private function term_list( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * A plain-text BookFields meta value, already escaped, '' when unset.
	 */
	private function field( int $post_id, string $key ): string {
		$value = (string) get_post_meta( $post_id, $key, true );

		return '' !== $value ? esc_html( $value ) : '';
	}

	/**
	 * A "Y-m-d" BookFields date meta value, formatted per the site's
	 * configured date format, already escaped, '' when unset.
	 */
	private function date_field( int $post_id, string $key ): string {
		$value = (string) get_post_meta( $post_id, $key, true );

		return '' !== $value ? esc_html( sb_format_date( $value ) ) : '';
	}

	/**
	 * A select-type BookFields value's translated option label (e.g.
	 * "sb_condition"), already escaped, '' when unset/unrecognised.
	 */
	private function choice_label( int $post_id, string $key ): string {
		$value   = (string) get_post_meta( $post_id, $key, true );
		$options = BookFields::definitions()[ $key ]['options'] ?? array();

		return ( '' !== $value && isset( $options[ $value ] ) ) ? esc_html( $options[ $value ] ) : '';
	}

	/**
	 * The "sb_format" field's translated option label, already escaped.
	 */
	private function format_label( int $post_id ): string {
		return $this->choice_label( $post_id, 'sb_format' );
	}

	/**
	 * Translated reading-status label, already escaped, reusing the same
	 * option labels the edit-screen meta box uses.
	 *
	 * @param int $post_id Book post ID.
	 */
	private function status_label( int $post_id ): string {
		return $this->choice_label( $post_id, 'sb_status' );
	}

	/**
	 * A visual progress bar plus percentage, already escaped.
	 *
	 * @param int $post_id Book post ID.
	 */
	private function progress( int $post_id ): string {
		$progress = max( 0, min( 100, (int) get_post_meta( $post_id, 'sb_progress', true ) ) );

		if ( 0 === $progress ) {
			return '';
		}

		return sprintf(
			'<div class="sb-book-panel__progress"><div class="sb-book-panel__progress-track"><div class="sb-book-panel__progress-fill" style="width:%1$d%%"></div></div><span>%1$d%%</span></div>',
			$progress
		);
	}

	/**
	 * The book's price formatted per the site's currency setting,
	 * already escaped, omitted when unset.
	 */
	private function price( int $post_id ): string {
		$price = (float) get_post_meta( $post_id, 'sb_price', true );

		return $price > 0 ? esc_html( sb_format_currency( $price ) ) : '';
	}

	/**
	 * Attachment ids from the "sb_gallery" meta (comma-separated,
	 * written by the Add/Edit Book media picker).
	 *
	 * @return int[]
	 */
	private function gallery_ids( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, 'sb_gallery', true );

		if ( '' === $raw ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}
}
