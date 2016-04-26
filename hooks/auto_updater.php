<?php
/*
 * GitHub script to synchronize subreddit stylesheets and images with multiple
 * subreddits that may use them as soon changes are pushed to their repos. It
 * automatically adds new assets and stylesheets and also deletes those that
 * are no longer used.
 * It does this by using GitHub's webhooks:
 * @see https://developer.github.com/webhooks/
 * and the reddit API:
 * @see https://www.reddit.com/dev/api
 * 
 * The script assumes placed somewhere in a git repo so that it has access to
 * repo through the 'git' command (but it does not actually need to be in the repo).
 * You need to set up git so it doesn't need to authenticate, either by setting
 * up the default username and password or by using a deploy key.
 *
 * For this script to work, fill in the 'config.php' file and set its path in
 * the CONFIG_FILE constant below.
 */
ini_set('xdebug.var_display_max_depth', -1);
ini_set('xdebug.var_display_max_children', -1);
ini_set('xdebug.var_display_max_data', -1);
define('CONFIG_FILE', 'config.php');
require CONFIG_FILE;

define('USER_AGENT_STRING', 'web_design-stylesheet-updater/0.1');
define('REDDIT_CHAR_LIMIT', 256);

// Make sure the getallheaders function is available.
// http://www.php.net/manual/en/function.getallheaders.php#84262
if (!function_exists('getallheaders')) {
	function getallheaders() {
		$headers = '';
		foreach ($_SERVER as $name => $value)
			if (substr($name, 0, 5) == 'HTTP_')
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
		return $headers;
	}
}

// PHP sends JSON in the POST body, not the standard POST key-value pairs, but as JSON.
$raw_payload = file_get_contents('php://input');
//if (!is_verified_sender($raw_payload, $config['github']['secret']))
//	exit('The secret is wrong, failed to verify sender.');
// Parse the JSON payload.
$payload = json_decode($raw_payload, TRUE);
if (!is_master_branch_commit($payload))
	exit('No action required, not pushed to master branch.');
// Get the OAUTH token to be able to use the reddit API.
$token = get_oauth_token();
$actionable_files = get_actionable_files($payload);
// Stop if there is nothing to upload or delete.
if (empty($actionable_files['upload']) or empty($actionable_files['upload']))
	exit('No action required, no changes made to the stylesheet or assets.');
// Checkout the correct git commit so that we can upload them.
$commit_id = $payload['after'];
git_init($commit_id);
// Upload new/modified files and delete deleted ones.
upload_files_reddit($actionable_files['upload'], $token, $payload);
delete_files_reddit($actionable_files['delete'], $token, $payload);

/*
 * Compare two commits based on their timestamp.
 * @param a First commit associative array.
 * @param b Second commit associative array.
 * @return $a=$b -> 0, $a<$b -> -1 and $a>$b -> 1.
 */
function compare_commit($a, $b) {
	return strcmp(strtotime($a['timestamp']), strtotime($b['timestamp']));
}

/*
 * Check if a file is an asset or stylesheet.
 * @param file The git file path.
 * @return Whether the file is an asset or stylesheet.
 */
function file_filter($file) {
	$github_config = $GLOBALS['config']['github'];
	// Get the directory part, to check if the file is inside the assets directory.
	$dir = pathinfo($file, PATHINFO_DIRNAME);
	return $file == $github_config['stylesheet_path'] or							// Stylesheet.
		($dir == rtrim($github_config['assets_dir'], '/') and is_valid_image_format($file));	// Valid image.
}

/*
 * Check whether the file is an image file in the right format.
 * Only checks the extension.
 * @param path The git path to the asset.
 * @return Whether the file is in a valid image format.
 */
function is_valid_image_format($path) {
	$extension = pathinfo($path, PATHINFO_EXTENSION);
	// Only JPG and PNG are supported, ignore the rest.
	return strtolower($extension) == 'jpg' or strtolower($extension) == 'png';
}

/*
 * Determine what kind of image upload to perform.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param path Git path of the image file.
 * @return The upload type of the file.
 */
