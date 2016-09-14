<?php
define('CONFIG_FILE', 'config.php');
require CONFIG_FILE;

define('USER_AGENT_STRING', 'web_design-stylesheet-updater/0.1');
define('REDDIT_REASON_LIMIT', 256);

$ERROR_STRING = NULL;

// Make sure the getallheaders function is available.
// If it isn't we'll define our own version.
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
handle_event();


/*
 * Handle the GitHub event. This is the script entry point.
 */
function handle_event() {
	$payload = get_payload();
	$states = get_before_and_after_state($payload);
	$changed_files = get_changed_files($states['before'], $states['after']);
	$changed_files = filter_synced_files($changed_files);
	// Stop if there is nothing to upload or delete.
	if (empty($changed_files['upload']) and empty($changed_files['delete']))
		error_exit('No action required, no changes have been made to synced files.');
	$reason = get_stylesheet_change_reason($payload);
	sync_changed_files($changed_files, $states['after'], $reason);
}


/*
 * Get the payload sent by GitHub.
 * @return The payload sent by GitHub.
 */
function get_payload() {
	// Get raw payload, as all information is sent as JSON, not HTTP key=value pairs.
	$raw_payload = file_get_contents('php://input');
	if (!is_verified_sender($raw_payload, $GLOBALS['config']['github']['secret']))
		error_exit('The secret is wrong, failed to verify sender.');
	$payload = json_decode($raw_payload, TRUE);
	// Only act upon pushes and releases of the master branch.
	if (!is_master_branch_event($payload))
		error_exit('No action required, not pushed to master branch.');
	return $payload;
}


/*
 * Verify that the request is actually from GitHub based on a secret.
 * @see https://developer.github.com/webhooks/securing/
 * @param $raw_payload The raw JSON post data.
 * @return Whether the sender was succesfully verified.
 */
function is_verified_sender($raw_payload, $secret) {
	// Verify the POST by comparing the HTTP_X_HUB_SIGNATURE header
	// with the HMAC hash of the payload.
	if ($secret === NULL) {
		queue_error('Please set the github secret in the config.');
		return TRUE;
	}

	$hashed_payload = 'sha1='.hash_hmac('sha1', $raw_payload, $secret);
	if ($hashed_payload === FALSE)
		error_exit('The current PHP installation does not support the required HMAC SHA1 hashing algorithm.');
	// Compare the hash to the given signature.
	$headers = getallheaders();
	if (!isset($headers['X-Hub-Signature']))
		return FALSE;
	$signature = $headers['X-Hub-Signature'];
	return hash_equals($hashed_payload, $signature);
}


/*
 * Check whether the event happened in the master branch.
 * @param $payload The parsed JSON post data.
 * @return Whether the event happened in the master branch.
 */
function is_master_branch_event($payload) {
	return !empty($payload['ref']) and $payload['ref'] == 'refs/heads/master'	// Push case.
			or $payload['release']['target_commitish'] == 'master';				// Release case.
}


/*
 * Get the state of the GitHub repository before and after the commit.
 * @param $payload The GitHub payload.
 * @return The before and after state of the GitHub repository before and after the commit.
 * Returned as an associative array ['before'=>..., 'after'=>...].
 */
function get_before_and_after_state($payload) {
	// GitHub defines some information as HTTP headers.
	$headers = getallheaders();
	// Make sure the incoming event has a valid event header defined.
	if (!key_exists('X-GitHub-Event', $headers))
		error_exit('Incorrect POST request, no event type specified.');
	$event_type = $headers['X-GitHub-Event'];
	if ($event_type == 'push') {
		$before = $payload['before'];
		$after = $payload['after'];
	}
	else if ($event_type == 'release') {
		// Ignore pre-releases and drafts.
		if ($payload['release']['prerelease'] or $payload['release']['draft'])
			error_exit('Not a full release, no action required.');
		// The before and after state of the repository is given in the payload.
		$before = get_previous_release_tag($payload['repository']['name']);
		$after = $payload['release']['tag_name'];
	}
	else
		error_exit("This script only supports syncing for the  'push' and 'release' event, but it received a '$event_type' event.".
			'Please select a correct event type.');
	return ['before' => $before, 'after' => $after];
}


/*
 * Get a list of files that need to be uploaded or removed.
 * Also check if the stylesheet needs to be updated.
 * @param $before Reference to the git before state, NULL if there isn't one.
 * @param $after Reference to the git after state.
 * @return An array containing two arrays of the form
 *         ['upload' => list, 'delete' => list].
 */
