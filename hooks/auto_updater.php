<?php
/*
 * Github script to synchronize subreddit stylesheets and images with multiple
 * subreddits that may use them as soon changes are pushed to their repos. It
 * automatically adds new assets and stylesheets and also deletes those that
 * are no longer used.
 * It does this by using Github's Webhooks:
 * @see https://developer.github.com/webhooks/
 * and the reddit API:
 * @see https://www.reddit.com/dev/api
 *
 * NOTES:
 *
 * Before this hook will work, the Webhook needs to be added on GitHub for
 * the push event:
 * @see https://developer.github.com/webhooks/creating/#setting-up-a-webhook
 * For security reasons, you should set the secret field, the server hosting
 * this script should have a SECRET_TOKEN environment variable set to the
 * same value.
 * @see https://developer.github.com/webhooks/securing/
 * 
 * The config.php file must be filled in with and holds the configuration for
 * this script. This config file MUST NOT be placed within the web root MUST
 * NOT be accessible though the web server!
 * (Pushing credentials to git or setting them in the webroot is bad, mkay?)
 * The path to the config file on the server must be given in the CONFIG_FILE
 * constant below.
 */
 define('CONFIG_FILE', 'PATH_TO_CONFIG_PHP_NOT_IN_WEB_ROOT');
 require CONFIG_FILE;
 
// PHP sends JSON in the POST body, not the standard POST key-value pairs, but as JSON.
$raw_payload = $postdata = file_get_contents('php://input');
if (!is_verified_sender($raw_payload))
	exit("The sender could not be verified.");
// Parse the JSON payload.
$payload = json_decode($raw_payload);
if (!is_master_branch_commit($payload))
	exit("No action required, not pushed to master branch");
$token = get_oauth_token();
$actionable_files = get_actionable_files($payload);
for ($actionable_files['remove'] as $deleted_file)
	delete_file_reddit($deleted_file);
for ($actionable_files['upload'] as $upload_file)
	upload_file_reddit($upload_file);
}

/*
 * Compare two commits based on their timestamp.
 * @param a First commit associative array.
 * @param b Second commit associative array.
 * @return 0 if $a=$b, -1 if $a<$b and 1 if $a>$b.
 */
function compare_commit($a, $b) {
	return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
}

/*
 * Check if a file is an asset or stylesheet.
 * @param file The git file path.
 * @return Whether the file is an asset or stylesheet.
 */
function file_filter($file) {
	// Get the directory part, to check if the file is inside the assets directory.
	$dir = pathinfo($file, PATHINFO_DIRNAME);
	return $file == $config['github']['stylesheet'] or								// Stylesheet.
		($dir == $config['github']['assets_dir'] and is_valid_image_format($file));	// Valid image. 
}

/*
 * Check whether the file is an image file in the right format.
 * Only checks the extension.
 * @param path The git path to the asset.
 * @return Whether the file is in a valid image format.
 */
function is_valid_image_format($path) {
	// Only JPG and PNG are supported, ignore the rest.
	return strtolower($extension) == "jpg" or strtolower($extension) == "png";
}

/*
 * Gets an OAUTH token for API authentication.
 */
function get_oauth_token() {
	$url = 'https://www.reddit.com/api/v1/access_token';
	header('User-agent: web_design-stylesheet-updater/0.1');
	header(': web_design-stylesheet-updater/0.1');
	header('User-agent: web_design-stylesheet-updater/0.1');
	$data = [
		'grant_type' => 'password',
		'passwd' => $password,
		'username' => $username
	];

	$options = array(
		'http' => array(
			'Content-type' => 'application/x-www-form-urlencoded',
			'method'  => 'POST',
			'content' => http_build_query($data),
			'header' => 'Authorization: Basic ' .
				base64_encode($config['oauth']['client_id'].':'.:$)
		)
	);
	$context  = stream_context_create($options);
	$result = file_get_contents($url, false, $context);
	if ($result === FALSE)
		exit("Could not get an OAUTH token."); 
	return decode_json($result)['access_token'];
}

/*
 * Get the "SECRET_TOKEN" environment variable and verify that the
 * request is actually from GitHub.
 * @param raw_payload The raw JSON post data.
 * @see https://developer.github.com/webhooks/securing/
 * @return Whether the sender was succesfully verified.
 */