function get_upload_type($path) {
	$github_config = $GLOBALS['config']['github'];
	if ($path == $github_config['header_path'])				// Regular header.
		return 'header';
	if ($path == $github_config['header_mobile_path'])		// Mobile header/banner.
		return 'banner';
	if ($path == $github_config['icon_mobile_path'])		// Mobile icon.
		return 'icon';
	return 'img';											// Regular image.
}

/*
 * Get the HTTP basic authorization header.
 * @param username Username to use for authentication.
 * @param password Password to use for authentication.
 * @return The HTTP basic authorization header.
 */
function get_basic_authentication_header($username, $password) {
	return 'Authorization: Basic '.base64_encode("$username:$password");
}

/*
 * Get the HTTP authorization header for the OAUTH token.
 * @param token The OAUTH token.
 * @return The HTTP OAUTH authorization header.
 */
function get_authorization_header($token) {
	return 'Authorization: bearer '.$token;
}
/*
 * Gets an OAUTH token for API authentication.
 * @return An OAUTH token for the reddit API.
 */
function get_oauth_token() {
	$oauth_config = $GLOBALS['config']['oauth'];
	$url = 'https://www.reddit.com/api/v1/access_token';
	$data = [
		'grant_type' => 'password',
		'username' => $oauth_config['mod_username'],
		'password' => $oauth_config['mod_password']
	];
	// OAUTH uses basic access authentication, add in header.
	$headers = [get_basic_authentication_header($oauth_config['client_id'], $oauth_config['secret'])];
	$result = api_post_request($url, $data, $headers);
	if ($result === FALSE)
		exit('Could not get an OAUTH token.');
	return json_decode($result, TRUE)['access_token'];
}

/*
 * Get the URL for a given subreddit.
 * @param subreddit Name of the subreddit.
 * @return URL to use for OAUTH for the given subreddit.
 */
function get_oauth_sr_api_url($subreddit) {
	return "https://oauth.reddit.com/r/$subreddit/api";
}

/*
 * Build a multipart/form-data query body.
 * @param data Data to encode.
 * @param file_path File to encode, i.e. {key => x, path => y}.
 * @param mime_boundary Boundary to use for encoding.
 */