function get_changed_files($before, $after) {
	if ($before === NULL) {
		// If there is no before state, simply return all files in the current git state.
		exec("git ls-files", $output, $retval);
		return $output;
	}
	else {
		exec("git fetch", $output, $retval);
		// Ask git what has changed between these states.
		exec("git diff --name-status $before $after", $output, $retval);
		if ($retval !== 0)
			error_exit("An error occurred while finding the git diff. $before $after\n");
		$changed = [
			// @see https://git-scm.com/docs/git-diff for the meaning of these status letters.
			'upload' => array_filter($output, function ($x) { return in_array(substr($x, 0, 1), ['A', 'C', 'M', 'R']); }),
			'delete' => array_filter($output, function ($x) { return substr($x, 0, 1) == 'D'; })
		];
		// Remove the status character, leaving only the paths to the changed files.
		$changed['upload'] = array_map(function ($x) { return trim(substr($x, 1)); }, $changed['upload']);
		$changed['delete'] = array_map(function ($x) { return trim(substr($x, 1)); }, $changed['delete']);
	}

	return $changed;
}


/*
 * Filter out only files that are synced.
 * @param $files List off files.
 * @return Filtered list.
 */
function  filter_synced_files($files) {
	/*
	 * Check if a file is an asset or stylesheet.
	 * @param $file The git file path.
	 * @return Whether the file is an asset or stylesheet.
	 */
	function file_filter($file) {
		$github_config = $GLOBALS['config']['github'];
		// Get the directory part, to check if the file is inside the assets directory.
		$dir = pathinfo($file, PATHINFO_DIRNAME);
		return $file == $github_config['stylesheet_path'] or											// Stylesheet.
				$file == $github_config['header_path'] or												// Logo.
				$file == $github_config['icon_mobile_path'] or											// Mobile icon.
				$file == $github_config['header_mobile_path'] or										// Banner.
				($dir == rtrim($github_config['assets_dir'], '/') and is_valid_image_format($file));	// Valid image.
	}	
	$files['upload'] = array_filter($files['upload'], 'file_filter');
	$files['delete'] = array_filter($files['delete'], 'file_filter');
	return $files;
}


/*
 * Check whether the file is an image file in the right format.
 * Only checks the extension.
 * @param $path The git path to the asset.
 * @return Whether the file is in a valid image format.
 */
function is_valid_image_format($path) {
	$extension = pathinfo($path, PATHINFO_EXTENSION);
	// Only JPG and PNG are supported, ignore the rest.
	return strtolower($extension) == 'jpg' or strtolower($extension) == 'png';
}


/*
 * Generate a reason string to pass to reddit's API's stylesheet method.
 * @param $payload GitHub's POST payload.
 * @return A reason string.
 */
function get_stylesheet_change_reason($payload) {
	$event_type = getallheaders()['X-GitHub-Event'];
	if ($event_type == "push") {
		// Mention who pushed it and what the latest commit ID head was.
		$commit_id_head = substr($payload['after'], 0, 7);
		$user = $payload['pusher']['name'];
		$reason = "Push by $user($commit_id_head)";

		// Add the commit message titles to clarify what changed.
		$commits = $payload['commits'];
		// Get only the commits that affect the stylesheet.
		$commits = array_filter($commits, 'affects_stylesheet');
		// Sort commits descending by time.
		usort($commits, function ($a, $b) { return compare_commit($b, $a); });
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
		if (count($reason) > REDDIT_REASON_LIMIT)
			$reason = substr($reason, 0, REDDIT_REASON_LIMIT - 3) . '...';
		return $reason;
	}
	else {
		$release_tag = $payload['release']['tag_name'];
		return "Release $release_tag";
	}
}


/*
 * Compare two releases based on publishing date.
 * @param $a First release associative array.
 * @param $b Second release associative array.
 * @return $a=$b -> 0, $a<$b -> -1 and $a>$b -> 1.
 */
function compare_release($a, $b) {
	return strtotime($a['released']['published_at']) <=> strtotime($b['released']['published_at']);
}


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
 * Sync the files given files to Reddit.
 * @param $files The files to sync.
 * @param $state The state the filse are in, as given by GitHub.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function sync_changed_files($files, $commit_id, $reason) {
	// Init git and checkout the 'after' state.
	git_init($commit_id);
	// Get the OAUTH token to be able to use the reddit API.
	$token = get_reddit_oauth_token();
	// Upload new/modified files and delete deleted ones.
	reddit_upload_files($files['upload'], $token, $reason);
	reddit_delete_files($files['delete'], $token, $reason);
}


/*
 * Queues the error message to be printed at the end of execution, without stopping the script.
 * The queue or error messages is printed automatically at the end of execution, along with setting the 500 HTTP status.
 * @param $msg Message to pass to GitGub.
 */
