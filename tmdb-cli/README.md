# TMDB CLI Tool

A command-line tool that shows the now playing, popular, top-rated and upcoming movies from [The Movie Database (TMDB)](https://www.themoviedb.org/), built with **plain PHP** (no frameworks, no external libraries). It uses the [TMDB API](https://developer.themoviedb.org/reference/intro/getting-started).

Project idea from [roadmap.sh](https://roadmap.sh/projects/tmdb-cli).

## Requirements

- PHP 8.1 or higher (`php -v` to check)
- An internet connection
- A free TMDB API key (see below)

## Getting an API key

1. Create a free account at [themoviedb.org](https://www.themoviedb.org/signup).
2. Go to **Settings → API** ([direct link](https://www.themoviedb.org/settings/api)) and request a developer key.
3. Copy either the **API Key** (short) or the **API Read Access Token** (long). The tool accepts both.
4. Put it in the `TMDB_API_KEY` environment variable:

```bash
# macOS / Linux / Git Bash
export TMDB_API_KEY=your_key_here
```

```powershell
# Windows PowerShell
$env:TMDB_API_KEY = "your_key_here"
```

These commands set the key for the current terminal only. The key is never stored in the code, so it can't end up in git by accident.

## Installation

```bash
git clone https://github.com/husamMousa/roadmap-backend-projects.git
cd roadmap-backend-projects/tmdb-cli
```

You can now run `php tmdb-app.php`. The folder also has two small launchers so you can type `tmdb-app` instead:

- **macOS / Linux / Git Bash:** `./tmdb-app` (run `chmod +x tmdb-app` first if needed)
- **Windows (cmd / PowerShell):** `.\tmdb-app.bat`

To run it from any folder, add the `tmdb-cli` folder to your `PATH`.

## Usage

```bash
tmdb-app --type "playing"
tmdb-app --type "popular"
tmdb-app --type "top"
tmdb-app --type "upcoming"

# The next page of results (TMDB returns 20 movies per page)
tmdb-app --type top --page 2

# The "=" form works too
tmdb-app --type=upcoming

# Help
tmdb-app --help
```

| Option | Values | Default |
| --- | --- | --- |
| `--type` | `playing`, `popular`, `top`, `upcoming` | required |
| `--page` | a whole number from `1` to `500` | `1` |

Each type maps to one TMDB endpoint:

| Type | Endpoint |
| --- | --- |
| `playing` | [`/movie/now_playing`](https://developer.themoviedb.org/reference/movie-now-playing-list) |
| `popular` | [`/movie/popular`](https://developer.themoviedb.org/reference/movie-popular-list) |
| `top` | [`/movie/top_rated`](https://developer.themoviedb.org/reference/movie-top-rated-list) |
| `upcoming` | [`/movie/upcoming`](https://developer.themoviedb.org/reference/movie-upcoming-list) |

Example output (your results will differ):

```
Top-rated movies (page 1 of 500):

 1. The Shawshank Redemption (1994)
    Rating: 8.7/10 (29,113 votes) | Release date: 1994-09-23
    Imprisoned in the 1940s for the double murder of his wife and her lover,
    upstanding banker Andy Dufresne begins a new life at the Shawshank prison...

 2. The Godfather (1972)
    Rating: 8.7/10 (22,071 votes) | Release date: 1972-03-14
    Spanning the years 1945 to 1955, a chronicle of the fictional Italian-
    American Corleone crime family...
```

## Error handling

The tool prints a clear message to STDERR and exits with code `1` for:

- **Invalid input:** a missing or unknown `--type`, a page that isn't a whole number from 1 to 500, an option with no value, an unknown option, or an unexpected argument
- **A missing key:** `TMDB_API_KEY` not set, with a link to where to get one
- **API errors:** an invalid key (401), a missing list (404), too many requests (429, with the wait time when TMDB sends one), TMDB server errors (5xx), and any other error status together with TMDB's own `status_message`
- **Network and data problems:** no internet connection or a timeout (15 seconds), and a response that isn't valid JSON or doesn't have the expected shape

Asking for a page past the end of a list isn't an error: the tool says the page is empty and how many pages the list has.
