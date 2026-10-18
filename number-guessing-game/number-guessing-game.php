<?php

/**
 * Number Guessing Game
 * --------------------
 * The computer picks a number between 1 and 100 and you try to guess it
 * within a limited number of chances. Plain PHP only: no Composer, no frameworks.
 *
 * Extras: multiple rounds, a timer, hints, and high scores per difficulty
 * (saved in a JSON file).
 *
 * Usage:
 *   php number-guessing-game.php               Play the game
 *   php number-guessing-game.php scores        Show the high scores
 *   php number-guessing-game.php reset-scores  Delete the high scores
 *   php number-guessing-game.php help          Show this help
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

const MIN_NUMBER = 1;
const MAX_NUMBER = 100;

// Menu number => difficulty. The key is what the user types at the menu.
const DIFFICULTIES = [
    1 => ['name' => 'Easy',   'chances' => 10],
    2 => ['name' => 'Medium', 'chances' => 5],
    3 => ['name' => 'Hard',   'chances' => 3],
];

// How many hints you may ask for in one round.
const MAX_HINTS = 3;

// Same idea as the other projects: the data file lives in the folder you run the command FROM.
define('SCORES_FILE', getcwd() . DIRECTORY_SEPARATOR . 'highscores.json');

// ============================================================================
// 2. INPUT / OUTPUT HELPERS
// ============================================================================

/**
 * Print an error to STDERR and stop with exit code 1 (= failure).
 */
function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    exit(1);
}

function line(string $text = ''): void
{
    echo $text . PHP_EOL;
}

/**
 * Show a prompt and read one line from the keyboard.
 *
 * Why fgets(STDIN) and not readline()?
 *   readline() needs a PHP extension that is often missing (especially on Windows).
 *   fgets(STDIN) always works, and it also works when input is piped in.
 *
 * fgets() returns false at "end of input" (Ctrl+D on Linux/macOS, Ctrl+Z then Enter
 * on Windows, or the end of a piped file). There is nothing more to read, so we quit
 * instead of looping forever on an empty answer.
 */
function ask(string $prompt): string
{
    echo $prompt;
    $input = fgets(STDIN);

    if ($input === false) {
        line();
        line('Goodbye!');
        exit(0);
    }

    return trim($input);
}

function printUsage(): void
{
    echo <<<USAGE
    Number Guessing Game

    Usage:
      php number-guessing-game.php               Play the game
      php number-guessing-game.php scores        Show the high scores
      php number-guessing-game.php reset-scores  Delete the high scores
      php number-guessing-game.php help          Show this help

    While playing, type "hint" instead of a guess to get a clue,
    or "quit" to leave the game.

    USAGE;
}

/**
 * 4.25 seconds -> "4.3s", 75.5 seconds -> "1m 15.5s".
 */
function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.1fs', $seconds);
    }

    $minutes = intdiv((int) $seconds, 60);
    return sprintf('%dm %.1fs', $minutes, $seconds - $minutes * 60);
}

function plural(int $count, string $word): string
{
    return $count . ' ' . $word . ($count === 1 ? '' : 's');
}

// ============================================================================
// 3. HIGH SCORES STORAGE
// ============================================================================

/**
 * One best result per difficulty, keyed by the difficulty name:
 *   {
 *     "Medium": { "attempts": 4, "seconds": 12.3, "date": "2026-10-18" }
 *   }
 */
function loadScores(): array
{
    if (!file_exists(SCORES_FILE)) {
        return [];
    }

    $content = file_get_contents(SCORES_FILE);
    if ($content === false) {
        fail('Could not read ' . SCORES_FILE);
    }

    if (trim($content) === '') {
        return [];
    }

    $scores = json_decode($content, true);
    if (!is_array($scores)) {
        fail('highscores.json is corrupted (' . json_last_error_msg() . '). Fix or delete the file.');
    }

    return $scores;
}

