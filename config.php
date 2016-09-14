<?php
// Follow the instructions in the README to configure the script.
$config = [
	'github' => [
		// The secret for the GitHub webhook, optional, but should be set for verifying that GitHub is the sender.
		// @see https://developer.github.com/webhooks/securing/
		'secret' => '<WEBHOOK_SECRET>',
		// All of these values can be changed to NULL to disable the syncing of the resource.
		// style.css in the root of git would be 'style.css', a directory assets in the root would be 'assets/'.
		'stylesheet_path' => NULL,				// Path to the stylesheet to sync.
		'assets_dir' => NULL,					// Path to the assets folder, containing all images to sync.
		'header_path' => NULL,					// Path to the header, the subreddit logo to sync.
		'icon_mobile_path' => NULL,				// Path to the mobile icon for the subreddit to sync.
		'header_mobile_path' => NULL			// Path to the subreddit banner/mobile header to sync.
	],
	// OAUTH configuration for the Reddit API.
	// @see https://github.com/reddit/reddit/wiki/OAuth2-Quick-Start-Example
	'reddit' => [
		// All of the following are required.
		'subreddit' => '<SUBREDDIT_NAME>',			// Name of the subreddit to sync.
		'mod_username' => '<MOD_USERNAME>',			// Username of a moderator of the subreddit to sync.
		'mod_password' => '<MOD_PASSWORD>',			// Password of a moderator of the subreddit to sync.
		'secret' => '<CLIENT_SECRET>',				// Secret of the reddit script, generated/given when registering the script.
		'client_id' => '<CLIENT_ID>'				// The ID of the reddit script, generated when registering the script.
	]
];
