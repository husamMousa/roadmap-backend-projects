<?php

/**
 * TMDB CLI Tool
 * -------------
 * Shows the now playing, popular, top-rated and upcoming movies from
 * The Movie Database (TMDB). Plain PHP only: no Composer, no frameworks,
 * no external libraries.
 *
 * Usage:
 *   php tmdb-app.php --type playing|popular|top|upcoming [--page N]
 *
 * Needs a free TMDB API key in the TMDB_API_KEY environment variable.
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

const API_BASE_URL    = 'https://api.themoviedb.org/3';
const REQUEST_TIMEOUT = 15;       // seconds, so we never hang forever
const LANGUAGE        = 'en-US';  // language of titles and overviews
const DEFAULT_PAGE    = 1;
const MAX_PAGE        = 500;      // TMDB refuses pages above 500

// Every --type the user can pass, mapped to its TMDB endpoint and a heading for the output.
const MOVIE_TYPES = [
    'playing'  => ['endpoint' => '/movie/now_playing', 'title' => 'Now playing'],
    'popular'  => ['endpoint' => '/movie/popular',     'title' => 'Popular'],
    'top'      => ['endpoint' => '/movie/top_rated',   'title' => 'Top-rated'],
    'upcoming' => ['endpoint' => '/movie/upcoming',    'title' => 'Upcoming'],
];

// ============================================================================
// 2. OUTPUT HELPERS
// ============================================================================

/**
 * Print an error to STDERR and stop with exit code 1 (= failure).
 */
function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    exit(1);
}

/**
 * Like fail(), but also points the user to --help (for mistakes in their input).
 */
function failUsage(string $message): never
{
    fail($message . PHP_EOL . 'Run with --help to see how to use it.');
}

function printUsage(): void
{
    $types = implode(', ', array_keys(MOVIE_TYPES));
    $max   = MAX_PAGE;

    echo <<<USAGE
    TMDB CLI Tool
    Shows movies from The Movie Database (TMDB).

    Usage:
      tmdb-app --type <type> [--page <number>]

    Options:
      --type <type>       Which movies to show (required): {$types}
      --page <number>     Which page of results, 1-{$max} (default: 1)
      -h, --help          Show this help

    Examples:
      tmdb-app --type playing
      tmdb-app --type "top" --page 2
      tmdb-app --type=upcoming

    Needs a TMDB API key in the TMDB_API_KEY environment variable (see the README).

    USAGE;
}

// ============================================================================
// 3. COMMAND-LINE ARGUMENTS
// ============================================================================

/**
 * Read the options from $argv. Both "--page 2" and "--page=2" are accepted.
 * Returns null when the user asked for help.
 *
 * @param  string[] $args  the arguments WITHOUT the script name
 * @return array{type: string, page: int}|null
 */
function parseArguments(array $args): ?array
{
    // --type has no default (it's required), so it starts as null.
    $options = ['type' => null, 'page' => (string) DEFAULT_PAGE];

    // A while loop (not foreach) because "--page 2" consumes TWO items.
    $i = 0;
    while ($i < count($args)) {
        $arg = $args[$i++];

        if (in_array($arg, ['-h', '--help', 'help'], true)) {
            return null;
        }

        // Split "--page=2" into name "page" and value "2".
        // For "--page" alone, $value stays null and we take the NEXT argument.
        if (!preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $match)) {
            failUsage("Unexpected argument \"{$arg}\".");
        }

        $name  = $match[1];
        $value = $match[2] ?? null;

        if (!array_key_exists($name, $options)) {
            failUsage("Unknown option \"--{$name}\".");
        }

        if ($value === null) {
            // The next item must exist and must not be another option.
            if (!isset($args[$i]) || str_starts_with($args[$i], '--')) {
                failUsage("Option \"--{$name}\" needs a value.");
            }
            $value = $args[$i++];
        }

        $options[$name] = $value;
    }

    if ($options['type'] === null) {
        $types = implode(', ', array_keys(MOVIE_TYPES));
        failUsage("Missing the --type option. Use one of: {$types}.");
    }

    return [
        'type' => validateType($options['type']),
        'page' => validatePage($options['page']),
    ];
}

function validateType(string $type): string
{
    $type = strtolower(trim($type)); // be forgiving: "Popular" works too

    if (!array_key_exists($type, MOVIE_TYPES)) {
        $allowed = implode(', ', array_keys(MOVIE_TYPES));
        failUsage("Invalid type \"{$type}\". Use one of: {$allowed}.");
    }

    return $type;
}

function validatePage(string $page): int
{
    // ctype_digit() only accepts 0-9, so "abc", "-5", "2.5" and "" are all rejected.
    if (!ctype_digit($page)) {
        failUsage("Invalid page \"{$page}\". It must be a whole number.");
    }

    $number = (int) $page;

    if ($number < 1 || $number > MAX_PAGE) {
        failUsage('Invalid page ' . $number . '. It must be between 1 and ' . MAX_PAGE . '.');
    }

    return $number;
}

// ============================================================================
// 4. BUILDING THE REQUEST
// ============================================================================

