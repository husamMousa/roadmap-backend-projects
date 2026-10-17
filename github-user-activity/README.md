# GitHub User Activity CLI

A simple command-line app that fetches a GitHub user's recent public activity and prints it in the terminal, built with **plain PHP** (no frameworks, no external libraries). It uses the [GitHub Events API](https://docs.github.com/en/rest/activity/events).

Project idea from [roadmap.sh](https://roadmap.sh/projects/github-user-activity).

## Requirements

- PHP 8.1 or higher (`php -v` to check)
- An internet connection

## Installation

```bash
git clone https://github.com/husamMousa/roadmap-backend-projects.git
cd roadmap-backend-projects/github-user-activity
```

Run it with `php github-activity.php <username>`. The examples below write `github-activity` as shorthand for `php github-activity.php`.

## Usage

```bash
# Show a user's recent activity
github-activity husamMousa

# Help
github-activity --help
```

Example output:

```
Recent activity for husamMousa:
- Commented on pull request #6870 in nuxt/ui
- Pushed to branch fix/changelog-version-indicator-overlap in husamMousa/ui (3 times)
```

Identical events in a row are grouped into one line with a counter, such as `(3 times)`.

## Supported events

| Event type | Example output |
| --- | --- |
| `PushEvent` | Pushed to branch main in owner/repo |
| `IssuesEvent` | Opened issue #12 in owner/repo |
| `IssueCommentEvent` | Commented on issue #12 in owner/repo |
| `PullRequestEvent` | Merged pull request #7 in owner/repo |
| `PullRequestReviewEvent` | Reviewed a pull request in owner/repo |
| `PullRequestReviewCommentEvent` | Commented on a pull request review in owner/repo |
| `CommitCommentEvent` | Commented on a commit in owner/repo |
| `WatchEvent` | Starred owner/repo |
| `ForkEvent` | Forked owner/repo |
| `CreateEvent` | Created branch feature-x in owner/repo |
| `DeleteEvent` | Deleted branch feature-x in owner/repo |
| `ReleaseEvent` | Published release v1.0.0 in owner/repo |
| `PublicEvent` | Made owner/repo public |
| `MemberEvent` | Added a collaborator in owner/repo |
| `GollumEvent` | Updated the wiki of owner/repo |

Any other event type is still shown in a generic form, for example `Sponsorship in owner/repo`.

> GitHub no longer includes commit counts in `PushEvent` payloads, so pushes show the branch name instead of "Pushed N commits".

## Limits

- The Events API only returns public activity from the **last 90 days**.
- Without authentication, GitHub allows **60 requests per hour** per IP address.

## Error handling

The app prints a clear message and exits with code `1` for: a missing username, an invalid username format, a user that doesn't exist (404), the API rate limit being reached (with the minutes until it resets), GitHub server errors (5xx), no internet connection or a timeout, and an invalid JSON response.