function queue_error($msg) {
	// NULL error string means no messages have been queued yet.
	if ($GLOBALS['ERROR_STRING'] === NULL) {
		$GLOBALS['ERROR_STRING'] = "";
		// Register a shutdown function to print the errors and set the 500 HTTP status.
		register_shutdown_function(function () {
			header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error", true, 500);
			echo($GLOBALS['ERROR_STRING']);
		});
	}
	$GLOBALS['ERROR_STRING'] .= "$msg\n";
}


/*
 * Stop execution, send an error response to GitHub and set the 500 HTTP status.
 * @param $msg Message to pass to GitGub.
 */
function error_exit($msg) {
	queue_error($msg);
	exit();
}


/*
 * Make sure git is available, pull changes and checkout the correct commit.
 * @param $git_ref Reference to a git state to checkout.
 */
function git_init($git_ref) {
	// Check if in a git repo.
	exec('git rev-parse --is-inside-work-tree', $output, $retval);
	if ($retval != 0 or $output[0] != 'true')
		error_exit('The script is not in a git repo.');
	//exec('git pull origin master', $output, $retval);
	if ($retval != 0)
		error_exit('Git fetch failed. Did you set up git credentials?');
	exec("git checkout $git_ref", $output, $retval);
	if ($retval != 0)
		error_exit('Unable to checkout the given commit.');
}


/*
 * Gets an OAUTH token for API authentication.
 * @return An OAUTH token for the reddit API.
 */
function get_reddit_oauth_token() {
	$oauth_config = $GLOBALS['config']['reddit'];
	$url = 'https://www.reddit.com/api/v1/access_token';
	$data = [
		'grant_type' => 'password',
		'username' => $oauth_config['mod_username'],
		'password' => $oauth_config['mod_password']
	];
	// OAUTH uses basic access authentication, add in header.
	$headers = [get_basic_authentication_header($oauth_config['client_id'], $oauth_config['secret'])];
	$response = post_request($url, $data, "Unable to get Reddit API access token, check your login credentials.", $headers);
	return $response['access_token'];
}


/*
 * Upload the files in the given list from Reddit.
 * @param $upload_list A list of files to upload.
 * @param $token Reddit OAUTH token.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function reddit_upload_files($upload_list, $token, $reason) {
	$github_config = $GLOBALS['config']['github'];
	// Remove the stylesheet_path, it has to be handled differently.
	$upload_list = array_diff($upload_list, [$github_config['stylesheet_path']]);

	// The assets must be uploaded before the stylesheet itself.

	// Upload changed assets.
	foreach ($upload_list as $upload_file)
		api_upload_image($upload_file, $token);

	// Always upload the stylesheet, since forgetting to upload an image would result in failure when trying to upload
	// the stylesheet. The stylesheet would only be uploaded again if it was changed again.
	$content = file_get_contents(git_to_absolute_path($github_config['stylesheet_path']));
	api_set_subreddit_stylesheet($token, $content, $reason);
}


/*
 * Delete the files in the given list from reddit.
 * @param $delete_list A list of file paths to delete.
 * @param $token Reddit OAUTH token.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function reddit_delete_files($delete_list, $token, $reason) {
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
		api_set_subreddit_stylesheet($token, '', $reason);
}


/*
 * Determine what kind of image upload to perform.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param $path Git path of the image file.
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
 * Converts a git path to the local path to to git object.
 * The 'git' command is assumed to be available.
 * @param $git_path The path of the object according to git.
 * @return The absolute path to the object according to the web server.
 */
function git_to_absolute_path($git_path) {
	static $git_root = NULL;
	if ($git_root === NULL)
		exec('git rev-parse --show-toplevel', $git_root);
	return $git_root[0].'/'.$git_path;
}


/*
 * Handle the response of an Reddit API call.
 * Specifically, check for errors and handle them.
 * @param $response_json The response of a Reddit API call.
 * @param $err_msg The error message to show if the call fails.
 * @return Parsed response object.
 */
function handle_api_response($response_json, $err_msg) {
	// FALSE: something went wrong in making the request itself.
	if ($response_json === FALSE) {
		queue_error("Could not successfully update reddit stylesheet.");
		$response = NULL;
	}
	// An error was caused in the API call.
	else {
		$response = json_decode($response_json, TRUE);
		if ($response == NULL)
			return NULL;
		// Two kinds of JSON error keys.
		if (key_exists('error', $response))
			queue_error($err_msg . "\n(". $response['error'] . ')');
		else if (!empty($response['json']['errors'])) {
			$errs = array_map(function($ar) { return '('.implode(", ", $ar).')'; }, $response['json']['errors']);
			queue_error($err_msg."\n(".implode("\n", $errs).')');
		}
	}
	return $response;
}


