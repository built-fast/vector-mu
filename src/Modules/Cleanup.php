<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class Cleanup implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector cleanup revisions', [ $this, 'revisionsCommand' ] );
			WP_CLI::add_command( 'vector cleanup transients', [ $this, 'transientsCommand' ] );
			WP_CLI::add_command( 'vector cleanup trash', [ $this, 'trashCommand' ] );
			WP_CLI::add_command( 'vector cleanup orphans', [ $this, 'orphansCommand' ] );
			WP_CLI::add_command( 'vector cleanup all', [ $this, 'allCommand' ] );
		}
	}

	/**
	 * Delete post revisions.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<keep>]
	 * : Number of revisions to keep per post. Default: 0 (delete all).
	 *
	 * [--post-type=<post-type>]
	 * : Only delete revisions for specific post type.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cleanup revisions
	 *     wp vector cleanup revisions --keep=5
	 *     wp vector cleanup revisions --post-type=post --keep=3
	 *     wp vector cleanup revisions --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function revisionsCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$keep      = (int) ( $assoc_args['keep'] ?? 0 );
		$post_type = $assoc_args['post-type'] ?? '';
		$dry_run   = isset( $assoc_args['dry-run'] );

		if ( 0 === $keep ) {
			// Delete all revisions.
			$where = "WHERE r.post_type = 'revision'";

			if ( $post_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$where .= $wpdb->prepare(
					' AND r.post_parent IN (SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type = %s)',
					$post_type
				);
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} r {$where}"
			);
			// phpcs:enable

			if ( 0 === $count ) {
				WP_CLI::success( 'No revisions to delete.' );
				return;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf( 'Would delete %d revision(s).', $count ) );
				return;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"DELETE r FROM {$wpdb->posts} r {$where}"
			);
			// phpcs:enable

			WP_CLI::success( sprintf( 'Deleted %d revision(s).', $count ) );
			return;
		}

		// Keep N revisions per post - more complex.
		$post_type_clause = '';
		if ( $post_type ) {
			$post_type_clause = $wpdb->prepare( ' AND p.post_type = %s', $post_type );
		}

		// Get posts with more than $keep revisions.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts_with_excess = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, COUNT(r.ID) as revision_count
				FROM {$wpdb->posts} p
				JOIN {$wpdb->posts} r ON r.post_parent = p.ID AND r.post_type = 'revision'
				WHERE 1=1 {$post_type_clause}
				GROUP BY p.ID
				HAVING revision_count > %d",
				$keep
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $posts_with_excess ) ) {
			WP_CLI::success( sprintf( 'No posts have more than %d revision(s).', $keep ) );
			return;
		}

		$total_deleted = 0;

		foreach ( $posts_with_excess as $post ) {
			$post_id   = (int) $post['ID'];
			$to_delete = (int) $post['revision_count'] - $keep;

			if ( $dry_run ) {
				$total_deleted += $to_delete;
				continue;
			}

			// Get oldest revisions to delete.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$revision_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_parent = %d AND post_type = 'revision'
					ORDER BY post_date ASC
					LIMIT %d",
					$post_id,
					$to_delete
				)
			);

			foreach ( $revision_ids as $revision_id ) {
				wp_delete_post_revision( (int) $revision_id );
				++$total_deleted;
			}
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Would delete %d revision(s) from %d post(s).', $total_deleted, count( $posts_with_excess ) ) );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %d revision(s) from %d post(s).', $total_deleted, count( $posts_with_excess ) ) );
	}

	/**
	 * Delete expired transients.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete all transients, not just expired ones.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cleanup transients
	 *     wp vector cleanup transients --all
	 *     wp vector cleanup transients --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function transientsCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$all     = isset( $assoc_args['all'] );
		$dry_run = isset( $assoc_args['dry-run'] );
		$now     = time();

		if ( $all ) {
			// Count all transients.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_%'
				OR option_name LIKE '_site_transient_%'"
			);

			if ( 0 === $count ) {
				WP_CLI::success( 'No transients to delete.' );
				return;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf( 'Would delete %d transient(s).', $count ) );
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_%'
				OR option_name LIKE '_site_transient_%'"
			);

			WP_CLI::success( sprintf( 'Deleted %d transient(s).', $count ) );
			return;
		}

		// Only expired transients.
		// Get expired transient timeouts.
		$transient_timeout_like      = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$site_transient_timeout_like = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$expired_transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE (option_name LIKE %s OR option_name LIKE %s)
				AND option_value < %d",
				$transient_timeout_like,
				$site_transient_timeout_like,
				$now
			)
		);

		if ( empty( $expired_transients ) ) {
			WP_CLI::success( 'No expired transients to delete.' );
			return;
		}

		$count = count( $expired_transients );

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Would delete %d expired transient(s).', $count ) );
			return;
		}

		// Delete the transients and their timeout entries.
		foreach ( $expired_transients as $timeout_option ) {
			// Get the transient name from the timeout option.
			$transient_option = str_replace( '_timeout', '', $timeout_option );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s",
					$timeout_option,
					$transient_option
				)
			);
		}

		WP_CLI::success( sprintf( 'Deleted %d expired transient(s).', $count ) );
	}

	/**
	 * Empty trash for posts and comments.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post-type>]
	 * : Only empty trash for specific post type.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cleanup trash
	 *     wp vector cleanup trash --post-type=post
	 *     wp vector cleanup trash --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function trashCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$post_type = $assoc_args['post-type'] ?? '';
		$dry_run   = isset( $assoc_args['dry-run'] );

		// Count trashed posts.
		$where = "WHERE post_status = 'trash'";
		if ( $post_type ) {
			$where .= $wpdb->prepare( ' AND post_type = %s', $post_type );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$trashed_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} {$where}"
		);
		// phpcs:enable

		// Count trashed comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$trashed_comments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		// Count auto-drafts.
		$autodraft_where = "WHERE post_status = 'auto-draft'";
		if ( $post_type ) {
			$autodraft_where .= $wpdb->prepare( ' AND post_type = %s', $post_type );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$auto_drafts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} {$autodraft_where}"
		);
		// phpcs:enable

		$total = $trashed_posts + $trashed_comments + $auto_drafts;

		if ( 0 === $total ) {
			WP_CLI::success( 'No trash to empty.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Would delete: %d trashed post(s), %d trashed comment(s), %d auto-draft(s).', $trashed_posts, $trashed_comments, $auto_drafts ) );
			return;
		}

		// Delete trashed posts.
		if ( $trashed_posts > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} {$where}" );
			foreach ( $post_ids as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}

		// Delete trashed comments.
		if ( $trashed_comments > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$comment_ids = $wpdb->get_col(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
			);
			foreach ( $comment_ids as $comment_id ) {
				wp_delete_comment( (int) $comment_id, true );
			}
		}

		// Delete auto-drafts.
		if ( $auto_drafts > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$autodraft_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} {$autodraft_where}" );
			foreach ( $autodraft_ids as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}

		WP_CLI::success( sprintf( 'Deleted %d trashed post(s), %d trashed comment(s), %d auto-draft(s).', $trashed_posts, $trashed_comments, $auto_drafts ) );
	}

	/**
	 * Delete orphaned metadata.
	 *
	 * Removes postmeta, commentmeta, termmeta, and term relationships with no parent.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cleanup orphans
	 *     wp vector cleanup orphans --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function orphansCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] );

		// Count orphans.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_postmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.ID IS NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_commentmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
			LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
			WHERE c.comment_ID IS NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_termmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} tm
			LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
			WHERE t.term_id IS NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_relationships = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			WHERE p.ID IS NULL"
		);

		$total = $orphaned_postmeta + $orphaned_commentmeta + $orphaned_termmeta + $orphaned_relationships;

		if ( 0 === $total ) {
			WP_CLI::success( 'No orphaned data to delete.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Would delete:' );
			WP_CLI::log( sprintf( '  - %d orphaned postmeta entries', $orphaned_postmeta ) );
			WP_CLI::log( sprintf( '  - %d orphaned commentmeta entries', $orphaned_commentmeta ) );
			WP_CLI::log( sprintf( '  - %d orphaned termmeta entries', $orphaned_termmeta ) );
			WP_CLI::log( sprintf( '  - %d orphaned term relationships', $orphaned_relationships ) );
			return;
		}

		// Delete orphans.
		if ( $orphaned_postmeta > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE pm FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE p.ID IS NULL"
			);
		}

		if ( $orphaned_commentmeta > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE cm FROM {$wpdb->commentmeta} cm
				LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
				WHERE c.comment_ID IS NULL"
			);
		}

		if ( $orphaned_termmeta > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE tm FROM {$wpdb->termmeta} tm
				LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
				WHERE t.term_id IS NULL"
			);
		}

		if ( $orphaned_relationships > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE tr FROM {$wpdb->term_relationships} tr
				LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				WHERE p.ID IS NULL"
			);
		}

		WP_CLI::success( sprintf( 'Deleted %d orphaned entries.', $total ) );
	}

	/**
	 * Run all cleanup operations.
	 *
	 * Cleans revisions, expired transients, trash, and orphaned data.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-revisions=<keep>]
	 * : Number of revisions to keep per post. Default: 5.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cleanup all
	 *     wp vector cleanup all --keep-revisions=3
	 *     wp vector cleanup all --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function allCommand( array $args, array $assoc_args ): void {
		$keep_revisions = $assoc_args['keep-revisions'] ?? '5';
		$dry_run        = isset( $assoc_args['dry-run'] );

		$dry_run_args = $dry_run ? [ 'dry-run' => true ] : [];

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cleaning Revisions ===%n' ) );
		$this->revisionsCommand( [], array_merge( [ 'keep' => $keep_revisions ], $dry_run_args ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cleaning Expired Transients ===%n' ) );
		$this->transientsCommand( [], $dry_run_args );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Emptying Trash ===%n' ) );
		$this->trashCommand( [], $dry_run_args );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cleaning Orphaned Data ===%n' ) );
		$this->orphansCommand( [], $dry_run_args );

		WP_CLI::log( '' );

		if ( ! $dry_run ) {
			WP_CLI::success( 'All cleanup operations completed.' );
		}
	}
}
