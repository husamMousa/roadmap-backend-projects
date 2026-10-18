<?php

/**
 * GitHub Trending CLI
 * -------------------
 * Shows the most-starred GitHub repositories created in the last day, week,
 * month or year. Plain PHP only: no Composer, no frameworks, no external libraries.
 *
 * GitHub has no official "trending" endpoint, so we build our own with the
 * Search API: "repositories created after DATE, sorted by stars, highest first".
 *
 * Usage:
 *   php trending-repos.php [--duration day|week|month|year] [--limit N]
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

const API_BASE_URL     = 'https://api.github.com';
const REQUEST_TIMEOUT  = 15;     // seconds, so we never hang forever
const DEFAULT_DURATION = 'week';
const DEFAULT_LIMIT    = 10;
const MAX_LIMIT        = 100;    // the Search API returns at most 100 results per page

// How far back each duration reaches. These strings are understood by DateTime::modify().
const DURATIONS = [
    'day'   => '-1 day',
    'week'  => '-1 week',
    'month' => '-1 month',
    'year'  => '-1 year',
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
    $durations = implode(', ', array_keys(DURATIONS));
    $default   = DEFAULT_DURATION;
    $limit     = DEFAULT_LIMIT;
    $max       = MAX_LIMIT;

    echo <<<USAGE
    GitHub Trending CLI
    Shows the most-starred repositories created in a recent time range.

    Usage:
      trending-repos [--duration <range>] [--limit <number>]

    Options:
      --duration <range>   Time range: {$durations} (default: {$default})
      --limit <number>     How many repositories to show, 1-{$max} (default: {$limit})
      -h, --help           Show this help

    Examples:
      trending-repos
      trending-repos --duration month --limit 20
      trending-repos --duration=day --limit=5

    Optional: set the GITHUB_TOKEN environment variable to get a higher API rate limit.

    USAGE;
}

// ============================================================================
// 3. COMMAND-LINE ARGUMENTS
// ============================================================================

/**
 * Read the options from $argv. Both "--limit 20" and "--limit=20" are accepted.
 * Returns null when the user asked for help.
 *
 * @param  string[] $args  the arguments WITHOUT the script name
 * @return array{duration: string, limit: int}|null
 */
function parseArguments(array $args): ?array
{
    // Start from the defaults; options the user gives overwrite them.
    $options = ['duration' => DEFAULT_DURATION, 'limit' => (string) DEFAULT_LIMIT];

    // A while loop (not foreach) because "--limit 20" consumes TWO items.
    $i = 0;
    while ($i < count($args)) {
        $arg = $args[$i++];

        if (in_array($arg, ['-h', '--help', 'help'], true)) {
            return null;
        }

        // Split "--limit=20" into name "limit" and value "20".
        // For "--limit" alone, $value stays null and we take the NEXT argument.
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

    return [
        'duration' => validateDuration($options['duration']),
        'limit'    => validateLimit($options['limit']),
    ];
}

function validateDuration(string $duration): string
{
    $duration = strtolower(trim($duration)); // be forgiving: "Month" works too

    if (!array_key_exists($duration, DURATIONS)) {
        $allowed = implode(', ', array_keys(DURATIONS));
        failUsage("Invalid duration \"{$duration}\". Use one of: {$allowed}.");
    }

    return $duration;
}

function validateLimit(string $limit): int
{
    // ctype_digit() only accepts 0-9, so "abc", "-5", "2.5" and "" are all rejected.
    // (is_numeric() would accept "2.5" and "1e3", which we don't want.)
    if (!ctype_digit($limit)) {
        failUsage("Invalid limit \"{$limit}\". It must be a whole number.");
    }

    $number = (int) $limit;

    if ($number < 1 || $number > MAX_LIMIT) {
        failUsage('Invalid limit ' . $number . '. It must be between 1 and ' . MAX_LIMIT . '.');
    }

    return $number;
}

// ============================================================================
// 4. BUILDING THE SEARCH QUERY
// ============================================================================

/**
 * The first day of the time range, as YYYY-MM-DD (the format GitHub's search expects).
 * We use UTC because GitHub stores dates in UTC.
 */
function startDate(string $duration): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(DURATIONS[$duration])
        ->format('Y-m-d');
}

function buildSearchUrl(string $since, int $limit): string
{
    // http_build_query() URL-encodes everything for us (">" becomes "%3E", etc.).
    $query = http_build_query([
        'q'        => "created:>={$since}",
        'sort'     => 'stars',
        'order'    => 'desc',
        'per_page' => $limit,
    ]);

    return API_BASE_URL . '/search/repositories?' . $query;
}

// ============================================================================
// 5. HTTP LAYER
// ============================================================================

/**
 * Turn PHP's raw header lines into a status code + an associative array.
 * If GitHub redirects us, PHP keeps the headers of EVERY response in order,
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
 * Call the GitHub Search API and return the decoded JSON response.
 * Every failure path ends in fail(), so the caller only ever sees good data.
 *
 * @return array<string, mixed>
 */