/*
 * Send a POST request.
 * All responses are expected to be JSON, as Reddit always does.
 * @param $url URL to send the request to.
 * @param $data Data to sent.
 * @param $err_msg The error message to be shown if the request fails.
 * @param $headers Extra headers to send if any (Default=[]).
 * @param $file_path File to send if there is any (Default=NULL).
 * @return Response object.
 */
function post_request($url, $data, $err_msg, $headers=[], $file_path=NULL) {
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
	$response_json = file_get_contents($url, FALSE, $context);
	return handle_api_response($response_json, $err_msg);
}


/*
 * Send an API POST request.
 * @param $api_method API method to call.
 * @param $data Data to sent.
 * @param $token Reddit API token.
 * @param $err_msg The error message to be shown if the request fails.
 * @param $file_path File to send if there is any (Default=NULL).
 * @return Response to API POST request.
 */
function api_post_request($api_method, $token, $data, $err_msg, $file_path=NULL) {
	$subreddit = $GLOBALS['config']['reddit']['subreddit'];
	$url = "https://oauth.reddit.com/r/$subreddit/api/$api_method";
	$headers = ['Authorization: bearer '.$token];
	return post_request($url, $data, $err_msg, $headers, $file_path);
}


/*
 * Send an API POST request to the reddit API for all affected subreddits.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param $img_path Git path to the file to POST.
 * @param $token An Oauth token.
 */
function api_upload_image($img_path, $token) {
	$upload_type = get_upload_type($img_path);
	$path_parts = pathinfo($img_path);
	$local_img_path = git_to_absolute_path($img_path);

	$data = [
		'header' => $upload_type == 'header' ? 1 : 0,
		'img_type' => strtolower($path_parts['extension']),
		'name' => $path_parts['filename'],
		'upload_type' => $upload_type,
	];
	api_post_request('upload_sr_img', $token, $data,
			"Could not upload an image ($local_img_path), check that it fits within reddits guidelines:\n<=500kb, jpg or png", $local_img_path);
}


/*
 * Send an API request to delete an image.
 * @param $path Git path to the file to POST.
 * @param $token An Oauth token.
 */
function api_delete_image($path, $token) {
	$img_name = pathinfo($path, PATHINFO_FILENAME);
	$data = [
		'api_type' => 'json',
		'img_name' => $img_name,
	];
	api_post_request('delete_sr_img', $token, $data,"Could not delete an image ($path), it may have already been deleted manually.");
}


/*
 * Set the subreddit stylesheet.
 * @param $token An Oauth token.
 * @param $content The new content of the stylesheet.
 * @param $reason The reason to state when updating the subreddit stylesheet.
 */
function api_set_subreddit_stylesheet($token, $content, $reason) {
	$data = [
		'api_type' => 'json',
		'op' => 'save',
		'reason' => $reason,
		'stylesheet_contents' => $content
	];
	api_post_request('subreddit_stylesheet', $token, $data, "An error occurred while updating the stylesheet.\n" .
		'Make sure all used assets are either in the assets folder on git or already manually uploaded to reddit.');
}


/*
 * Build a multipart/form-data query body.
 * @param $data Data to encode.
 * @param $file_path File to encode, i.e. {key => x, path => y}.
 * @param $mime_boundary Boundary to use for encoding.
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
 * Get the HTTP basic authorization header.
 * @param $username Username to use for authentication.
 * @param $password Password to use for authentication.
 * @return The HTTP basic authorization header.
 */
function get_basic_authentication_header($username, $password) {
	return 'Authorization: Basic '.base64_encode("$username:$password");
}


/*
 * Get the tag of the previous release.
 * @param $repo_name Name of the repository.
 * @return The tag of the previous release or NULL if there isn't any.
 */
function get_previous_release_tag($repo_name) {
	$json = file_get_contents("https//api.github.com/repos/$repo_name/releases");
	if ($json === false)
		error_exit('Could not look up the previous version.');
	$releases = json_decode($json, TRUE);
	if (count($releases) == 1)
		return NULL;
	usort($releases, 'compare_release');
	return $releases[1]['tag_name'];
}


/*
 * Check whether a commit affects the stylesheet.
 * @param $commit Commit to check.
 * @return Whether the commit affects the stylesheet.
 */
function affects_stylesheet($commit) {
	$github_config = $GLOBALS['config']['github'];
	return in_array($github_config['stylesheet_path'], $commit['added']) or
			in_array($github_config['stylesheet_path'], $commit['modified']) or
			in_array($github_config['stylesheet_path'], $commit['removed']);
}


/*
 * Check whether a file path is that of the stylesheet.
 * @param $path A git path.
 * @return Whether the file path is that of the stylesheet.
 */
function is_stylesheet($path) {
	$github_config = $GLOBALS['config']['github'];
	return $path == $github_config['stylesheet_path'];
}

<?php
define('CONFIG_FILE', 'config.php');
require CONFIG_FILE;

