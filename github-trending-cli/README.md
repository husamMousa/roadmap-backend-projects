# GitHub Trending CLI

A command-line tool that shows the trending repositories on GitHub for the past day, week, month or year, built with **plain PHP** (no frameworks, no external libraries). It uses the [GitHub Search API](https://docs.github.com/en/rest/search/search#search-repositories).

Project idea from [roadmap.sh](https://roadmap.sh/projects/github-trending-cli).

## How "trending" is calculated

GitHub has no official API for its trending page, so this tool defines trending as **the repositories created within the chosen time range, sorted by star count (highest first)**. For example, `--duration week` searches for `created:>=<date 7 days ago>` and sorts by stars. Dates are in UTC.

## Requirements

- PHP 8.1 or higher (`php -v` to check)
- An internet connection

## Installation

```bash
git clone https://github.com/husamMousa/roadmap-backend-projects.git
cd roadmap-backend-projects/github-trending-cli
```

You can now run `php trending-repos.php`. The folder also has two small launchers so you can type `trending-repos` instead:

- **macOS / Linux / Git Bash:** `./trending-repos` (run `chmod +x trending-repos` first if needed)
- **Windows (cmd / PowerShell):** `.\trending-repos.bat`

To run it from any folder, add the `github-trending-cli` folder to your `PATH`.

## Usage

```bash
# Top 10 of the past week (the defaults)
trending-repos

# Top 20 of the past month
trending-repos --duration month --limit 20

# The "=" form works too
trending-repos --duration=day --limit=5

# Help
trending-repos --help
```

| Option | Values | Default |
| --- | --- | --- |
| `--duration` | `day`, `week`, `month`, `year` | `week` |
| `--limit` | a whole number from `1` to `100` | `10` |

Example output:

```
Top 2 trending repositories of the past month (created since 2026-09-18):

1. storytold/photocraft
   Stars: 67,057 | Language: Rust
   PhotoCraft: An open-source, photo editor written by scratch in pure Rust
   https://github.com/storytold/photocraft

2. NandhaKishorM/laya
   Stars: 33,322 | Language: Python
   Non-autoregressive System 1 decision engine. Typed choice, score and yes/no
   decisions over any text in a single forward pass, in 100+ languages, with a
   router that picks the right checkpoint per request.
   https://github.com/NandhaKishorM/laya
```

## Configuration

No authentication is needed. Without it, GitHub allows **10 searches per minute** per IP address. If you hit that limit often, set a [personal access token](https://github.com/settings/tokens) (no scopes needed) in the `GITHUB_TOKEN` environment variable to raise it to 30 per minute:

```bash
export GITHUB_TOKEN=your_token_here
```

On Windows PowerShell, use `$env:GITHUB_TOKEN = "your_token_here"` instead.

## Error handling

The tool prints a clear message and exits with code `1` for:

- **Invalid input:** an unknown duration, a limit that isn't a whole number from 1 to 100, an option with no value, an unknown option, or an unexpected argument
- **API errors:** the rate limit being reached (with the seconds until it resets), an invalid `GITHUB_TOKEN` (401), a rejected query (422), and GitHub server errors (5xx)
- **Network and data problems:** no internet connection or a timeout, and a response that isn't valid JSON or doesn't have the expected shape

If GitHub stops a search early under heavy load, the results are still shown, with a note that the list may be incomplete.
