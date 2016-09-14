# SubredditStyleSync
GitHub webhook to automatically synchronize a subreddit's stylesheet and
accompanying images when they are pushed or when there is a new release.

## Setup
This script uses [Github's webhooks](https://developer.github.com/webhooks/)
and the [Reddit API](https://www.reddit.com/dev/api) to do what it does.
The script has to be placed on a webserver and needs access to the git command.

1. Make sure the subreddit has the newest version of the styling, because the webhook will only sync
   things that change. Missing images before activating the sync script result in the stylesheet
   not updating (just like when done manually). Also, remember that manual changes will only be
   overriden when the same file is changed on GitHub, which also means that manually removing images
   will result in the stylesheet not updating until it no longer contains that image or the image is
   restored.
2. Set up a local GitHub repo of your styling somewhere in the webroot of your server, or make it
   accessible through the server, and place auto_updater.php (the webhook) and config.php somewhere
   in that repo. It does not have to be in the root of the git repository, but it does have to be
   accessible from the outside, as we'll set up GitHub to send webhook events to auto_updater.php.
3. The webhook uses the git command through exec(), so make sure the script can use it without
   entering a password. This can be done either by using
   [git's credential store](https://git-scm.com/docs/git-credential-store)
   or by [setting up an SSH key passphrase](https://help.github.com/articles/working-with-ssh-key-passphrases/)
   (more secure).
4. Set up the webhook on the styling Github, as explained
   [here](https://developer.github.com/webhooks/creating/). You'll have to fill out the following
   values:
  * Payload URL: the URL pointing to autoupdater.php on your server.
  * Content type: "application/json".
  * Which events would you like to trigger this webhook?: "Just the push event".
  * Secret: a long complex random string used to verify webhook events.
  * Active: leave unchecked for now.
5. Set up the script to allow access to the Reddit API, as explained under "first steps"
   [here](https://github.com/reddit/reddit/wiki/OAuth2-Quick-Start-Example#user-content-first-steps). 
6. Fill in the configuration in config.php:
  * subreddit_name: The name of the subreddit you want to sync the styling for.
  * github, all values are optional, all paths are relative to the git root, NULL to disable:
    * secret: The secret you entered in step 4.
    * stylesheet_path: Path to the stylesheet.
    * assets_dir: Path to the assets folder, containing all images.
    * header_path: Path to the header file, i.e. the subreddit logo.
    * icon_mobile_path: Path to the mobile subreddit icon.
    * header_mobile_path: Path to the subreddit banner/mobile header.
  * reddit:
    * mod_username: Username of a moderator of the subreddit to sync.
    * mod_password: Password of that moderator.
    * secret: The secret of the reddit script, generated when registering the script in step 5. It
	  is shown after pressing edit on the app on [the apps page](https://www.reddit.com/prefs/apps/)
    * client_id: The client ID of the reddit script, generated when registering the script in step 5.
      It is shown under the name of the app on [the apps page](https://www.reddit.com/prefs/apps/).
7. If done correctly, the script should now be working. Go back to the webhook configuration page
   and set it to active. Test the script by pushing a commit. You can see the response of the script
   under recent deliveries in the webhook configuration page. If you get a green checkmark, the
   script works. If you get a red triangle, you can click delivery ID next to it and see what went
   wrong by looking in the response tab. Protip: you can redeliver the same event from here, so you
   don't have to repeatedly push something while testing.
  