define('USER_AGENT_STRING', 'web_design-stylesheet-updater/0.1');
define('REDDIT_REASON_LIMIT', 256);

$ERROR_STRING = NULL;

// Make sure the getallheaders function is available.
// If it isn't we'll define our own version.
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
handle_event();


/*
 * Handle the GitHub event. This is the script entry point.
 */
function handle_event() {
	$payload = get_payload();
	$states = get_before_and_after_state($payload);
	$changed_files = get_changed_files($states['before'], $states['after']);
	$changed_files = filter_synced_files($changed_files);
	// Stop if there is nothing to upload or delete.
	if (empty($changed_files['upload']) and empty($changed_files['delete']))
		error_exit('No action required, no changes have been made to synced files.');
	$reason = get_stylesheet_change_reason($payload);
	sync_changed_files($changed_files, $states['after'], $reason);
}


/*
 * Get the payload sent by GitHub.
 * @return The payload sent by GitHub.
 */
function get_payload() {
	// Get raw payload, as all information is sent as JSON, not HTTP key=value pairs.
	$raw_payload = file_get_contents('php://input');
	if (!is_verified_sender($raw_payload, $GLOBALS['config']['github']['secret']))
		error_exit('The secret is wrong, failed to verify sender.');
	$payload = json_decode($raw_payload, TRUE);
	// Only act upon pushes and releases of the master branch.
	if (!is_master_branch_event($payload))
		error_exit('No action required, not pushed to master branch.');
	return $payload;
}


/*
 * Verify that the request is actually from GitHub based on a secret.
 * @see https://developer.github.com/webhooks/securing/
 * @param $raw_payload The raw JSON post data.
 * @return Whether the sender was succesfully verified.
 */
function is_verified_sender($raw_payload, $secret) {
	// Verify the POST by comparing the HTTP_X_HUB_SIGNATURE header
	// with the HMAC hash of the payload.
	if ($secret === NULL) {
		queue_error('Please set the github secret in the config.');
		return TRUE;
	}

	$hashed_payload = 'sha1='.hash_hmac('sha1', $raw_payload, $secret);
	if ($hashed_payload === FALSE)
		error_exit('The current PHP installation does not support the required HMAC SHA1 hashing algorithm.');
	// Compare the hash to the given signature.
	$headers = getallheaders();
	if (!isset($headers['X-Hub-Signature']))
		return FALSE;
	$signature = $headers['X-Hub-Signature'];
	return hash_equals($hashed_payload, $signature);
}


/*
 * Check whether the event happened in the master branch.
 * @param $payload The parsed JSON post data.
 * @return Whether the event happened in the master branch.
 */
function is_master_branch_event($payload) {
	return !empty($payload['ref']) and $payload['ref'] == 'refs/heads/master'	// Push case.
			or $payload['release']['target_commitish'] == 'master';				// Release case.
}


/*
 * Get the state of the GitHub repository before and after the commit.
 * @param $payload The GitHub payload.
 * @return The before and after state of the GitHub repository before and after the commit.
 * Returned as an associative array ['before'=>..., 'after'=>...].
 */
function get_before_and_after_state($payload) {
	// GitHub defines some information as HTTP headers.
	$headers = getallheaders();
	// Make sure the incoming event has a valid event header defined.
	if (!key_exists('X-GitHub-Event', $headers))
		error_exit('Incorrect POST request, no event type specified.');
	$event_type = $headers['X-GitHub-Event'];
	if ($event_type == 'push') {
		$before = $payload['before'];
		$after = $payload['after'];
	}
	else if ($event_type == 'release') {
		// Ignore pre-releases and drafts.
		if ($payload['release']['prerelease'] or $payload['release']['draft'])
			error_exit('Not a full release, no action required.');
		// The before and after state of the repository is given in the payload.
		$before = get_previous_release_tag($payload['repository']['name']);
		$after = $payload['release']['tag_name'];
	}
	else
		error_exit("This script only supports syncing for the  'push' and 'release' event, but it received a '$event_type' event.".
			'Please select a correct event type.');
	return ['before' => $before, 'after' => $after];
}


/*
 * Get a list of files that need to be uploaded or removed.
 * Also check if the stylesheet needs to be updated.
 * @param $before Reference to the git before state, NULL if there isn't one.
 * @param $after Reference to the git after state.
 * @return An array containing two arrays of the form
 *         ['upload' => list, 'delete' => list].
 */