function http_build_multipart_query($data, $file_path, $mime_boundary) {
	$lines = [];
	// Generate all lines of the body for the regular data.
	foreach ($data as $key => $value) {
		array_push($lines, "--$mime_boundary");
		array_push($lines, "Content-Disposition: form-data; name=\"$key\"");
		array_push($lines, "");
		array_push($lines, $value);
	}

	// Get filename.
	$filename = pathinfo($file_path, PATHINFO_BASENAME);
	$content = file_get_contents($file_path);
	// Generate the lines for the file.
	array_push($lines, "--$mime_boundary");
	array_push($lines, "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"");
	array_push($lines, "Content-Type: ".mime_content_type($file_path));
	array_push($lines, "");
	array_push($lines, $content);
	// Add final boundary.
	array_push($lines, "--$mime_boundary--");
	// Join lines.
	return implode("\r\n", $lines);
}

/*
 * Gets an OAUTH token for API authentication.
 * @param url URL to post to.
 * @param data Associative array of key-value pairs to pass.
 * @param headers Array of headers to add.
 * @param file_path Optional file to transfer in POST.
 */
function api_post_request($url, $data, $headers, $file_path=NULL) {
	if ($file_path !== NULL) {
		// Use MIME multipart if a file is to be sent.
		$mime_boundary = md5(time());
		array_push($headers, "Content-Type: multipart/form-data; boundary=$mime_boundary");
		$content = http_build_multipart_query($data, $file_path, $mime_boundary);
	}
	else {
		// Use the default POST encoding if there is no file.
		array_push($headers, "Content-Type: application/x-www-form-urlencoded");
		$content = http_build_query($data);
	}

	$options = [
		'http' => [
			'method'  => 'POST',
			'content' => $content,
			'user_agent' => USER_AGENT_STRING,
			'header' => $headers
		]
	];
	$context  = stream_context_create($options);
	return file_get_contents($url, FALSE, $context);
}

/*
 * Send an API POST request to the reddit API for all affected subreddits.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param path Git path to the file to POST.
 * @param token An Oauth token.
 */
function api_upload_image($path, $token) {
	$config = $GLOBALS['config'];
	$upload_type = get_upload_type($path);
	$path_parts = pathinfo($path);
	$path = git_to_absolute_path($path);

	$url = get_oauth_sr_api_url($config['subreddit_name']).'/upload_sr_img';
	$data = [
		'header' => $upload_type == 'header' ? 1 : 0,
		'img_type' => strtolower($path_parts['extension']),
		'name' => $path_parts['filename'],
		'upload_type' => $upload_type,
	];
	$result = api_post_request($url, $data, [get_authorization_header($token)], $path);
	if ($result === FALSE)
		error_log("Could not successfully POST file to reddit: $path\n");
	var_dump($result);
}

/*
 * Send an API request to delete an image.
 * @param path Git path to the file to POST.
 * @param token An Oauth token.
 */
function api_delete_image($path, $token) {
	$config = $GLOBALS['config'];
	$img_name = pathinfo($path, PATHINFO_FILENAME);

	$url = get_oauth_sr_api_url($config['subreddit_name']).'/delete_sr_image';
	$data = [
		'api_type' => 'json',
		'name' => $img_name,
	];
	$result = api_post_request($url, $data, [get_authorization_header($token)]);
	if ($result === FALSE)
		error_log("Could not successfully delelte file from reddit: $path\n");
}

/*
 * Check whether a commit affects the stylesheet.
 * @param commit Commit to check.
 * @return Whether the commit affects the stylesheet.
 */
function affects_stylesheet($commit) {
	$github_config = $GLOBALS['config']['github'];
	return in_array($github_config['stylesheet_path'], $commit['added']) or
			in_array($github_config['stylesheet_path'], $commit['modified']) or
			in_array($github_config['stylesheet_path'], $commit['removed']);
}

/*
 * Generate a reason string to pass to reddit's API's stylesheet method.
 * @param payload GitHub's POST payload.
 * @return A reason string.
 */
function get_reason($payload) {
	// Mention who pushed it and what the latest commit ID head was.
	$commit_id_head = substr($payload['after'], 0, 7);
	$user = $payload['pusher']['name'];
	$reason = "Push by $user($commit_id_head)";

	// Add the commit message titles to clarify what changed.
	$commits = $payload['commits'];
	// Get only the commits that affect the stylesheet.
	$commits = array_filter($commits, 'affects_stylesheet');
	// Sort commits descending by time.
	usort($commits, function ($a, $b) {
		return compare_commit($b, $a);
	});
	$commit_messages = array_map(function ($commit) {
			return strtok($commit['message'], "\r\n");
		}, $commits);
	// Get only non-empty messages.
	$commit_messages = array_filter($commit_messages);
	// Generate the message string.
	$message_string = implode(', ', $commit_messages);
	// If the message is not empty now add it to the reason.
	if (!empty($message_string))
		$reason = "$reason: $message_string";
	// Make sure the reason fits in reddit's 256 char limit.
	if (count($reason) > REDDIT_CHAR_LIMIT)
		$reason = substr($reason, 0, REDDIT_CHAR_LIMIT-3).'...';
	return $reason;
}

/*
 * Set the subreddit stylesheet.
 * @param token An Oauth token.
 * @param content The new content of the stylesheet.
 * @param payload GitHub's POST payload.
 */
function api_subreddit_stylesheet($token, $content, $payload) {
	$reason = get_reason($payload);
	$config = $GLOBALS['config'];
	$url = get_oauth_sr_api_url($config['subreddit_name']).'/subreddit_stylesheet';
	$data = [
		'api_type' => 'json',
		'op' => 'save',
		'reason' => $reason,
		'stylesheet_contents' => $content
	];
	$result = api_post_request($url, $data, [get_authorization_header($token)]);
	if ($result === FALSE)
		error_log("Could not successfully update reddit stylesheet.\n");
	if (!empty($result['errors']))
		error_log("An error occurred while updating the stylesheet.\n".
				"Make sure all used assets are either in the assets folder on git or already manually uploaded to reddit.\n");
}

/*
 * Converts a git path to the local path to to git object.
 * The 'git' command is assumed to be available.
 * @param git_path The path of the object according to git.
 * @return The absolute path to the object according to the web server.
 */
function git_to_absolute_path($git_path) {
	static $git_root = NULL;
	if ($git_root === NULL)
		exec('git rev-parse --show-toplevel', $git_root);
	return $git_root[0].'/'.$git_path;
}

/*
 * Make sure git is available, pull changes and checkout the correct commit.
 * @param commit_id The ID of the latest commit.
 */
function git_init($commit_id) {
	// Check if in a git repo.
	exec('git rev-parse --is-inside-work-tree', $output, $retval);
	if ($retval != 0 or $output[0] != 'true')
		exit('The script is not in a git repo.');
	//exec('git pull origin master', $output, $retval);
	if ($retval != 0)
		exit('Git fetch failed. Did you set up git credentials?');
	exec("git checkout $commit_id", $output, $retval);
	if ($retval != 0)
		exit('Unable to checkout the given commit.');
}

/*
 * Verify that the request is actually from GitHub based on a secret.
 * @see https://developer.github.com/webhooks/securing/
 * @param raw_payload The raw JSON post data.
 * @return Whether the sender was succesfully verified.
 */
function is_verified_sender($raw_payload, $secret) {
	// Verify the POST by comparing the HTTP_X_HUB_SIGNATURE header
	// with the HMAC hash of the payload.
	if ($secret === NULL) {
		error_log('Please set the github secret in the config.');
		return TRUE;
	}

	$hashed_payload = 'sha1='.hash_hmac('sha1', $raw_payload, $secret);
	if ($hashed_payload === FALSE)
		exit('The current PHP installation does not support the required HMAC SHA1 hashing algorithm.');
	// Compare the hash to the given signature.
	$headers = getallheaders();
	if (!isset($headers['X-Hub-Signature']))
		return FALSE;
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
	$github_config = $GLOBALS['config']['github'];
	return $path == $github_config['stylesheet_path'];
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
	usort($payload['commits'], 'compare_commit');
	// Merge the commits into one list.
	foreach ($payload['commits'] as $commit) {
		// Any files that are removed in this commit need to be removed from the existing
		// update list, they were added/modified in an already commit, but removed within the same push.
		$upload_files = array_diff($upload_files, $commit['removed']);
		// Do the same for deleted files that have now been added again.
		$delete_files = array_diff($delete_files, $commit['added'], $commit['modified']);
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
 * Delete the files in the given list from reddit.
 * @param delete_list A list of file paths to delete.
 * @param token OAUTH token.
 * @param payload GitHub's POST payload.
 */
function delete_files_reddit($delete_list, $token, $payload) {
	$github_config = $GLOBALS['config']['github'];
	$is_stylesheet_changed = in_array($github_config['stylesheet_path'], $delete_list);
	// The assets must be uploaded before the stylesheet itself.
	if ($is_stylesheet_changed)
		$delete_list = array_diff($delete_list, [$github_config['stylesheet_path']]);

	// Delete deleted assets.
	foreach ($delete_list as $deleted_file)
		api_delete_image($deleted_file, $token);
	// Delete stylesheet if it was deleted from git.
	if ($is_stylesheet_changed)
		api_subreddit_stylesheet($token, '', $payload);
}

/*
 * Delete the files in the given list from reddit.
 * @param delete_list A list of files to delete.
 * @param token OAUTH token.
 * @param payload GitHub's full POST payload.
 */
function upload_files_reddit($upload_list, $token, $payload) {
	$github_config = $GLOBALS['config']['github'];
	$is_stylesheet_changed = in_array($github_config['stylesheet_path'], $upload_list);
	// The assets must be uploaded before the stylesheet itself.
	if ($is_stylesheet_changed)
		$upload_list = array_diff($upload_list, [$github_config['stylesheet_path']]);

	// Upload changed assets.
	foreach ($upload_list as $upload_file)
		api_upload_image($upload_file, $token);

	// Upload stylesheet if it changed on git.
	if ($is_stylesheet_changed) {
		$content = file_get_contents(git_to_absolute_path($github_config['stylesheet_path']));
		api_subreddit_stylesheet($token, $content, $payload);
	}
}
