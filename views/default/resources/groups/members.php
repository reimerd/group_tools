<?php

use Elgg\Database\Clauses\OrderByClause;
use Elgg\Database\QueryBuilder;

$guid = (int) elgg_extract('guid', $vars);

elgg_entity_gatekeeper($guid, 'group');

$group = get_entity($guid);

elgg_set_page_owner_guid($guid);

elgg_push_breadcrumb(elgg_echo('groups'), "groups/all");
elgg_push_breadcrumb($group->getDisplayName(), $group->getURL());

$options = [
	'relationship' => 'member',
	'relationship_guid' => $group->guid,
	'inverse_relationship' => true,
	'type' => 'user',
	'limit' => (int) get_input('limit', max(20, elgg_get_config('default_limit')), false),
	'no_results' => elgg_format_element('div', ['class' => 'elgg-list'], elgg_echo('notfound')),
];

$sort = elgg_extract('sort', $vars);
switch ($sort) {
	case 'newest':
		$options['order_by'] = [
			new OrderByClause('r.time_created', 'DESC'),
		];
		break;
	default:
		$options['order_by_metadata'] = [
			[
				'name' => 'name',
				'direction' => 'ASC',
			],
		];
		break;
}

// user search
$members_search = elgg()->db->sanitizeString(get_input('members_search'));
if (!empty($members_search)) {
	$options['base_url'] = elgg_generate_url('collection:user:user:group_members', [
		'guid' => $guid,
		'sort' => $sort,
		'members_search' => $members_search,
	]);
	
	$options['metadata_name_value_pairs'] = [
		[
			'name' => 'name',
			'value' => "%{$members_search}%",
			'operand' => 'LIKE',
			'case_sensitive' => false,
		],
		[
			'name' => 'username',
			'value' => "%{$members_search}%",
			'operand' => 'LIKE',
			'case_sensitive' => false,
		],
	];
	$options['metadata_name_value_pairs_operator'] = 'OR';
}

$title = elgg_echo('groups:members:title', [$group->getDisplayName()]);

$tabs = elgg_view_menu('groups_members', [
	'entity' => $group,
	'sort_by' => 'priority',
	'class' => 'elgg-tabs'
]);

$user_list = elgg_list_entities($options);

if (elgg_is_xhr()) {
	// ajax pagination
	echo $user_list;
	return;
}

$content = elgg_view_form('group_tools/members_search', [
	'action' => elgg_generate_url('collection:user:user:group_members', [
		'guid' => $guid,
		'sort' => $sort,
	]),
	'disable_security' => true,
]);
$content .= $user_list;

$body = elgg_view_layout('content', [
	'content' => $content,
	'title' => $title,
	'filter' => $tabs,
]);

echo elgg_view_page($title, $body);