function get_changed_files($before, $after) {
	if ($before === NULL) {
		// If there is no before state, simply return all files in the current git state.
		exec("git ls-files", $output, $retval);
		return $output;
	}
	else {
		exec("git fetch", $output, $retval);
		// Ask git what has changed between these states.
		exec("git diff --name-status $before $after", $output, $retval);
		if ($retval !== 0)
			error_exit("An error occurred while finding the git diff. $before $after\n");
		$changed = [
			// @see https://git-scm.com/docs/git-diff for the meaning of these status letters.
			'upload' => array_filter($output, function ($x) { return in_array(substr($x, 0, 1), ['A', 'C', 'M', 'R']); }),
			'delete' => array_filter($output, function ($x) { return substr($x, 0, 1) == 'D'; })
		];
		// Remove the status character, leaving only the paths to the changed files.
		$changed['upload'] = array_map(function ($x) { return trim(substr($x, 1)); }, $changed['upload']);
		$changed['delete'] = array_map(function ($x) { return trim(substr($x, 1)); }, $changed['delete']);
	}

	return $changed;
}


/*
 * Filter out only files that are synced.
 * @param $files List off files.
 * @return Filtered list.
 */
function  filter_synced_files($files) {
	/*
	 * Check if a file is an asset or stylesheet.
	 * @param $file The git file path.
	 * @return Whether the file is an asset or stylesheet.
	 */
	function file_filter($file) {
		$github_config = $GLOBALS['config']['github'];
		// Get the directory part, to check if the file is inside the assets directory.
		$dir = pathinfo($file, PATHINFO_DIRNAME);
		return $file == $github_config['stylesheet_path'] or											// Stylesheet.
				$file == $github_config['header_path'] or												// Logo.
				$file == $github_config['icon_mobile_path'] or											// Mobile icon.
				$file == $github_config['header_mobile_path'] or										// Banner.
				($dir == rtrim($github_config['assets_dir'], '/') and is_valid_image_format($file));	// Valid image.
	}	
	$files['upload'] = array_filter($files['upload'], 'file_filter');
	$files['delete'] = array_filter($files['delete'], 'file_filter');
	return $files;
}


/*
 * Check whether the file is an image file in the right format.
 * Only checks the extension.
 * @param $path The git path to the asset.
 * @return Whether the file is in a valid image format.
 */
function is_valid_image_format($path) {
	$extension = pathinfo($path, PATHINFO_EXTENSION);
	// Only JPG and PNG are supported, ignore the rest.
	return strtolower($extension) == 'jpg' or strtolower($extension) == 'png';
}


/*
 * Generate a reason string to pass to reddit's API's stylesheet method.
 * @param $payload GitHub's POST payload.
 * @return A reason string.
 */
function get_stylesheet_change_reason($payload) {
	$event_type = getallheaders()['X-GitHub-Event'];
	if ($event_type == "push") {
		// Mention who pushed it and what the latest commit ID head was.
		$commit_id_head = substr($payload['after'], 0, 7);
		$user = $payload['pusher']['name'];
		$reason = "Push by $user($commit_id_head)";

		// Add the commit message titles to clarify what changed.
		$commits = $payload['commits'];
		// Get only the commits that affect the stylesheet.
		$commits = array_filter($commits, 'affects_stylesheet');
		// Sort commits descending by time.
		usort($commits, function ($a, $b) { return compare_commit($b, $a); });
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
		if (count($reason) > REDDIT_REASON_LIMIT)
			$reason = substr($reason, 0, REDDIT_REASON_LIMIT - 3) . '...';
		return $reason;
	}
	else {
		$release_tag = $payload['release']['tag_name'];
		return "Release $release_tag";
	}
}


/*
 * Compare two releases based on publishing date.
 * @param $a First release associative array.
 * @param $b Second release associative array.
 * @return $a=$b -> 0, $a<$b -> -1 and $a>$b -> 1.
 */
function compare_release($a, $b) {
	return strtotime($a['released']['published_at']) <=> strtotime($b['released']['published_at']);
}


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
 * Sync the files given files to Reddit.
 * @param $files The files to sync.
 * @param $state The state the filse are in, as given by GitHub.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function sync_changed_files($files, $commit_id, $reason) {
	// Init git and checkout the 'after' state.
	git_init($commit_id);
	// Get the OAUTH token to be able to use the reddit API.
	$token = get_reddit_oauth_token();
	// Upload new/modified files and delete deleted ones.
	reddit_upload_files($files['upload'], $token, $reason);
	reddit_delete_files($files['delete'], $token, $reason);
}


/*
 * Queues the error message to be printed at the end of execution, without stopping the script.
 * The queue or error messages is printed automatically at the end of execution, along with setting the 500 HTTP status.
 * @param $msg Message to pass to GitGub.
 */
function queue_error($msg) {
	// NULL error string means no messages have been queued yet.
	if ($GLOBALS['ERROR_STRING'] === NULL) {
		$GLOBALS['ERROR_STRING'] = "";
		// Register a shutdown function to print the errors and set the 500 HTTP status.
		register_shutdown_function(function () {
			header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error", true, 500);
			echo($GLOBALS['ERROR_STRING']);
		});
	}
	$GLOBALS['ERROR_STRING'] .= "$msg\n";
}


