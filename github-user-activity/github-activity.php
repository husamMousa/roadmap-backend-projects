<?php

/**
 * GitHub User Activity CLI
 * ------------------------
 * Fetches a GitHub user's recent public activity and prints it in the terminal.
 * Plain PHP only: no Composer, no frameworks, no external libraries.
 *
 * Usage:
 *   php github-activity.php <username>
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

const API_BASE_URL    = 'https://api.github.com';
const REQUEST_TIMEOUT = 10; // seconds, so we never hang forever

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

function printUsage(): void
{
    echo <<<USAGE
    GitHub User Activity CLI

    Usage:
      github-activity <username>

    Example:
      php github-activity.php kamranahmedse

    USAGE;
}

// ============================================================================
// 3. INPUT VALIDATION
// ============================================================================

/**
 * GitHub usernames: letters, digits and single hyphens, max 39 characters,
 * and they can't start or end with a hyphen.
 * Checking this BEFORE calling the API saves a request (we only get 60/hour).
 */
function isValidUsername(string $username): bool
{
    // (?=...) is a "lookahead": a hyphen is only allowed if a letter/digit follows it.
    return preg_match('/^[a-z\d](?:[a-z\d]|-(?=[a-z\d])){0,38}$/i', $username) === 1;
}

// ============================================================================
// 4. HTTP LAYER
// ============================================================================

/**
 * Turn PHP's raw header lines into a status code + an associative array.
 *
 * Raw lines look like:
 *   "HTTP/1.1 200 OK"
 *   "X-RateLimit-Remaining: 49"
 *
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

        // Split "Name: value" on the FIRST colon only (values may contain colons).
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            // Header names are case-insensitive, so we normalize to lowercase.
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return ['status' => $status, 'headers' => $headers];
}

/**
 * Call the GitHub API and return the decoded list of events.
 * Every failure path ends in fail(), so the caller only ever sees good data.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchEvents(string $username): array
{
    $url = API_BASE_URL . '/users/' . urlencode($username) . '/events';

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", [
                // GitHub REJECTS requests without a User-Agent (403).
                'User-Agent: github-activity-cli',
                'Accept: application/vnd.github+json',
            ]),
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

    // PHP fills $http_response_header automatically after file_get_contents().
    // (PHP 8.4+ adds http_get_last_response_headers() as the modern replacement.)
    ['status' => $status, 'headers' => $headers] = parseResponseHeaders($http_response_header ?? []);

    // match is like switch, but it compares strictly (===) and returns a value.
    match (true) {
        $status === 200 => null,
        $status === 404 => fail("User \"{$username}\" was not found on GitHub."),
        isRateLimited($status, $headers) => fail(rateLimitMessage($headers)),
        $status >= 500  => fail("GitHub is having problems right now (HTTP {$status}). Try again later."),
        default         => fail("Unexpected response from GitHub (HTTP {$status})."),
    };

    // JSON_THROW_ON_ERROR: broken JSON throws an exception instead of silently returning null.
    try {
        $events = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('GitHub returned data that is not valid JSON.');
    }

    if (!is_array($events)) {
        fail('GitHub returned data in an unexpected format.');
    }

    return $events;
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
 *
 * @param array<string, string> $headers
 */
function rateLimitMessage(array $headers): string
{
    $message = 'GitHub API rate limit reached (60 requests per hour without login).';

    // x-ratelimit-reset is a Unix timestamp (seconds since 1970) of when the limit resets.
    if (isset($headers['x-ratelimit-reset'])) {
        $minutes  = max(1, (int) ceil(((int) $headers['x-ratelimit-reset'] - time()) / 60));
        $message .= " Try again in about {$minutes} minute(s).";
    }

    return $message;
}

// ============================================================================
// 5. TURNING EVENTS INTO SENTENCES
// ============================================================================

/**
 * Describe one event as a human-readable sentence.
 * Every event has: type, repo.name, payload (the payload shape depends on the type).
 *
 * @param array<string, mixed> $event
 */
