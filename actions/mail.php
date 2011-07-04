<?php 

	gatekeeper();
	
	$group_guid = (int) get_input("group_guid", 0);
	$subject = get_input("title");
	$body = get_input("description");
	
	$forward_url = REFERER;
	
	if(!empty($group_guid) && !empty($body)){
				
		if(($group = get_entity($group_guid)) && ($group instanceof ElggGroup)){
			if($group->canEdit()){
				set_time_limit(0);
				
				$body .= "


" . elgg_echo("group_tools:mail:message:from") . ": <a href='" . $group->getURL() . "'>" . $group->name . "</a>"; 
					
				foreach($group->getMembers(false) as $member){
					notify_user($member->getGUID(), $group->getGUID(), $subject, $body, NULL, "email"); 
				}
				
				system_message(elgg_echo("group_tools:action:mail:success"));
				
				$forward_url = $group->getURL();
			} else {
				register_error(elgg_echo("group_tools:action:error:edit"));
			}
		} else {
			register_error(elgg_echo("group_tools:action:error:entity"));
		}
	} else {
		register_error(elgg_echo("group_tools:action:error:input"));
	}
	
	forward($forward_url);
	
?>