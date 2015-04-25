<?php
/**
 * class-revisr-process.php
 *
 * Processes user actions and delegates work to the correct class.
 *
 * @package   	Revisr
 * @license   	GPLv3
 * @link      	https://revisr.io
 * @copyright 	Expanded Fronts, LLC
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) exit;

class Revisr_Process {

	/**
	 * Checks if a the current WordPress site is a repository,
	 * and returns a link to create a new repository if not.
	 * @access public
	 * @return boolean
	 */
	public function process_is_repo() {
		if ( revisr()->git->is_repo ) {
			return true;
		} else {
			$init_url 	= wp_nonce_url( get_admin_url() . 'admin-post.php?action=init_repo', 'init_repo', 'revisr_init_nonce' );
			$alert 		= sprintf( __( 'Thanks for installing Revisr! No Git repository was detected, <a href="%s">click here</a> to create one.', 'revisr' ), $init_url );
			Revisr_Admin::alert( $alert );
		}
		return false;
	}

	/**
	 * Processes the request to checkout an existing branch.
	 * @access public
	 */
	public function process_checkout( $args = '', $new_branch = false ) {

		if ( wp_verify_nonce( $_REQUEST['revisr_checkout_nonce'], 'process_checkout' ) ) {

			if ( revisr()->git->get_config( 'revisr', 'import-checkouts' ) === 'true' ) {
				revisr()->db->backup();
			}

			$branch = $_REQUEST['branch'] ? $_REQUEST['branch'] : $args;

			// Fires before the checkout.
			do_action( 'revisr_pre_checkout', $branch );

			revisr()->git->reset();
			revisr()->git->checkout( $branch );

			if ( revisr()->git->get_config( 'revisr', 'import-checkouts' ) === 'true' && $new_branch === false ) {
				revisr()->db->import();
			}

			wp_safe_redirect( get_admin_url() . 'admin.php?page=revisr' );
			exit();

		}

	}

	/**
	 * Processes a new commit from the "New Commit" admin page.
	 * @access public
	 */
	public function process_commit() {

		if ( wp_verify_nonce( $_REQUEST['revisr_commit_nonce'], 'process_commit' ) ) {

			$id 			= get_the_ID();
			$commit_msg 	= $_REQUEST['post_title'];
			$post_new 		= get_admin_url() . 'post-new.php?post_type=revisr_commits';

			// Require a message to be entered for the commit.
			if ( $commit_msg == 'Auto Draft' || $commit_msg == '' ) {
				wp_safe_redirect( $post_new . '&message=42' );
				exit();
			}

			// Determine what we want to do.
			if ( isset( $_POST['staged_files'] ) ) {

				$staged_files 	= $_POST['staged_files'];
				$quick_stage 	= isset( $_POST['unstaged_files'] ) ? false : true;

				// Stage the files.
				revisr()->git->stage_files( $staged_files, $quick_stage );

				// Add the necessary post meta and make the commit in Git.
				add_post_meta( $id, 'committed_files', $staged_files );
				add_post_meta( $id, 'files_changed', count( $staged_files ) );
				revisr()->git->commit( $commit_msg, 'commit' );

			} elseif ( isset( $_POST['backup_db'] ) ) {

				// Backup the database.
				revisr()->db->backup();
				$commit_hash = revisr()->git->current_commit();

				// Add post-commit meta.
				add_post_meta( $id, 'commit_hash', $commit_hash );
				add_post_meta( $id, 'branch', revisr()->git->branch );
				add_post_meta( $id, 'files_changed', '0' );
				add_post_meta( $id, 'commit_status', __( 'Committed', 'revisr' ) );
				add_post_meta( $id, 'db_hash', $commit_hash );
				add_post_meta( $id, 'backup_method', 'tables' );

			} else {

				// There's nothing to do here!
				wp_safe_redirect( $post_new . '&message=43' );
				exit();

			}

		}

	}

	/**
	 * Processes the request to create a new branch.
	 * @access public
	 */
	public function process_create_branch() {

		if ( wp_verify_nonce( $_REQUEST['revisr_create_branch_nonce'], 'process_create_branch' ) ) {

			// Branches can't have spaces, so we replace them with hyphens.
			$branch = str_replace( ' ', '-', $_REQUEST['branch_name'] );

			// Create the branch.
			$result = revisr()->git->create_branch( $branch );

			if ( $result !== false ) {
				$msg = sprintf( __( 'Created new branch: %s', 'revisr' ), $branch );
				Revisr_Admin::log( $msg, 'branch' );

				// Maybe checkout the new branch.
				if ( isset( $_REQUEST['checkout_new_branch'] ) ) {
					revisr()->git->checkout( $branch );
				}

				wp_safe_redirect( get_admin_url() . 'admin.php?page=revisr_branches&status=create_success&branch=' . $branch );
			} else {
				wp_safe_redirect( get_admin_url() . 'admin.php?page=revisr_branches&status=create_error&branch=' . $branch );
			}

		}

		exit();
	}

	/**
	 * Processes the request to delete an existing branch.
	 * @access public
	 */
	public function process_delete_branch() {

		if ( wp_verify_nonce( $_REQUEST['revisr_delete_branch_nonce'], 'process_delete_branch' ) ) {

			if ( isset( $_POST['branch'] ) && $_POST['branch'] != revisr()->git->branch ) {
				$branch = $_POST['branch'];
				revisr()->git->delete_branch( $branch );

				if ( isset( $_POST['delete_remote_branch'] ) ) {
					revisr()->git->run( 'push', array( revisr()->git->remote, '--delete', $branch ) );
				}
			}

		}

		exit();
	}

	/**
	 * Processes the request to discard all untracked changes.
	 * @access public
	 */
	public function process_discard() {

		if ( wp_verify_nonce( $_REQUEST['revisr_dashboard_nonce'], 'revisr_dashboard_nonce' ) ) {

			// Fires prior to a discard.
			do_action( 'revisr_pre_discard' );

			if ( revisr()->git->reset( '--hard', 'HEAD', true ) ) {

				Revisr_Admin::log( __('Discarded all uncommitted changes.', 'revisr'), 'discard' );
				Revisr_Admin::alert( __('Successfully discarded any uncommitted changes.', 'revisr' ) );

				// Fires after a successful discard.
				do_action( 'revisr_post_discard' );

			}


		}

		exit();
	}

	/**
	 * Processes a Git init.
	 * @access public
	 */
	public function process_init() {
		if ( ! wp_verify_nonce( $_REQUEST['revisr_init_nonce'], 'init_repo' ) ) {
			wp_die( 'Cheatin&#8217; uh?', 'revisr' );
		}

		// Fires before a repo is created.
		do_action( 'revisr_pre_init' );

		revisr()->git->init_repo();
	}

	/**
	 * Processes the import of additional (new) tables.
	 * @access public
	 */
	public function process_import() {

		if ( wp_verify_nonce( $_REQUEST['revisr_import_nonce'], 'process_import' ) ) {

			if ( isset( $_REQUEST['revisr_import_untracked'] ) && is_array( $_REQUEST['revisr_import_untracked'] ) ) {
				revisr()->db->import( $_REQUEST['revisr_import_untracked'] );
				_e( 'Importing...', 'revisr' );
				echo "<script>
						window.top.location.href = '" . get_admin_url() . "admin.php?page=revisr';
				</script>";
			}

		}

	}

	/**
	 * Processes the request to merge a branch into the current branch.
	 * @access public
	 */
	public function process_merge() {

		if ( wp_verify_nonce( $_REQUEST['revisr_merge_nonce'], 'process_merge' ) ) {

			// Fires immediately before a merge.
			do_action( 'revisr_pre_merge' );

			revisr()->git->merge( $_REQUEST['branch'] );

			if ( isset( $_REQUEST['import_db'] ) && $_REQUEST['import_db'] == 'on' ) {
				revisr()->db->import();
			}
		}

	}

	/**
	 * Processes the request to pull changes into the current branch.
	 * @access public
	 */
	public function process_pull() {
		if ( ! wp_verify_nonce( $_REQUEST['revisr_dashboard_nonce'], 'revisr_dashboard_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; uh?', 'revisr' ) );
		}

		// Fetch the changes so we can compare them.
		revisr()->git->reset();
		revisr()->git->fetch();

		// Build an array of the commits we don't have locally.
		$commits_since = revisr()->git->run( 'log', array( revisr()->git->branch . '..' . revisr()->git->remote . '/' . revisr()->git->branch, '--pretty=oneline' ) );

		// Maybe backup database.
		if ( revisr()->git->get_config( 'revisr', 'import-pulls' ) === 'true' ) {
			revisr()->db->backup();
			$undo_hash = revisr()->git->current_commit();
			revisr()->git->set_config( 'revisr', 'last-db-backup', $undo_hash );
		}

		// Fires before the changes are pulled.
		do_action( 'revisr_pre_pull', $commits_since );

		// Pull the changes or return an error on failure.
		revisr()->git->pull( $commits_since );
	}

	/**
	 * Processes the request to push changes to a remote repository.
	 * @access public
	 */
	public function process_push() {
		if ( wp_verify_nonce( $_REQUEST['revisr_dashboard_nonce'], 'revisr_dashboard_nonce' ) ) {

			// Fires before a push.
			do_action( 'revisr_pre_push' );

			revisr()->git->push();
		}
	}

	/**
	 * Processes a request to revert, routing to the necessary functions.
	 * @access public
	 * @param  string $type What to revert
	 * @return null
	 */
	public function process_revert( $type = '' ) {
		if ( ! wp_verify_nonce( $_REQUEST['revisr_revert_nonce'], 'revisr_revert_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; uh?', 'revisr' ) );
		}

		// Determine how to handle the request.
		if ( isset( $_REQUEST['revert_type'] ) && $_REQUEST['revert_type'] !== '' ) {
			$revert_type = $_REQUEST['revert_type'];
		} else {
			$revert_type = $type;
		}

		// Fires before the revert.
		do_action( 'revisr_pre_revert', $type );

		// Run the action.
		switch ( $revert_type ) {
			case 'files':
				$this->process_revert_files( false );
				break;
			case 'db':
				revisr()->db->restore( false );
				break;
			case 'files_and_db':
				$this->process_revert_files( false );
				revisr()->db->restore( false );
				break;
			default:
		}

		if ( isset( $_REQUEST['echo_redirect'] ) ) {
			_e( 'Revert completed. Redirecting...', 'revisr' );
			echo "<script>window.top.location.href = '" . get_admin_url() . "admin.php?page=revisr';</script>";
		} else {
			wp_safe_redirect( get_admin_url() . 'admin.php?page=revisr' );
		}
	}

	/**
	 * Processes the request to revert to an earlier commit.
	 * @access public
	 */
	public function process_revert_files( $redirect = true ) {
		if ( ! wp_verify_nonce( $_REQUEST['revisr_revert_nonce'], 'revisr_revert_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; uh?', 'revisr' ) );
		}

		$branch 	= $_REQUEST['branch'];
		$commit 	= $_REQUEST['commit_hash'];
		$commit_msg = sprintf( __( 'Reverted to commit: #%s.', 'revisr' ), $commit );

		if ( $branch != revisr()->git->branch ) {
			revisr()->git->checkout( $branch );
		}

		revisr()->git->reset( '--hard', 'HEAD', true );
		revisr()->git->reset( '--hard', $commit );
		revisr()->git->reset( '--soft', 'HEAD@{1}' );
		revisr()->git->run( 'add', array( '-A' ) );
		revisr()->git->commit( $commit_msg );
		revisr()->git->auto_push();

		$post_url = get_admin_url() . "post.php?post=" . $_REQUEST['post_id'] . "&action=edit";

		$msg = sprintf( __( 'Reverted to commit <a href="%s">#%s</a>.', 'revisr' ), $post_url, $commit );
		$email_msg = sprintf( __( '%s was reverted to commit #%s', 'revisr' ), get_bloginfo(), $commit );
		Revisr_Admin::log( $msg, 'revert' );
		Revisr_Admin::notify( get_bloginfo() . __( ' - Commit Reverted', 'revisr' ), $email_msg );

		if ( true === $redirect ) {
			$redirect = get_admin_url() . "admin.php?page=revisr";
			wp_safe_redirect( $redirect );
		}
	}
}
