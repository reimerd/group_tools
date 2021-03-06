<?php
/**
 * Group module to show related groups
 */

$group = elgg_extract('entity', $vars);
if (!($group instanceof ElggGroup)) {
	return;
}

if ($group->related_groups_enable !== 'yes') {
	return;
}

$all_link = elgg_view('output/url', [
	'href' => "groups/related/{$group->guid}",
	'text' => elgg_echo('link:view:all'),
	'is_trusted' => true,
]);

$content = elgg_list_entities_from_relationship([
	'type' => 'group',
	'limit' => 4,
	'relationship' => 'related_group',
	'relationship_guid' => $group->guid,
	'full_view' => false,
	'order_by_metadata' => [
		'name' => 'name',
		'direction' => 'ASC',
	],
	'no_results' => elgg_echo('groups_tools:related_groups:none'),
]);

echo elgg_view('groups/profile/module', [
	'title' => elgg_echo('widgets:related_groups:name'),
	'content' => $content,
	'all_link' => $all_link,
]);