/*
 * Stop execution, send an error response to GitHub and set the 500 HTTP status.
 * @param $msg Message to pass to GitGub.
 */
function error_exit($msg) {
	queue_error($msg);
	exit();
}


/*
 * Make sure git is available, pull changes and checkout the correct commit.
 * @param $git_ref Reference to a git state to checkout.
 */
function git_init($git_ref) {
	// Check if in a git repo.
	exec('git rev-parse --is-inside-work-tree', $output, $retval);
	if ($retval != 0 or $output[0] != 'true')
		error_exit('The script is not in a git repo.');
	//exec('git pull origin master', $output, $retval);
	if ($retval != 0)
		error_exit('Git fetch failed. Did you set up git credentials?');
	exec("git checkout $git_ref", $output, $retval);
	if ($retval != 0)
		error_exit('Unable to checkout the given commit.');
}


/*
 * Gets an OAUTH token for API authentication.
 * @return An OAUTH token for the reddit API.
 */
function get_reddit_oauth_token() {
	$oauth_config = $GLOBALS['config']['reddit'];
	$url = 'https://www.reddit.com/api/v1/access_token';
	$data = [
		'grant_type' => 'password',
		'username' => $oauth_config['mod_username'],
		'password' => $oauth_config['mod_password']
	];
	// OAUTH uses basic access authentication, add in header.
	$headers = [get_basic_authentication_header($oauth_config['client_id'], $oauth_config['secret'])];
	$response = post_request($url, $data, "Unable to get Reddit API access token, check your login credentials.", $headers);
	return $response['access_token'];
}


/*
 * Upload the files in the given list from Reddit.
 * @param $upload_list A list of files to upload.
 * @param $token Reddit OAUTH token.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function reddit_upload_files($upload_list, $token, $reason) {
	$github_config = $GLOBALS['config']['github'];
	// Remove the stylesheet_path, it has to be handled differently.
	$upload_list = array_diff($upload_list, [$github_config['stylesheet_path']]);

	// The assets must be uploaded before the stylesheet itself.

	// Upload changed assets.
	foreach ($upload_list as $upload_file)
		api_upload_image($upload_file, $token);

	// Always upload the stylesheet, since forgetting to upload an image would result in failure when trying to upload
	// the stylesheet. The stylesheet would only be uploaded again if it was changed again.
	$content = file_get_contents(git_to_absolute_path($github_config['stylesheet_path']));
	api_set_subreddit_stylesheet($token, $content, $reason);
}


/*
 * Delete the files in the given list from reddit.
 * @param $delete_list A list of file paths to delete.
 * @param $token Reddit OAUTH token.
 * @param $reason The reason to state when removing the subreddit stylesheet.
 */
function reddit_delete_files($delete_list, $token, $reason) {
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
		api_set_subreddit_stylesheet($token, '', $reason);
}


/*
 * Determine what kind of image upload to perform.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param $path Git path of the image file.
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
 * Converts a git path to the local path to to git object.
 * The 'git' command is assumed to be available.
 * @param $git_path The path of the object according to git.
 * @return The absolute path to the object according to the web server.
 */
function git_to_absolute_path($git_path) {
	static $git_root = NULL;
	if ($git_root === NULL)
		exec('git rev-parse --show-toplevel', $git_root);
	return $git_root[0].'/'.$git_path;
}


/*
 * Handle the response of an Reddit API call.
 * Specifically, check for errors and handle them.
 * @param $response_json The response of a Reddit API call.
 * @param $err_msg The error message to show if the call fails.
 * @return Parsed response object.
 */
function handle_api_response($response_json, $err_msg) {
	// FALSE: something went wrong in making the request itself.
	if ($response_json === FALSE) {
		queue_error("Could not successfully update reddit stylesheet.");
		$response = NULL;
	}
	// An error was caused in the API call.
	else {
		$response = json_decode($response_json, TRUE);
		if ($response == NULL)
			return NULL;
		// Two kinds of JSON error keys.
		if (key_exists('error', $response))
			queue_error($err_msg . "\n(". $response['error'] . ')');
		else if (!empty($response['json']['errors'])) {
			$errs = array_map(function($ar) { return '('.implode(", ", $ar).')'; }, $response['json']['errors']);
			queue_error($err_msg."\n(".implode("\n", $errs).')');
		}
	}
	return $response;
}


/*
 * Send a POST request.
 * All responses are expected to be JSON, as Reddit always does.
 * @param $url URL to send the request to.
 * @param $data Data to sent.
 * @param $err_msg The error message to be shown if the request fails.
 * @param $headers Extra headers to send if any (Default=[]).
 * @param $file_path File to send if there is any (Default=NULL).
 * @return Response object.
 */
