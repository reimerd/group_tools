<?php
/**
 * Elgg groups plugin edit action.
 *
 * If editing an existing group, only the "group_guid" must be submitted. All other form
 * elements may be omitted and the corresponding data will be left as is.
 *
 * @package ElggGroups
 */

elgg_make_sticky_form('groups');

// Get group fields
$input = [];
foreach (elgg_get_config('group') as $shortname => $valuetype) {
	$value = get_input($shortname);

	if ($value === null) {
		// only submitted fields should be updated
		continue;
	}

	$input[$shortname] = $value;

	// @todo treat profile fields as unescaped: don't filter, encode on output
	if (is_array($input[$shortname])) {
		array_walk_recursive($input[$shortname], function (&$v) {
			$v = elgg_html_decode($v);
		});
	} else {
		$input[$shortname] = elgg_html_decode($input[$shortname]);
	}

	if ($valuetype == 'tags') {
		$input[$shortname] = string_to_tag_array($input[$shortname]);
	}
}

// only set if submitted
$name = elgg_get_title_input('name', null);
if ($name !== null) {
	$input['name'] = $name;
}

$user = elgg_get_logged_in_user_entity();

$group_guid = (int) get_input('group_guid');
$is_new_group = ($group_guid == 0);

if ($is_new_group
		&& (elgg_get_plugin_setting('limited_groups', 'groups') == 'yes')
		&& !$user->isAdmin()) {
	return elgg_error_response(elgg_echo('groups:cantcreate'));
}

$group = $group_guid ? get_entity($group_guid) : new ElggGroup();
if (elgg_instanceof($group, "group") && !$group->canEdit()) {
	return elgg_error_response(elgg_echo('groups:cantedit'));
}

// Assume we can edit or this is a new group
foreach ($input as $shortname => $value) {
	if ($value === '' && !in_array($shortname, ['name', 'description'])) {
		// The group profile displays all profile fields that have a value.
		// We don't want to display fields with empty string value, so we
		// remove the metadata completely.
		$group->deleteMetadata($shortname);
		continue;
	}

	$group->$shortname = $value;
}

// Validate create
if (!$group->name) {
	return elgg_error_response(elgg_echo('groups:notitle'));
}

// Set group tool options (only pass along saved entities)
// @todo: move this to an event handler to make sure groups created outside of the action
// get their tools configured
if ($is_new_group) {
	$tools = elgg()->group_tools->all();
} else {
	$tools = elgg()->group_tools->group($group);
}

foreach ($tools as $tool) {
	$prop_name = $tool->mapMetadataName();
	$value = get_input($prop_name);

	if (!isset($value)) {
		continue;
	}

	if ($value === 'yes') {
		$group->enableTool($tool->name);
	} else {
		$group->disableTool($tool->name);
	}
}

// Group membership - should these be treated with same constants as access permissions?
$value = get_input('membership');
if ($group->membership === null || $value !== null) {
	$is_public_membership = ($value == ACCESS_PUBLIC);
	$group->membership = $is_public_membership ? ACCESS_PUBLIC : ACCESS_PRIVATE;
}

$group->setContentAccessMode((string) get_input('content_access_mode'));

if ($is_new_group) {
	$group->access_id = ACCESS_PUBLIC;

	// if new group, we need to save so group acl gets set in event handler
	if (!$group->save()) {
		return elgg_error_response(elgg_echo("groups:save_error"));
	}
}

// Invisible group support + admin approve check
// @todo this requires save to be called to create the acl for the group. This
// is an odd requirement and should be removed. Either the acl creation happens
// in the action or the visibility moves to a plugin hook
$admin_approve = (bool) (elgg_get_plugin_setting('admin_approve', 'group_tools', 'no') == 'yes');
$admin_approve = ($admin_approve && !elgg_is_admin_logged_in()); // admins don't need to wait

// new groups get access private, so an admin can validate it
$access_id = (int) $group->access_id;
if ($is_new_group && $admin_approve) {
	$access_id = ACCESS_PRIVATE;
	
	elgg_trigger_event('admin_approval', 'group', $group);
}

if (group_tools_allow_hidden_groups()) {
	$value = get_input('vis');
	if ($is_new_group || $value !== null) {
		$visibility = (int)$value;
		
		if ($visibility == ACCESS_PRIVATE) {
			// Make this group visible only to group members. We need to use
			// ACCESS_PRIVATE on the form and convert it to group_acl here
			// because new groups do not have acl until they have been saved once.
			$visibility = (int) $group->group_acl;
			
			// Force all new group content to be available only to members
			$group->setContentAccessMode(ElggGroup::CONTENT_ACCESS_MODE_MEMBERS_ONLY);
		}
		
		if (($access_id === ACCESS_PRIVATE) && $admin_approve) {
			// admins has not yet approved the group, store wanted access
			$group->intended_access_id = $visibility;
		} else {
			// already approved group
			$access_id = $visibility;
		}
	}
}

// set access
$group->access_id = $access_id;

if (!$group->save()) {
	return elgg_error_response(elgg_echo('groups:save_error'));
}

// join motivation
if (!$group->isPublicMembership() && group_tools_join_motivation_required()) {
	$join_motivation = get_input('join_motivation');
	$group->setPrivateSetting('join_motivation', $join_motivation);
} else {
	$group->removePrivateSetting('join_motivation');
}

// default access
$default_access = get_input('group_default_access');
if ($default_access === null) {
	$default_access = (int) $group->group_acl;
}
$default_access = (int) $default_access;

if (($group->getContentAccessMode() === ElggGroup::CONTENT_ACCESS_MODE_MEMBERS_ONLY) && (($default_access === ACCESS_PUBLIC) || ($default_access === ACCESS_LOGGED_IN))) {
	system_message(elgg_echo('group_tools:action:group:edit:error:default_access'));
	$default_access = (int) $group->group_acl;
}
$group->setPrivateSetting("elgg_default_access", $default_access);


// group saved so clear sticky form
elgg_clear_sticky_form('groups');

// group creator needs to be member of new group and river entry created
if ($is_new_group) {
	// @todo this should not be necessary...
	elgg_set_page_owner_guid($group->guid);

	$group->join($user);
	elgg_create_river_item([
		'view' => 'river/group/create',
		'action_type' => 'create',
		'subject_guid' => $user->guid,
		'object_guid' => $group->guid,
	]);
}

$group->saveIconFromUploadedFile('icon');

// owner transfer
$old_owner_guid = $is_new_group ? 0 : $group->owner_guid;
$new_owner_guid = (int) get_input('owner_guid');
$remain_admin = false;
if (group_tools_multiple_admin_enabled()) {
	$remain_admin = (bool) get_input('admin_transfer_remain', false);
}

if (!$is_new_group && $new_owner_guid && ($new_owner_guid != $old_owner_guid)) {
	// who can transfer
	$admin_transfer = elgg_get_plugin_setting("admin_transfer", "group_tools");
	
	$transfer_allowed = false;
	if (($admin_transfer == "admin") && elgg_is_admin_logged_in()) {
		$transfer_allowed = true;
	} elseif (($admin_transfer == "owner") && (($group->owner_guid == $user->guid) || elgg_is_admin_logged_in())) {
		$transfer_allowed = true;
	}
	
	if ($transfer_allowed) {
		// get the new owner
		$new_owner = get_user($new_owner_guid);
		
		// transfer the group to the new owner
		group_tools_transfer_group_ownership($group, $new_owner, $remain_admin);
	}
}

$data = [
	'entity' => $group,
];
return elgg_ok_response($data, elgg_echo('groups:saved'), $group->getURL());