/**
 * Read the API key from the environment. getenv() returns false if it's unset.
 * Keeping secrets out of the code means they never end up in git.
 */
function readApiKey(): string
{
    $key = getenv('TMDB_API_KEY');

    if (!is_string($key) || trim($key) === '') {
        fail(
            'The TMDB_API_KEY environment variable is not set.' . PHP_EOL
            . 'Get a free key at https://www.themoviedb.org/settings/api, then see the README for how to set it.'
        );
    }

    return trim($key);
}

/**
 * Build the URL and headers for one TMDB list endpoint.
 *
 * TMDB gives you two kinds of credentials and accepts either one:
 *  - the short "API Key" (32 hex characters), sent as ?api_key=... in the URL
 *  - the long "API Read Access Token", a JWT (three parts joined by dots),
 *    sent in the "Authorization: Bearer ..." header
 *
 * @return array{url: string, headers: string[]}
 */
function buildRequest(string $endpoint, int $page, string $apiKey): array
{
    $query   = ['language' => LANGUAGE, 'page' => $page];
    $headers = ['Accept: application/json', 'User-Agent: tmdb-cli'];

    if (substr_count($apiKey, '.') === 2) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    } else {
        $query['api_key'] = $apiKey;
    }

    // http_build_query() URL-encodes every value for us.
    return [
        'url'     => API_BASE_URL . $endpoint . '?' . http_build_query($query),
        'headers' => $headers,
    ];
}

// ============================================================================
// 5. HTTP LAYER
// ============================================================================

/**
 * Turn PHP's raw header lines into a status code + an associative array.
 * If we get redirected, PHP keeps the headers of EVERY response in order,
 * so whenever we see a new status line we start fresh (the last one wins).
 *
 * @param  string[] $rawHeaders
 * @return array{status: int, headers: array<string, string>}
 */
function parseResponseHeaders(array $rawHeaders): array
{
    $status  = 0;
    $headers = [];

    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
            $status  = (int) $match[1];
            $headers = [];
            continue;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return ['status' => $status, 'headers' => $headers];
}

/**
 * Call TMDB and return the decoded JSON response.
 * Every failure path ends in fail(), so the caller only ever sees good data.
 *
 * @param  string[] $headers
 * @return array<string, mixed>
 */
function fetchJson(string $url, array $headers): array
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => REQUEST_TIMEOUT,
            // Give us the body even on 4xx/5xx, so we can read TMDB's error message.
            'ignore_errors' => true,
        ],
    ]);

    // The "@" silences PHP's own warning; we report the problem ourselves below.
    // (PHP's warning would also print the full URL, which can contain the API key.)
    $body = @file_get_contents($url, false, $context);

    // false = we never got an HTTP response at all (no internet, DNS, timeout...).
    if ($body === false) {
        fail('Could not reach TMDB. Check your internet connection and try again.');
    }

    ['status' => $status, 'headers' => $responseHeaders] = parseResponseHeaders($http_response_header ?? []);

    // Decode before checking the status: TMDB's error responses are JSON too,
    // with a human-readable "status_message" we can show the user.
    $data = decodeJson($body);

    match (true) {
        $status === 200 => null,
        $status === 401 => fail('TMDB rejected your API key (HTTP 401). Check the TMDB_API_KEY environment variable.'),
        $status === 404 => fail('TMDB could not find that movie list (HTTP 404).'),
        $status === 429 => fail(rateLimitMessage($responseHeaders)),
        $status >= 500  => fail("TMDB is having problems right now (HTTP {$status}). Try again later."),
        default         => fail("Unexpected response from TMDB (HTTP {$status})" . tmdbMessage($data) . '.'),
    };

    if ($data === null) {
        fail('TMDB returned data that is not valid JSON.');
    }

    // A list response is an object with a "results" list; anything else is unexpected.
    if (!isset($data['results']) || !is_array($data['results'])) {
        fail('TMDB returned data in an unexpected format.');
    }

    return $data;
}

/**
 * Decode a JSON object, or return null if the text isn't one.
 *
 * @return array<string, mixed>|null
 */
