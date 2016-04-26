<?php
ini_set('xdebug.var_display_max_depth', -1);
ini_set('xdebug.var_display_max_children', -1);
ini_set('xdebug.var_display_max_data', -1);
$payload = <<<HERE
{
  "ref": "refs/heads/master",
  "before": "e4c2753744afa64aec5b626e544026356deea059",
  "after": "ec91ed7d5f80c030110dea6107cebb2cf054bace",
  "created": false,
  "deleted": false,
  "forced": false,
  "base_ref": null,
  "compare": "https://github.com/JohnnyDeuss/test/compare/e4c2753744af...ec91ed7d5f80",
  "commits": [
    {
      "id": "ec91ed7d5f80c030110dea6107cebb2cf054bace",
      "tree_id": "e3973c2a63768eebac6a1f7a821d0b5ac58f108f",
      "distinct": true,
      "message": "Add testing stylesheet and asset data",
      "timestamp": "2016-04-26T03:41:01+02:00",
      "url": "https://github.com/JohnnyDeuss/test/commit/ec91ed7d5f80c030110dea6107cebb2cf054bace",
      "author": {
        "name": "JohnnyDeuss",
        "email": "johnnydeuss@gmail.com",
        "username": "JohnnyDeuss"
      },
      "committer": {
        "name": "JohnnyDeuss",
        "email": "johnnydeuss@gmail.com",
        "username": "JohnnyDeuss"
      },
      "added": [
        "assets/flairsheet.png",
        "assets/headerbypass.png",
        "assets/headercode.jpg",
        "assets/headerimg.png",
        "assets/logo.png",
        "assets/spritesheet.png",
        "web_design.css"
      ],
      "removed": [

      ],
      "modified": [
        "hooks/auto_updater.php"
      ]
    }
  ],
  "head_commit": {
    "id": "ec91ed7d5f80c030110dea6107cebb2cf054bace",
    "tree_id": "e3973c2a63768eebac6a1f7a821d0b5ac58f108f",
    "distinct": true,
    "message": "Add testing stylesheet and asset data",
    "timestamp": "2016-04-26T03:41:01+02:00",
    "url": "https://github.com/JohnnyDeuss/test/commit/ec91ed7d5f80c030110dea6107cebb2cf054bace",
    "author": {
      "name": "JohnnyDeuss",
      "email": "johnnydeuss@gmail.com",
      "username": "JohnnyDeuss"
    },
    "committer": {
      "name": "JohnnyDeuss",
      "email": "johnnydeuss@gmail.com",
      "username": "JohnnyDeuss"
    },
    "added": [
      "assets/flairsheet.png",
      "assets/headerbypass.png",
      "assets/headercode.jpg",
      "assets/headerimg.png",
      "assets/logo.png",
      "assets/spritesheet.png",
      "web_design.css"
    ],
    "removed": [

    ],
    "modified": [
      "hooks/auto_updater.php"
    ]
  },
  "repository": {
    "id": 56939297,
    "name": "test",
    "full_name": "JohnnyDeuss/test",
    "owner": {
      "name": "JohnnyDeuss",
      "email": "johnnydeuss@gmail.com"
    },
    "private": true,
    "html_url": "https://github.com/JohnnyDeuss/test",
    "description": "",
    "fork": false,
    "url": "https://github.com/JohnnyDeuss/test",
    "forks_url": "https://api.github.com/repos/JohnnyDeuss/test/forks",
    "keys_url": "https://api.github.com/repos/JohnnyDeuss/test/keys{/key_id}",
    "collaborators_url": "https://api.github.com/repos/JohnnyDeuss/test/collaborators{/collaborator}",
    "teams_url": "https://api.github.com/repos/JohnnyDeuss/test/teams",
    "hooks_url": "https://api.github.com/repos/JohnnyDeuss/test/hooks",
    "issue_events_url": "https://api.github.com/repos/JohnnyDeuss/test/issues/events{/number}",
    "events_url": "https://api.github.com/repos/JohnnyDeuss/test/events",
    "assignees_url": "https://api.github.com/repos/JohnnyDeuss/test/assignees{/user}",
    "branches_url": "https://api.github.com/repos/JohnnyDeuss/test/branches{/branch}",
    "tags_url": "https://api.github.com/repos/JohnnyDeuss/test/tags",
    "blobs_url": "https://api.github.com/repos/JohnnyDeuss/test/git/blobs{/sha}",
    "git_tags_url": "https://api.github.com/repos/JohnnyDeuss/test/git/tags{/sha}",
    "git_refs_url": "https://api.github.com/repos/JohnnyDeuss/test/git/refs{/sha}",
    "trees_url": "https://api.github.com/repos/JohnnyDeuss/test/git/trees{/sha}",
    "statuses_url": "https://api.github.com/repos/JohnnyDeuss/test/statuses/{sha}",
    "languages_url": "https://api.github.com/repos/JohnnyDeuss/test/languages",
    "stargazers_url": "https://api.github.com/repos/JohnnyDeuss/test/stargazers",
    "contributors_url": "https://api.github.com/repos/JohnnyDeuss/test/contributors",
    "subscribers_url": "https://api.github.com/repos/JohnnyDeuss/test/subscribers",
    "subscription_url": "https://api.github.com/repos/JohnnyDeuss/test/subscription",
    "commits_url": "https://api.github.com/repos/JohnnyDeuss/test/commits{/sha}",
    "git_commits_url": "https://api.github.com/repos/JohnnyDeuss/test/git/commits{/sha}",
    "comments_url": "https://api.github.com/repos/JohnnyDeuss/test/comments{/number}",
    "issue_comment_url": "https://api.github.com/repos/JohnnyDeuss/test/issues/comments{/number}",
    "contents_url": "https://api.github.com/repos/JohnnyDeuss/test/contents/{+path}",
    "compare_url": "https://api.github.com/repos/JohnnyDeuss/test/compare/{base}...{head}",
    "merges_url": "https://api.github.com/repos/JohnnyDeuss/test/merges",
    "archive_url": "https://api.github.com/repos/JohnnyDeuss/test/{archive_format}{/ref}",
    "downloads_url": "https://api.github.com/repos/JohnnyDeuss/test/downloads",
    "issues_url": "https://api.github.com/repos/JohnnyDeuss/test/issues{/number}",
    "pulls_url": "https://api.github.com/repos/JohnnyDeuss/test/pulls{/number}",
    "milestones_url": "https://api.github.com/repos/JohnnyDeuss/test/milestones{/number}",
    "notifications_url": "https://api.github.com/repos/JohnnyDeuss/test/notifications{?since,all,participating}",
    "labels_url": "https://api.github.com/repos/JohnnyDeuss/test/labels{/name}",
    "releases_url": "https://api.github.com/repos/JohnnyDeuss/test/releases{/id}",
    "deployments_url": "https://api.github.com/repos/JohnnyDeuss/test/deployments",
    "created_at": 1461443114,
    "updated_at": "2016-04-23T23:29:26Z",
    "pushed_at": 1461634868,
    "git_url": "git://github.com/JohnnyDeuss/test.git",
    "ssh_url": "git@github.com:JohnnyDeuss/test.git",
    "clone_url": "https://github.com/JohnnyDeuss/test.git",
    "svn_url": "https://github.com/JohnnyDeuss/test",
    "homepage": null,
    "size": 26,
    "stargazers_count": 0,
    "watchers_count": 0,
    "language": "PHP",
    "has_issues": false,
    "has_downloads": true,
    "has_wiki": false,
    "has_pages": false,
    "forks_count": 0,
    "mirror_url": null,
    "open_issues_count": 0,
    "forks": 0,
    "open_issues": 0,
    "watchers": 0,
    "default_branch": "master",
    "stargazers": 0,
    "master_branch": "master"
  },
  "pusher": {
    "name": "JohnnyDeuss",
    "email": "johnnydeuss@gmail.com"
  },
  "sender": {
    "login": "JohnnyDeuss",
    "id": 6266815,
    "avatar_url": "https://avatars.githubusercontent.com/u/6266815?v=3",
    "gravatar_id": "",
    "url": "https://api.github.com/users/JohnnyDeuss",
    "html_url": "https://github.com/JohnnyDeuss",
    "followers_url": "https://api.github.com/users/JohnnyDeuss/followers",
    "following_url": "https://api.github.com/users/JohnnyDeuss/following{/other_user}",
    "gists_url": "https://api.github.com/users/JohnnyDeuss/gists{/gist_id}",
    "starred_url": "https://api.github.com/users/JohnnyDeuss/starred{/owner}{/repo}",
    "subscriptions_url": "https://api.github.com/users/JohnnyDeuss/subscriptions",
    "organizations_url": "https://api.github.com/users/JohnnyDeuss/orgs",
    "repos_url": "https://api.github.com/users/JohnnyDeuss/repos",
    "events_url": "https://api.github.com/users/JohnnyDeuss/events{/privacy}",
    "received_events_url": "https://api.github.com/users/JohnnyDeuss/received_events",
    "type": "User",
    "site_admin": false
  }
}
HERE;
$url = 'http://localhost/web_design/hooks/auto_updater.php';
$headers = ['Content-Type: application/x-www-form-urlencoded'];
$options = [
    'http' => [
        'method'  => 'POST',
        'content' => $payload,
        'header' => $headers
    ]
];
$context  = stream_context_create($options);
var_dump(file_get_contents($url, FALSE, $context));