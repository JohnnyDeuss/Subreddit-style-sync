<?php
/**
 * Before this hook will work, the Webhook needs to be added on GitHub for
 * the push event:
 * @see https://developer.github.com/webhooks/creating/#setting-up-a-webhook
 * For security reasons, you should set the secret field, the server hosting
 * this script should have a SECRET_TOKEN environment variable set.
 * @see https://developer.github.com/webhooks/securing/
 * 
 * There should also be a seperate credentials file "credentials.php" that
 * holds the credentials to a moderator account. The credentials should be
 * defined as constants named MOD_USERNAME and MOD_PASSWORD. Make sure that
 * the credentials file is not in the web root and can not be accessed from
 * the web server!
 */
ob_start();
var_dump($_POST);
if (!is_verified_sender())
	exit("The sender could not be verified.");
if (!is_action_required())
	exit("No action required.");
	
/*
 * Get the "SECRET_TOKEN" environment variable and verify that the
 * request is actually from GitHub.
 * @see https://developer.github.com/webhooks/securing/
 * @return Whether the sender was succesfully verified.
 */
function is_verified_sender() {
	// Verify the POST by comparing the HTTP_X_HUB_SIGNATURE with
	// the HMAC hash of the payload.
	$secret = getenv('SECRET_TOKEN');
	if ($secret === false)
		exit("Please set the SECRET_TOKEN environment variable.");
	$raw_payload = $postdata = file_get_contents('php://input');
	$hashed_payload = hash_hmac('sha1', $raw_payload, $secret);
	if ($hashed_payload === false)
		exit("The current PHP intallation does not support the required HMAC SHA1 hashing algorithm.");
	// Compare the hash to the given signature.
	$headers = getallheaders();
	$signature = headers['X-Hub-Signature'];
	return hash_equals($hashed_payload, $signature);
}

/*
 * Make sure that an action is required, meaning that the push
 * must have been made to the master branch and must have made
 * a change to the stylesheet.
 * @return Whether an action is required.
 */
function is_action_required() {
	// TODO
	return true;
}

/*
 * Retrieve the stylesheet from GitHub.
 */
function get_stylesheet() {
	// TODO
}

function update_reddit_stylesheet() {
	// TODO
	// require "<URL_TO_MOD_CREDENTIALS>";
}

fprint(fopen('out.log', 'w'), ob_get_clean());