function fetchJson(string $url): array
{
    $headers = [
        // GitHub REJECTS requests without a User-Agent (403).
        'User-Agent: github-trending-cli',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    // Configuration from the environment: a token is optional, but raises the
    // Search API limit from 10 to 30 requests per minute. getenv() returns false if unset.
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && trim($token) !== '') {
        $headers[] = 'Authorization: Bearer ' . trim($token);
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => REQUEST_TIMEOUT,
            // Give us the body even on 4xx/5xx, so we can decide what to say.
            'ignore_errors' => true,
        ],
    ]);

    // The "@" silences PHP's own warning; we report the problem ourselves below.
    $body = @file_get_contents($url, false, $context);

    // false = we never got an HTTP response at all (no internet, DNS, timeout...).
    if ($body === false) {
        fail('Could not reach GitHub. Check your internet connection and try again.');
    }

    ['status' => $status, 'headers' => $responseHeaders] = parseResponseHeaders($http_response_header ?? []);

    match (true) {
        $status === 200 => null,
        $status === 401 => fail('GitHub rejected the GITHUB_TOKEN (HTTP 401). Check it, or unset it to run without one.'),
        isRateLimited($status, $responseHeaders) => fail(rateLimitMessage($responseHeaders)),
        $status === 422 => fail('GitHub could not process the search query (HTTP 422).'),
        $status >= 500  => fail("GitHub is having problems right now (HTTP {$status}). Try again later."),
        default         => fail("Unexpected response from GitHub (HTTP {$status})."),
    };

    try {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('GitHub returned data that is not valid JSON.');
    }

    // A search response is an object with an "items" list; anything else is unexpected.
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        fail('GitHub returned data in an unexpected format.');
    }

    return $data;
}

/**
 * GitHub signals "too many requests" with 403 or 429.
 * A 403 is only a rate limit if the remaining counter hit zero.
 *
 * @param array<string, string> $headers
 */
function isRateLimited(int $status, array $headers): bool
{
    return $status === 429
        || ($status === 403 && ($headers['x-ratelimit-remaining'] ?? null) === '0');
}

/**
 * Build a friendly message telling the user when they can try again.
 * The Search API limit is per MINUTE (unlike most of the API, which is per hour).
 *
 * @param array<string, string> $headers
 */
function rateLimitMessage(array $headers): string
{
    $message = 'GitHub API rate limit reached (10 searches per minute without a token).';

    // x-ratelimit-reset is a Unix timestamp of when the limit resets.
    if (isset($headers['x-ratelimit-reset'])) {
        $seconds  = max(1, (int) $headers['x-ratelimit-reset'] - time());
        $message .= " Try again in about {$seconds} second(s).";
    }

    return $message;
}

// ============================================================================
// 6. PARSING AND DISPLAYING THE RESULTS
// ============================================================================

/**
 * Keep only the fields we display, with safe fallbacks for missing values.
 *
 * @param  array<string, mixed> $item  one repository from the API response
 * @return array{name: string, description: string, stars: int, language: string, url: string}
 */
function toRepository(array $item): array
{
    return [
        'name'        => (string) ($item['full_name'] ?? 'unknown/unknown'),
        'description' => cleanText((string) ($item['description'] ?? '')),
        'stars'       => (int) ($item['stargazers_count'] ?? 0),
        // "language" is null when GitHub can't detect one (e.g. a repo with only Markdown).
        'language'    => (string) ($item['language'] ?? 'Unknown'),
        'url'         => (string) ($item['html_url'] ?? ''),
    ];
}

/**
 * Descriptions are written by repo owners, so they can contain newlines or
 * terminal control characters. Replace them with spaces and squeeze repeats.
 */
function cleanText(string $text): string
{
    // \p{Cc} = Unicode control characters; the /u flag makes the regex UTF-8 aware.
    $clean = preg_replace('/[\p{Cc}\s]+/u', ' ', $text);

    // preg_replace() returns null if the text isn't valid UTF-8, so fall back to the original.
    return trim($clean ?? $text);
}

/**
 * Sort by stars, highest first. The API already does this, but the project
 * asks us to sort, and it protects us if the API ever changes its order.
 *
 * @param  array<int, array{stars: int}> $repos
 * @return array<int, array{stars: int}>
 */
function sortByStars(array $repos): array
{
    // <=> ("spaceship") returns -1, 0 or 1. Putting $b first gives DESCENDING order.
    usort($repos, fn (array $a, array $b) => $b['stars'] <=> $a['stars']);

    return $repos;
}

/**
 * @param array<int, array{name: string, description: string, stars: int, language: string, url: string}> $repos
 */
function printRepositories(array $repos, string $duration, string $since): void
{
    $count = count($repos);
    $noun  = $count === 1 ? 'repository' : 'repositories';
    echo "Top {$count} trending {$noun} of the past {$duration} (created since {$since}):" . PHP_EOL . PHP_EOL;

    // Pad the numbers so "1." and "10." line up.
    $width = strlen((string) $count);

    foreach ($repos as $index => $repo) {
        $number = str_pad((string) ($index + 1), $width, ' ', STR_PAD_LEFT);
        $indent = str_repeat(' ', $width + 2);

        echo "{$number}. {$repo['name']}" . PHP_EOL;
        echo $indent . 'Stars: ' . number_format($repo['stars']) . " | Language: {$repo['language']}" . PHP_EOL;

        if ($repo['description'] !== '') {
            // wordwrap() breaks long lines at spaces; we indent every wrapped line.
            echo $indent . wordwrap($repo['description'], 76, PHP_EOL . $indent) . PHP_EOL;
        }

        echo $indent . $repo['url'] . PHP_EOL . PHP_EOL;
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

['duration' => $duration, 'limit' => $limit] = $options;

$since = startDate($duration);
$data  = fetchJson(buildSearchUrl($since, $limit));

$repos = sortByStars(array_map('toRepository', array_filter($data['items'], 'is_array')));

if ($repos === []) {
    echo "No repositories found that were created since {$since}." . PHP_EOL;
    exit(0);
}

printRepositories($repos, $duration, $since);

// Under heavy load GitHub may stop searching early and say so in this flag.
if (($data['incomplete_results'] ?? false) === true) {
    echo 'Note: GitHub returned incomplete results (the search timed out), so the list may be missing some repositories.' . PHP_EOL;
}