function describeEvent(array $event): string
{
    $type    = $event['type'] ?? 'UnknownEvent';
    $repo    = $event['repo']['name'] ?? 'an unknown repository';
    $payload = $event['payload'] ?? [];
    $action  = ucfirst($payload['action'] ?? 'updated'); // "opened" -> "Opened"

    // Payloads can change over time, so never assume a field exists: "??" gives a fallback.
    $number = $payload['number'] ?? $payload['issue']['number'] ?? $payload['pull_request']['number'] ?? null;
    $num    = $number !== null ? " #{$number}" : '';

    return match ($type) {
        'PushEvent'                     => describePush($payload, $repo),
        'IssuesEvent'                   => "{$action} issue{$num} in {$repo}",
        'IssueCommentEvent'             => describeIssueComment($payload, $repo),
        'PullRequestEvent'              => "{$action} pull request{$num} in {$repo}",
        'PullRequestReviewEvent'        => "Reviewed a pull request in {$repo}",
        'PullRequestReviewCommentEvent' => "Commented on a pull request review in {$repo}",
        'CommitCommentEvent'            => "Commented on a commit in {$repo}",
        'WatchEvent'                    => "Starred {$repo}",
        'ForkEvent'                     => "Forked {$repo}",
        'CreateEvent'                   => describeCreateOrDelete('Created', $payload, $repo),
        'DeleteEvent'                   => describeCreateOrDelete('Deleted', $payload, $repo),
        'ReleaseEvent'                  => "{$action} release " . ($payload['release']['tag_name'] ?? '') . " in {$repo}",
        'PublicEvent'                   => "Made {$repo} public",
        'MemberEvent'                   => "{$action} a collaborator in {$repo}",
        'GollumEvent'                   => "Updated the wiki of {$repo}",
        // Unknown/new types: "SponsorshipEvent" -> "Sponsorship in owner/repo".
        default                         => preg_replace('/Event$/', '', $type) . " in {$repo}",
    };
}

/**
 * GitHub no longer includes the commit list or count in PushEvent payloads,
 * only the branch ("ref"). If an older-style payload has "size", we still use it.
 *
 * @param array<string, mixed> $payload
 */
function describePush(array $payload, string $repo): string
{
    // "refs/heads/main" -> "main"
    $branch = preg_replace('#^refs/heads/#', '', $payload['ref'] ?? '');

    if (isset($payload['size'])) {
        $count = (int) $payload['size'];
        $noun  = $count === 1 ? 'commit' : 'commits';
        return "Pushed {$count} {$noun} to {$repo}";
    }

    return $branch !== ''
        ? "Pushed to branch {$branch} in {$repo}"
        : "Pushed to {$repo}";
}

/**
 * On GitHub, pull requests are a special kind of issue, so comments on PRs
 * also arrive as IssueCommentEvent. The "pull_request" key tells them apart.
 *
 * @param array<string, mixed> $payload
 */
function describeIssueComment(array $payload, string $repo): string
{
    $issue = $payload['issue'] ?? [];
    $kind  = isset($issue['pull_request']) ? 'pull request' : 'issue';
    $num   = isset($issue['number']) ? " #{$issue['number']}" : '';

    return "Commented on {$kind}{$num} in {$repo}";
}

/**
 * CreateEvent / DeleteEvent cover repositories, branches and tags.
 *
 * @param array<string, mixed> $payload
 */
function describeCreateOrDelete(string $verb, array $payload, string $repo): string
{
    $refType = $payload['ref_type'] ?? 'repository';
    $ref     = $payload['ref'] ?? null;

    return $refType === 'repository' || $ref === null
        ? "{$verb} repository {$repo}"
        : "{$verb} {$refType} {$ref} in {$repo}";
}

/**
 * Collapse back-to-back identical lines into one with a counter:
 *   ["Pushed to x", "Pushed to x", "Starred y"] -> ["Pushed to x (2 times)", "Starred y"]
 *
 * @param  string[] $lines
 * @return string[]
 */
function groupConsecutive(array $lines): array
{
    $grouped = [];   // each item: [line, count]

    foreach ($lines as $line) {
        $last = array_key_last($grouped);

        if ($last !== null && $grouped[$last][0] === $line) {
            $grouped[$last][1]++;
        } else {
            $grouped[] = [$line, 1];
        }
    }

    return array_map(
        fn (array $item) => $item[1] > 1 ? "{$item[0]} ({$item[1]} times)" : $item[0],
        $grouped
    );
}

// ============================================================================
// 6. MAIN PROGRAM
// ============================================================================

$username = $argv[1] ?? null;

if ($username === null || in_array($username, ['-h', '--help', 'help'], true)) {
    printUsage();
    exit($username === null ? 1 : 0);
}

if (!isValidUsername($username)) {
    fail("\"{$username}\" is not a valid GitHub username.");
}

$events = fetchEvents($username);

if ($events === []) {
    echo "No recent public activity for {$username}." . PHP_EOL;
    exit(0);
}

echo "Recent activity for {$username}:" . PHP_EOL;

foreach (groupConsecutive(array_map('describeEvent', $events)) as $line) {
    echo "- {$line}" . PHP_EOL;
}