function saveScores(array $scores): void
{
    // (object): an EMPTY PHP array would be written as [] (a list); we want a {} map.
    $json = json_encode((object) $scores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents(SCORES_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        fail('Could not write to ' . SCORES_FILE);
    }
}

/**
 * Fewer attempts wins. On a tie, the faster time wins.
 */
function isNewHighScore(?array $best, int $attempts, float $seconds): bool
{
    if ($best === null) {
        return true;
    }

    // The spaceship operator compares arrays element by element:
    // first attempts, and only if those are equal, seconds.
    // The parentheses matter: "<" binds tighter than "<=>".
    return ([$attempts, $seconds] <=> [$best['attempts'], $best['seconds']]) < 0;
}

function printScores(array $scores): void
{
    line('High scores (fewest attempts):');

    foreach (DIFFICULTIES as $difficulty) {
        $name = $difficulty['name'];
        $best = $scores[$name] ?? null;

        // str_pad lines the names up in a column: "Easy  ", "Medium", "Hard  ".
        $label = str_pad($name, 6);

        if ($best === null) {
            line("  {$label}  -");
        } else {
            line(sprintf(
                '  %s  %s in %s (%s)',
                $label,
                plural($best['attempts'], 'attempt'),
                formatDuration($best['seconds']),
                $best['date']
            ));
        }
    }
}

// ============================================================================
// 4. HINTS
// ============================================================================

/**
 * Build the clues for this round up front, from vague to precise.
 * Each "hint" request reveals the next one.
 */
function buildHints(int $secret): array
{
    $hints = [];

    $hints[] = 'The number is ' . ($secret % 2 === 0 ? 'even' : 'odd') . '.';

    // Groups of ten: 1-10, 11-20, ..., 91-100.
    $start = intdiv($secret - 1, 10) * 10 + 1;
    $hints[] = "The number is between {$start} and " . ($start + 9) . '.';

    $digitSum = array_sum(str_split((string) $secret));
    $hints[] = "The digits of the number add up to {$digitSum}.";

    return $hints;
}

// ============================================================================
// 5. THE GAME
// ============================================================================

function printWelcome(): void
{
    line('Welcome to the Number Guessing Game!');
    line('I\'m thinking of a number between ' . MIN_NUMBER . ' and ' . MAX_NUMBER . '.');
    line('Guess it before you run out of chances. After each wrong guess I\'ll tell you');
    line('whether the number is greater or less than your guess.');
    line('Type "hint" for a clue (' . MAX_HINTS . ' per round) or "quit" to leave.');
}

/**
 * Keep asking until the user picks a valid menu number. Returns the difficulty.
 */
function chooseDifficulty(): array
{
    line('Please select the difficulty level:');
    foreach (DIFFICULTIES as $choice => $difficulty) {
        line("{$choice}. {$difficulty['name']} ({$difficulty['chances']} chances)");
    }
    line();

    while (true) {
        $input = ask('Enter your choice: ');

        if (strtolower($input) === 'quit') {
            line('Goodbye!');
            exit(0);
        }

        // ctype_digit: only 0-9 characters, so "2.5", "-1" and " " are rejected.
        if (ctype_digit($input) && isset(DIFFICULTIES[(int) $input])) {
            return DIFFICULTIES[(int) $input];
        }

        line('Please enter 1, 2 or 3.');
    }
}

/**
 * Play one round. Returns nothing: the result is printed and high scores are saved here.
 */
function playRound(): void
{
    // random_int() uses a cryptographically secure generator. Overkill for a game,
    // but it's the recommended function, and rand() has no real advantage.
    $secret = random_int(MIN_NUMBER, MAX_NUMBER);

    $difficulty = chooseDifficulty();
    $chances = $difficulty['chances'];

    line();
    line("Great! You have selected the {$difficulty['name']} difficulty level.");
    line('You have ' . plural($chances, 'chance') . ' to guess the correct number.');
    line('Let\'s start the game!');
    line();

    $hints = buildHints($secret);
    $hintsUsed = 0;

    // What the player knows so far: the number is somewhere in $low..$high.
    $low = MIN_NUMBER;
    $high = MAX_NUMBER;

    // hrtime(true) is a high-resolution clock in nanoseconds. Unlike time() or
    // microtime() it never jumps when the system clock changes, so it's the right
    // tool for measuring how long something took.
    $startedAt = hrtime(true);
    $attempts = 0;

    while ($attempts < $chances) {
        $input = strtolower(ask('Enter your guess: '));

        if ($input === 'quit') {
            line("The number was {$secret}. Goodbye!");
            exit(0);
        }

        if ($input === 'hint') {
            if ($hintsUsed === MAX_HINTS) {
                line("No hints left this round. So far you know it's between {$low} and {$high}.");
            } else {
                line('Hint: ' . $hints[$hintsUsed]);
                $hintsUsed++;
            }
            continue; // Asking for a hint doesn't cost a chance.
        }

        // Invalid input doesn't cost a chance either: it's a typo, not a guess.
        if (!preg_match('/^\d+$/', $input) || (int) $input < MIN_NUMBER || (int) $input > MAX_NUMBER) {
            line('Please enter a whole number between ' . MIN_NUMBER . ' and ' . MAX_NUMBER . '.');
            continue;
        }

        $guess = (int) $input;
        $attempts++;

        if ($guess === $secret) {
            $seconds = (hrtime(true) - $startedAt) / 1e9;
            line('Congratulations! You guessed the correct number in ' . plural($attempts, 'attempt') . '.');
            line('Time taken: ' . formatDuration($seconds));
            recordScore($difficulty['name'], $attempts, $seconds);
            return;
        }

        // Narrow the known range. Only move a bound if the guess actually narrows it,
        // so a "wasted" guess outside the range doesn't widen it again.
        if ($guess < $secret) {
            line("Incorrect! The number is greater than {$guess}.");
            $low = max($low, $guess + 1);
        } else {
            line("Incorrect! The number is less than {$guess}.");
            $high = min($high, $guess - 1);
        }

        $left = $chances - $attempts;
        if ($left > 0) {
            line(plural($left, 'chance') . ' left.');
        }
    }

    line();
    line("Game over! You ran out of chances. The number was {$secret}.");
}

function recordScore(string $difficultyName, int $attempts, float $seconds): void
{
    $scores = loadScores();
    $best = $scores[$difficultyName] ?? null;

    if (!isNewHighScore($best, $attempts, $seconds)) {
        line(sprintf(
            'Your best on %s is %s in %s.',
            $difficultyName,
            plural($best['attempts'], 'attempt'),
            formatDuration($best['seconds'])
        ));
        return;
    }

    $scores[$difficultyName] = [
        'attempts' => $attempts,
        'seconds'  => round($seconds, 2),
        'date'     => date('Y-m-d'),
    ];
    saveScores($scores);

    line("New high score for {$difficultyName}!");
}

function askPlayAgain(): bool
{
    while (true) {
        $answer = strtolower(ask('Do you want to play again? (y/n): '));

        if (in_array($answer, ['y', 'yes'], true)) {
            return true;
        }
        if (in_array($answer, ['n', 'no', 'quit'], true)) {
            return false;
        }

        line('Please answer y or n.');
    }
}

function play(): void
{
    printWelcome();

    $scores = loadScores();
    if ($scores !== []) {
        line();
        printScores($scores);
    }

    do {
        line();
        playRound();
        line();
    } while (askPlayAgain());

    line();
    printScores(loadScores());
    line('Thanks for playing!');
}

// ============================================================================
// 6. ENTRY POINT
// ============================================================================

// $argv[0] is the script name; anything after it is a command.
$command = $argv[1] ?? 'play';

if (count($argv) > 2) {
    fail('Too many arguments. Run "php number-guessing-game.php help" for usage.');
}

switch ($command) {
    case 'play':
        play();
        break;

    case 'scores':
        printScores(loadScores());
        break;

    case 'reset-scores':
        if (file_exists(SCORES_FILE) && !unlink(SCORES_FILE)) {
            fail('Could not delete ' . SCORES_FILE);
        }
        line('High scores reset.');
        break;

    case 'help':
    case '--help':
    case '-h':
        printUsage();
        break;

    default:
        fail("Unknown command \"{$command}\". Run \"php number-guessing-game.php help\" for usage.");
}
