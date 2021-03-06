<?php
/**
 * group owner tranfser form
 */

use Elgg\Database\QueryBuilder;

$group = elgg_extract('entity', $vars);
$user = elgg_get_logged_in_user_entity();

if (!$group instanceof ElggGroup || !$user instanceof ElggUser) {
	return;
}

// don't check canEdit() because group admins can do that
if (($group->getOwnerGUID() !== $user->getGUID()) && !$user->isAdmin()) {
	return;
}

$dbprefix = elgg_get_config('dbprefix');

$friends_options = [
	'type' => 'user',
	'relationship' => 'friend',
	'relationship_guid' => $user->guid,
	'limit' => false,
	'count' => true,
	'wheres' => [
		function(QueryBuilder $qb, $main_alias) use ($group) {
			return $qb->compare("{$main_alias}.guid", '!=', $group->owner_guid, ELGG_VALUE_GUID);
		},
	],
	'order_by_metadata' => [
		'name' => 'name',
		'direction' => 'ASC',
	],
	'selects' => [
		function(QueryBuilder $qb, $main_alias) {
			$joined_alias = $qb->joinMetadataTable($main_alias, 'guid', 'name');
			return "{$joined_alias}.value AS name";
		},
	],
];

$member_options = [
	'type' => 'user',
	'relationship' => 'member',
	'relationship_guid' => $group->guid,
	'inverse_relationship' => true,
	'limit' => false,
	'count' => true,
	'wheres' => [
		function (QueryBuilder $qb, $main_alias) use ($group, $user) {
			return $qb->compare("{$main_alias}.guid", 'NOT IN', [$group->owner_guid, $user->guid]);
		},
	],
	'order_by_metadata' => [
		'name' => 'name',
		'direction' => 'ASC',
	],
	'selects' => [
		function(QueryBuilder $qb, $main_alias) {
			$joined_alias = $qb->joinMetadataTable($main_alias, 'guid', 'name');
			return "{$joined_alias}.value AS name";
		},
	],
];

$friends = elgg_get_entities($friends_options);
$members = elgg_get_entities($member_options);

$show_selector = false;

$options = [];
// add current group owner
$options[] = elgg_format_element('option', ['value' => $group->getOwnerGUID()], elgg_echo('group_tools:admin_transfer:current', [$group->getOwnerEntity()->name]));

// add current user
if ($group->getOwnerGUID() !== $user->getGUID()) {
	$show_selector = true;
	$options[] = elgg_format_element('option', ['value' => $user->getGUID()], elgg_echo('group_tools:admin_transfer:myself'));
}

if (!$show_selector && empty($friends) && empty($members)) {
	// didn't add self
	// no friends and no group members
	return;
}

if (!empty($friends)) {
	$show_selector = true;
	$friends_optgroup = [];
	
	unset($friends_options['count']);
	$friends_options['callback'] = function($row) {
		return [
			'guid' => (int) $row->guid,
			'name' => $row->name,
		];
	};
	
	$friends = new ElggBatch('elgg_get_entities', $friends_options);
	foreach ($friends as $friend) {
		$friends_optgroup[] = elgg_format_element('option', ['value' => $friend['guid']], $friend['name']);
	}
	
	// add friends to the select
	$options[] = elgg_format_element('optgroup', ['label' => elgg_echo('friends')], implode('', $friends_optgroup));
}

if (!empty($members)) {
	$show_selector = true;
	$members_optgroup = [];
	
	unset($member_options['count']);
	$member_options['callback'] = function($row) {
		return [
			'guid' => (int) $row->guid,
			'name' => $row->name,
		];
	};
	
	$members = new ElggBatch('elgg_get_entities', $member_options);
	foreach ($members as $member) {
		$members_optgroup[] = elgg_format_element('option', ['value' => $member['guid']], $member['name']);
	}
	
	// add group members to the select
	$options[] = elgg_format_element('optgroup', ['label' => elgg_echo('groups:members')], implode('', $members_optgroup));
}

if (!$show_selector) {
	return;
}

echo '<div class="elgg-field">';
echo elgg_format_element('label', ['class' => 'elgg-field-label', 'for' => 'groups-owner-guid'], elgg_echo('groups:owner'));
echo elgg_format_element('select', [
	'name' => 'owner_guid',
	'id' => 'groups-owner-guid',
], implode('', $options));

if ($group->getOwnerGUID() == $user->getGUID()) {
	echo elgg_format_element('div', ['class' => 'elgg-field-help elgg-text-help'], elgg_echo('groups:owner:warning'));
	
	// stay admin
	if (group_tools_multiple_admin_enabled() && $group->isMember($user)) {
		echo elgg_view_field([
			'#type' => 'checkbox',
			'#label' => elgg_echo('group_tools:admin_transfer:remain_admin'),
			'#class' => 'elgg-divide-left plm hidden group-tools-admin-transfer-remain',
			'name' => 'admin_transfer_remain',
			'value' => '1',
			'checked' => true,
		]);
	}
}

echo '</div>';
