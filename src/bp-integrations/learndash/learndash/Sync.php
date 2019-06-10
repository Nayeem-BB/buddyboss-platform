<?php
/**
 * BuddyBoss LearnDash integration Sync class.
 *
 * @package BuddyBoss\LearnDash
 * @since BuddyBoss 1.0.0
 */


namespace Buddyboss\LearndashIntegration\Learndash;

use Buddyboss\LearndashIntegration\Library\SyncGenerator;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class for all syncing related functions
 *
 * @since BuddyBoss 1.0.0
 */
class Sync
{
	// temporarily hold the synced learndash group id just before delete
	protected $deletingSyncedBpGroupId;

	/**
	 * Constructor
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function __construct()
	{
		add_action('bp_ld_sync/init', [$this, 'init']);
	}

	/**
	 * Add actions once integration is ready
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function init()
	{
		add_action('bp_ld_sync/learndash_group_updated', [$this, 'onGroupUpdated']);
		add_action('bp_ld_sync/learndash_group_deleting', [$this, 'onGroupDeleting']);
		add_action('bp_ld_sync/learndash_group_deleted', [$this, 'onGroupDeleted']);

		add_action('bp_ld_sync/learndash_group_admin_added', [$this, 'onAdminAdded'], 10, 2);
		add_action('bp_ld_sync/learndash_group_user_added', [$this, 'onUserAdded'], 10, 2);

		add_action('bp_ld_sync/learndash_group_admin_removed', [$this, 'onAdminRemoved'], 10, 2);
		add_action('bp_ld_sync/learndash_group_user_removed', [$this, 'onUserRemoved'], 10, 2);
	}

	/**
	 * Get Sync generator object
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function generator($bpGroupId = null, $ldGroupId = null)
	{
		return new SyncGenerator($bpGroupId, $ldGroupId);
	}

	/**
	 * Run the sync when new group is created / updated
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onGroupUpdated( $groupId ) {
		if ( ! $this->preCheck() ) {
			return false;
		}

		// created from backend
		if ( bp_ld_sync()->isRequestExists( 'bp-ld-sync-enable' ) && ! bp_ld_sync()->getRequest( 'bp-ld-sync-enable' ) ) {
			$group_id = get_post_meta( $groupId, '_sync_group_id', true );
			if ( ! empty( $group_id ) ) {
				bp_ld_sync( 'buddypress' )->sync->generator( $group_id )->desyncFromLearndash();
			}

			return false;
		}

		// created programmatically
		//if ( ! bp_ld_sync( 'settings' )->get( 'learndash.default_auto_sync' ) ) {
			//return false;
		//}

		$newGroup  = bp_ld_sync()->getRequest( 'bp-ld-sync-id', null );
		$generator = $this->generator( null, $groupId );

		if ( $generator->hasBpGroup() && $generator->getBpGroupId() == $newGroup ) {
			$generator->fullSyncToBuddypress();

			return false;
		}

		$generator->associateToBuddypress( $newGroup )->syncLdAdmins()->syncLdUsers();
	}

	/**
	 * Set the deleted gropu in temporarly variable for later use
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onGroupDeleting($groupId)
	{
		if (! $this->preCheck()) {
			return false;
		}

		$this->deletingSyncedBpGroupId = $this->generator(null, $groupId)->getBpGroupId();
	}

	/**
	 * Desync when group is deleted
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onGroupDeleted($groupId)
	{
		if (! $bpGroupId = $this->deletingSyncedBpGroupId) {
			return;
		}

		$this->deletingSyncedBpGroupId = null;

		if (! bp_ld_sync('settings')->get('learndash.delete_bp_on_delete')) {
			$this->generator($bpGroupId)->desyncFromLearndash();
			return;
		}

		$this->generator()->deleteBpGroup($bpGroupId);
	}

	/**
	 * Sync when a admin is added to the group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onAdminAdded($groupId, $userId)
	{
		if (! $generator = $this->groupUserEditCheck('admin', $groupId)) {
			return false;
		}

		$generator->syncLdAdmin($userId);
	}

	/**
	 * Sync when a user is added to the group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onUserAdded($groupId, $userId)
	{
		if (! $generator = $this->groupUserEditCheck('user', $groupId)) {
			return false;
		}

		$generator->syncLdUser($userId);
	}

	/**
	 * Sync when a admin is removed from the group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onAdminRemoved($groupId, $userId)
	{
		if (! $generator = $this->groupUserEditCheck('admin', $groupId)) {
			return false;
		}

		$generator->syncLdAdmin($userId, true);
	}

	/**
	 * Sync when a user is removed from the group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function onUserRemoved($groupId, $userId)
	{
		if (! $generator = $this->groupUserEditCheck('user', $groupId)) {
			return false;
		}

		$generator->syncLdUser($userId, true);
	}

	/**
	 * Check if the user type need to be synced
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function groupUserEditCheck($role, $groupId)
	{
		if (! $this->preCheck()) {
			return false;
		}

		if ('none' == bp_ld_sync('settings')->get("learndash.default_{$role}_sync_to")) {
			return false;
		}

		$generator = $this->generator(null, $groupId);

		if (! $generator->hasBpGroup()) {
			return false;
		}

		return $generator;
	}

	/**
	 * Standard pre check bore all sync happens
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function preCheck()
	{
		global $bp_ld_sync__syncing_to_learndash;

		// if it's group is created from buddypress sync, don't need to sync back
		if ($bp_ld_sync__syncing_to_learndash) {
			return false;
		}

		return bp_ld_sync('settings')->get('learndash.enabled');
	}
}