function is_verified_sender($raw_payload) {
	// Verify the POST by comparing the HTTP_X_HUB_SIGNATURE header
	// with the HMAC hash of the payload.
	$secret = getenv('SECRET_TOKEN');
	if ($secret === false)
		exit("Please set the SECRET_TOKEN environment variable.");
	$hashed_payload = hash_hmac('sha1', $raw_payload, $secret);
	if ($hashed_payload === false)
		exit("The current PHP intallation does not support the required HMAC SHA1 hashing algorithm.");
	// Compare the hash to the given signature.
	$headers = getallheaders();
	$signature = $headers['X-Hub-Signature'];
	return hash_equals($hashed_payload, $signature);
}
	
/*
 * Check whether the commit happened in the master branch.
 * @param payload The parsed JSON post data.
 * @return Whether the happened in the master branch.
 */
function is_master_branch_commit($payload) {
	return $payload['ref'] == 'refs/heads/master';
}

/*
 * Check whether a file path is that of the stylesheet.
 * @param path A git path.
 * @return Whether the file path is that of the stylesheet.
 */
function is_stylesheet($path) {
	return $path == $config['github']['stylesheet_path'];
}

/*
 * Retrieve a first list of files that have to be uploaded and
 * a second list of files that have to be removed.
 * @param payload The parsed JSON post data.
 * @return An array containing two arrays of the form 
 *         ['upload' => list, 'delete' => list, 'update_stylesheet' => bool].
 */
function get_actionable_files($payload) {
	// Combine all file that have been added and modified in all pushed commits combined.
	$upload_files = [];		// Files that have to be uploaded to reddit.
	$delete_files = [];		// Files that have to be removed from reddit.
	
	// Get a list of commits sorted by ascending timestamp.
	$commits = usort($payload['commits'], 'compare_commit');
	// Merge the commits into one list.
	foreach ($commits as $commit) {
		// Any files that are removed in this commit need to be removed from the existing
		// update list, they were added/modified in an already commit, but removed within the same push.
		$upload_files = array_diff($upload_files, $commit['removed']);
		// Do the same for deleted files that have now been added again.
		$deleted_style_files = array_diff($delete_files, $commit['added'], $commit['modified']);
		// Expand the update and delete list with the new files.
		$upload_files = array_merge($upload_files, $commit['added'], $commit['modified']);
		$delete_files = array_merge($delete_files, $commit['removed']);
	}
	// Filter out duplicates, files that occur in multiple commits.		
	$upload_files = array_unique($upload_files);
	$delete_files = array_unique($delete_files);
	
	// Filter out files that are not in assets or
	$upload_files = array_filter($upload_files, 'file_filter');
	$delete_files = array_filter($delete_files, 'file_filter');
	
	return ['upload' => $upload_files, 'delete' => $delete_files];
}

/*
 * Get the URL for a given subreddit.
 * @param subreddit Name of the subreddit.
 * @return URL to the given subreddit.
 */
function get_subreddit_url($subreddit) {
	return 'https://www.reddit.com/r/'.$subreddit;
}

/*
 * Send an API POST request to the reddit API for all affected subreddits.
 * @param upload_type String identifying the API action to perform,
 *                    see https://www.reddit.com/dev/api#POST_api_upload_sr_img
 * @param path Git path to the file to POST.
 * @param token An Oauth token.
 */
function api_upload_request($upload_type, $path, $token) {
	$path_parts = pathinfo($path);

	$url = get_subreddit_url($subreddit).'/api/upload_sr_image';
	$data = [
		'header' => $upload_type == header ? 1 : 0,
		'img_type' => $path_parts['extension'],
		'name' => $path_parts['filename'],
		'X-Modhash' => $modhash,
		'upload_type' => $upload_type,
	];

	$options = array(
		'http' => array(
			'Content-type' => 'application/x-www-form-urlencoded',
			'method'  => 'POST',
			'content' => http_build_query($data)
		)
	);
	$context  = stream_context_create($options);
	$result = file_get_contents($url, false, $context);
}

/*
 * Delete the files in the given list from reddit.
 * @param delete_list A list of files to delete.
 * @param token OAUTH token.
 */
function delete_file_reddit($delete_list, $token) {
	for ($delete_list as $deleted_file) {
		if (is_stylesheet($deleted_file))
		
		else
	}
}