function decodeJson(string $body): ?array
{
    try {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($data) ? $data : null;
}

/**
 * TMDB error bodies look like {"status_code": 22, "status_message": "Invalid page..."}.
 * Returns ": <message>" to append to our own error, or "" if there is none.
 *
 * @param array<string, mixed>|null $data
 */
function tmdbMessage(?array $data): string
{
    $message = $data['status_message'] ?? null;

    return is_string($message) && $message !== '' ? ': ' . rtrim($message, '.') : '';
}

/**
 * Build a friendly message telling the user when they can try again.
 * Retry-After (seconds to wait) is a standard header sent with HTTP 429.
 *
 * @param array<string, string> $headers
 */
function rateLimitMessage(array $headers): string
{
    $message = 'Too many requests to TMDB (HTTP 429).';

    if (isset($headers['retry-after']) && ctype_digit($headers['retry-after'])) {
        $message .= " Try again in about {$headers['retry-after']} second(s).";
    } else {
        $message .= ' Wait a moment and try again.';
    }

    return $message;
}

// ============================================================================
// 6. PARSING AND DISPLAYING THE RESULTS
// ============================================================================

/**
 * Keep only the fields we display, with safe fallbacks for missing values.
 *
 * @param  array<string, mixed> $item  one movie from the API response
 * @return array{title: string, releaseDate: string, rating: float, votes: int, overview: string}
 */
function toMovie(array $item): array
{
    return [
        'title'       => cleanText((string) ($item['title'] ?? 'Untitled')),
        // release_date is "YYYY-MM-DD", or "" when TMDB doesn't know it yet.
        'releaseDate' => (string) ($item['release_date'] ?? ''),
        'rating'      => (float) ($item['vote_average'] ?? 0),
        'votes'       => (int) ($item['vote_count'] ?? 0),
        'overview'    => cleanText((string) ($item['overview'] ?? '')),
    ];
}

/**
 * Titles and overviews are written by TMDB users, so they can contain newlines
 * or terminal control characters. Replace them with spaces and squeeze repeats.
 */
function cleanText(string $text): string
{
    // \p{Cc} = Unicode control characters; the /u flag makes the regex UTF-8 aware.
    $clean = preg_replace('/[\p{Cc}\s]+/u', ' ', $text);

    // preg_replace() returns null if the text isn't valid UTF-8, so fall back to the original.
    return trim($clean ?? $text);
}

/**
 * "Title (2026)" when we know the release year, otherwise just "Title".
 *
 * @param array{title: string, releaseDate: string} $movie
 */
function titleWithYear(array $movie): string
{
    $year = substr($movie['releaseDate'], 0, 4);

    return ctype_digit($year) ? "{$movie['title']} ({$year})" : $movie['title'];
}

/**
 * @param array{rating: float, votes: int} $movie
 */
function formatRating(array $movie): string
{
    // A 0.0 average with no votes means "nobody rated it", not "rated terribly".
    if ($movie['votes'] === 0) {
        return 'Rating: not rated yet';
    }

    $noun = $movie['votes'] === 1 ? 'vote' : 'votes';

    return sprintf('Rating: %.1f/10 (%s %s)', $movie['rating'], number_format($movie['votes']), $noun);
}

/**
 * The heading above the list, e.g. "Popular movies (page 1 of 500)".
 * Now playing and upcoming lists also tell us the release dates they cover.
 *
 * @param array<string, mixed> $data  the full API response
 */
function heading(string $type, array $data): string
{
    $page       = (int) ($data['page'] ?? 1);
    $totalPages = (int) ($data['total_pages'] ?? $page);
    $heading    = MOVIE_TYPES[$type]['title'] . " movies (page {$page} of {$totalPages})";

    $minimum = $data['dates']['minimum'] ?? null;
    $maximum = $data['dates']['maximum'] ?? null;
    if (is_string($minimum) && is_string($maximum)) {
        $heading .= ", released {$minimum} to {$maximum}";
    }

    return $heading . ':';
}

/**
 * @param array<int, array{title: string, releaseDate: string, rating: float, votes: int, overview: string}> $movies
 */
function printMovies(array $movies, string $heading): void
{
    echo $heading . PHP_EOL . PHP_EOL;

    // Pad the numbers so "1." and "10." line up.
    $width = strlen((string) count($movies));

    foreach ($movies as $index => $movie) {
        $number = str_pad((string) ($index + 1), $width, ' ', STR_PAD_LEFT);
        $indent = str_repeat(' ', $width + 2);
        $date   = $movie['releaseDate'] !== '' ? $movie['releaseDate'] : 'unknown';

        echo "{$number}. " . titleWithYear($movie) . PHP_EOL;
        echo $indent . formatRating($movie) . " | Release date: {$date}" . PHP_EOL;

        if ($movie['overview'] !== '') {
            // wordwrap() breaks long lines at spaces; we indent every wrapped line.
            echo $indent . wordwrap($movie['overview'], 76, PHP_EOL . $indent) . PHP_EOL;
        }

        echo PHP_EOL;
    }
}

// ============================================================================
// 7. MAIN PROGRAM
// ============================================================================

// array_slice() drops $argv[0], which is the script name.
$options = parseArguments(array_slice($argv, 1));

if ($options === null) {
    printUsage();
    exit(0);
}

['type' => $type, 'page' => $page] = $options;

// Validate the input BEFORE asking for the key, so typos get the more useful message first.
$request = buildRequest(MOVIE_TYPES[$type]['endpoint'], $page, readApiKey());
$data    = fetchJson($request['url'], $request['headers']);

$movies = array_map('toMovie', array_values(array_filter($data['results'], 'is_array')));

if ($movies === []) {
    // TMDB answers a page past the end with an empty list rather than an error.
    $totalPages = (int) ($data['total_pages'] ?? 0);
    echo "No movies found on page {$page} (this list has {$totalPages} page(s))." . PHP_EOL;
    exit(0);
}

printMovies($movies, heading($type, $data));