function post_request($url, $data, $err_msg, $headers=[], $file_path=NULL) {
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
	$response_json = file_get_contents($url, FALSE, $context);
	return handle_api_response($response_json, $err_msg);
}


/*
 * Send an API POST request.
 * @param $api_method API method to call.
 * @param $data Data to sent.
 * @param $token Reddit API token.
 * @param $err_msg The error message to be shown if the request fails.
 * @param $file_path File to send if there is any (Default=NULL).
 * @return Response to API POST request.
 */
function api_post_request($api_method, $token, $data, $err_msg, $file_path=NULL) {
	$subreddit = $GLOBALS['config']['reddit']['subreddit'];
	$url = "https://oauth.reddit.com/r/$subreddit/api/$api_method";
	$headers = ['Authorization: bearer '.$token];
	return post_request($url, $data, $err_msg, $headers, $file_path);
}


/*
 * Send an API POST request to the reddit API for all affected subreddits.
 * @see https://www.reddit.com/dev/api/oauth#POST_api_upload_sr_img
 * @param $img_path Git path to the file to POST.
 * @param $token An Oauth token.
 */
function api_upload_image($img_path, $token) {
	$upload_type = get_upload_type($img_path);
	$path_parts = pathinfo($img_path);
	$local_img_path = git_to_absolute_path($img_path);

	$data = [
		'header' => $upload_type == 'header' ? 1 : 0,
		'img_type' => strtolower($path_parts['extension']),
		'name' => $path_parts['filename'],
		'upload_type' => $upload_type,
	];
	api_post_request('upload_sr_img', $token, $data,
			"Could not upload an image ($local_img_path), check that it fits within reddits guidelines:\n<=500kb, jpg or png", $local_img_path);
}


/*
 * Send an API request to delete an image.
 * @param $path Git path to the file to POST.
 * @param $token An Oauth token.
 */
function api_delete_image($path, $token) {
	$img_name = pathinfo($path, PATHINFO_FILENAME);
	$data = [
		'api_type' => 'json',
		'img_name' => $img_name,
	];
	api_post_request('delete_sr_img', $token, $data,"Could not delete an image ($path), it may have already been deleted manually.");
}


/*
 * Set the subreddit stylesheet.
 * @param $token An Oauth token.
 * @param $content The new content of the stylesheet.
 * @param $reason The reason to state when updating the subreddit stylesheet.
 */
function api_set_subreddit_stylesheet($token, $content, $reason) {
	$data = [
		'api_type' => 'json',
		'op' => 'save',
		'reason' => $reason,
		'stylesheet_contents' => $content
	];
	api_post_request('subreddit_stylesheet', $token, $data, "An error occurred while updating the stylesheet.\n" .
		'Make sure all used assets are either in the assets folder on git or already manually uploaded to reddit.');
}


/*
 * Build a multipart/form-data query body.
 * @param $data Data to encode.
 * @param $file_path File to encode, i.e. {key => x, path => y}.
 * @param $mime_boundary Boundary to use for encoding.
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
 * Get the HTTP basic authorization header.
 * @param $username Username to use for authentication.
 * @param $password Password to use for authentication.
 * @return The HTTP basic authorization header.
 */
function get_basic_authentication_header($username, $password) {
	return 'Authorization: Basic '.base64_encode("$username:$password");
}


/*
 * Get the tag of the previous release.
 * @param $repo_name Name of the repository.
 * @return The tag of the previous release or NULL if there isn't any.
 */
function get_previous_release_tag($repo_name) {
	$json = file_get_contents("https//api.github.com/repos/$repo_name/releases");
	if ($json === false)
		error_exit('Could not look up the previous version.');
	$releases = json_decode($json, TRUE);
	if (count($releases) == 1)
		return NULL;
	usort($releases, 'compare_release');
	return $releases[1]['tag_name'];
}


/*
 * Check whether a commit affects the stylesheet.
 * @param $commit Commit to check.
 * @return Whether the commit affects the stylesheet.
 */
function affects_stylesheet($commit) {
	$github_config = $GLOBALS['config']['github'];
	return in_array($github_config['stylesheet_path'], $commit['added']) or
			in_array($github_config['stylesheet_path'], $commit['modified']) or
			in_array($github_config['stylesheet_path'], $commit['removed']);
}


/*
 * Check whether a file path is that of the stylesheet.
 * @param $path A git path.
 * @return Whether the file path is that of the stylesheet.
 */
function is_stylesheet($path) {
	$github_config = $GLOBALS['config']['github'];
	return $path == $github_config['stylesheet_path'];
}

