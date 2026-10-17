<?php

/**
 * Task Tracker CLI
 * ----------------
 * A simple command-line to-do list that stores tasks in a JSON file.
 * Plain PHP only: no Composer, no frameworks, no external libraries.
 *
 * Usage:
 *   php task-cli.php add "Buy groceries"
 *   php task-cli.php update 1 "Buy groceries and cook dinner"
 *   php task-cli.php delete 1
 *   php task-cli.php mark-in-progress 1
 *   php task-cli.php mark-done 1
 *   php task-cli.php list [todo|in-progress|done]
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

// The requirement says: store the JSON file "in the current directory".
// getcwd() = the folder you ran the command FROM (not where this script lives).
// DIRECTORY_SEPARATOR = "\" on Windows, "/" on Linux/Mac, so the path works everywhere.
define('TASKS_FILE', getcwd() . DIRECTORY_SEPARATOR . 'tasks.json');

// The only statuses a task can have. Keeping them in one place means
// we validate against a single source of truth instead of repeating strings.
const VALID_STATUSES = ['todo', 'in-progress', 'done'];

// ============================================================================
// 2. OUTPUT HELPERS
// ============================================================================

/**
 * Print an error and stop the program.
 *
 * - STDERR: errors go to the "error stream", normal output goes to STDOUT.
 *   Terminals show both, but scripts can separate them (e.g. 2> errors.log).
 * - exit(1): an "exit code". 0 = success, anything else = failure.
 *   Other programs (CI pipelines, shell scripts) read this to know if you failed.
 * - Return type "never": tells PHP (8.1+) this function never returns normally.
 */
function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    exit(1);
}

/**
 * Print how to use the program.
 */
function printUsage(): void
{
    echo <<<USAGE
    Task Tracker CLI

    Usage:
      task-cli add "<description>"           Add a new task
      task-cli update <id> "<description>"   Update a task's description
      task-cli delete <id>                   Delete a task
      task-cli mark-in-progress <id>         Mark a task as in progress
      task-cli mark-done <id>                Mark a task as done
      task-cli list                          List all tasks
      task-cli list <status>                 List tasks by status (todo | in-progress | done)

    USAGE;
}

// ============================================================================
// 3. STORAGE (our tiny "database layer")
// ============================================================================

/**
 * Load all tasks from the JSON file into a PHP array.
 * Creates the file (with an empty list) if it doesn't exist yet.
 */
function loadTasks(): array
{
    // Requirement: "The JSON file should be created if it does not exist."
    if (!file_exists(TASKS_FILE)) {
        saveTasks([]);
        return [];
    }

    // file_get_contents returns the whole file as ONE string,
    // or false if it couldn't read it (permissions, file locked, etc.).
    $content = file_get_contents(TASKS_FILE);
    if ($content === false) {
        fail('Could not read ' . TASKS_FILE);
    }

    // An empty file is not an error: treat it as "no tasks yet".
    if (trim($content) === '') {
        return [];
    }

    // json_decode turns the JSON string into PHP data.
    // The "true" argument = give me associative arrays, not stdClass objects.
    $tasks = json_decode($content, true);

    // If someone edited the file by hand and broke it, json_decode returns null.
    // We also make sure the top level is a list (array), not a number or a string.
    if (!is_array($tasks)) {
        fail('tasks.json is corrupted (' . json_last_error_msg() . '). Fix or delete the file.');
    }

    return $tasks;
}

/**
 * Save the WHOLE tasks array back to the JSON file.
 * (A JSON file can't update one row like MySQL can: we rewrite everything.)
 */
function saveTasks(array $tasks): void
{
    // array_values re-numbers the keys 0, 1, 2...
    // Without it, a list with gaps (e.g. after unset) becomes a JSON OBJECT {"0":..,"2":..}
    // instead of a JSON ARRAY [..]. See the delete command for the full story.
    $json = json_encode(
        array_values($tasks),
        JSON_PRETTY_PRINT          // readable, indented file
        | JSON_UNESCAPED_UNICODE   // keep Arabic as Arabic, not \u0627\u0634...
        | JSON_UNESCAPED_SLASHES   // keep "/" as "/", not "\/"
    );

    // LOCK_EX = lock the file while writing, so two commands running
    // at the same moment can't write half-and-half garbage into it.
    if (file_put_contents(TASKS_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        fail('Could not write to ' . TASKS_FILE);
    }
}

// ============================================================================
// 4. SMALL REUSABLE HELPERS
// ============================================================================

/**
 * Current date-time in ISO 8601 format, e.g. 2026-10-17T14:30:00+03:00.
 * The timezone comes from php.ini (date.timezone). If it's not set, PHP uses UTC.
 */
function now(): string
{
    return date('c');
}

/**
 * Generate the next unique ID = highest existing ID + 1.
 *
 * Why NOT count($tasks) + 1?
 *   IDs 1,2,3 -> delete 2 -> count = 2 -> new ID = 3 -> DUPLICATE!
 * Same idea as AUTO_INCREMENT in MySQL: an ID is never reused.
 * (Limitation: if you delete the LAST task, its ID can be reused. A real DB
 *  stores a separate counter to avoid that. Good enough for this project.)
 */
function nextId(array $tasks): int
{
    if (empty($tasks)) {
        return 1;
    }

    // array_column pulls one "column" out of every row: [1, 3, 7]
    return max(array_column($tasks, 'id')) + 1;
}

/**
 * Validate and convert the ID argument.
 * Everything in $argv is a STRING, so "1" must become the integer 1.
 */
function parseId(?string $raw): int
{
    if ($raw === null || trim($raw) === '') {
        fail('Please provide a task ID.');
    }

    // FILTER_VALIDATE_INT returns the int, or false if it's not a valid integer.
    // min_range = 1 rejects 0 and negative numbers.
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false) {
        fail("Task ID must be a positive number, got \"{$raw}\".");
    }

    return $id;
}

/**
 * Validate the description argument.
 */
function parseDescription(?string $raw): string
{
    $description = trim($raw ?? '');

    if ($description === '') {
        fail('Please provide a task description.');
    }

    return $description;
}

/**
 * Find the POSITION (array index) of a task by its ID.
 * Returns null if not found, so the caller decides what to do.
 *
 * Note: index !== id. After deletions, task id 5 might sit at index 2.
 */
function findTaskIndex(array $tasks, int $id): ?int
{
    foreach ($tasks as $index => $task) {
        if ($task['id'] === $id) {
            return $index;
        }
    }

    return null;
}

/**
 * Same as findTaskIndex, but stops the program if the task doesn't exist.
 * update, delete and mark-* all need this, so we write it once.
 */
function findTaskIndexOrFail(array $tasks, int $id): int
{
    $index = findTaskIndex($tasks, $id);

    if ($index === null) {
        fail("Task with ID {$id} not found.");
    }

    return $index;
}

/**
 * Make sure the user didn't pass more arguments than the command expects.
 * Most common cause: forgetting the quotes -> add Buy groceries
 * becomes TWO arguments: "Buy" and "groceries".
 */
function ensureMaxArgs(array $args, int $max, string $hint): void
{
    if (count($args) > $max) {
        fail("Too many arguments. {$hint}");
    }
}

// ============================================================================
// 5. COMMANDS (one function per command, like controller methods)
// ============================================================================

function addTask(array $args): void
{
    ensureMaxArgs($args, 1, 'Wrap the description in quotes: add "Buy groceries"');
    $description = parseDescription($args[0] ?? null);

    $tasks = loadTasks();
    $timestamp = now();

    $task = [
        'id'          => nextId($tasks),
        'description' => $description,
        'status'      => 'todo',        // every new task starts as todo
        'createdAt'   => $timestamp,
        'updatedAt'   => $timestamp,    // same moment as createdAt at birth
    ];

    $tasks[] = $task;   // [] = push to the end of the array
    saveTasks($tasks);

    echo "Task added successfully (ID: {$task['id']})" . PHP_EOL;
}

function updateTask(array $args): void
{
    ensureMaxArgs($args, 2, 'Wrap the description in quotes: update 1 "New text"');
    $id = parseId($args[0] ?? null);
    $description = parseDescription($args[1] ?? null);

    $tasks = loadTasks();
    $index = findTaskIndexOrFail($tasks, $id);

    // We modify the array element directly through its index.
    $tasks[$index]['description'] = $description;
    $tasks[$index]['updatedAt'] = now();   // requirement: update this on every change

    saveTasks($tasks);

    echo "Task {$id} updated successfully." . PHP_EOL;
}

function deleteTask(array $args): void
{
    ensureMaxArgs($args, 1, 'Usage: delete <id>');
    $id = parseId($args[0] ?? null);

    $tasks = loadTasks();
    $index = findTaskIndexOrFail($tasks, $id);

    // unset removes the element but LEAVES A GAP in the keys:
    //   before: [0 => t1, 1 => t2, 2 => t3]
    //   after:  [0 => t1,          2 => t3]
    // json_encode would then output an object {"0":...,"2":...}.
    // saveTasks() fixes this with array_values(), which re-numbers to [0, 1].
    unset($tasks[$index]);

    saveTasks($tasks);

    echo "Task {$id} deleted successfully." . PHP_EOL;
}

/**
 * Shared logic for mark-in-progress and mark-done.
 * Two commands, one function: they only differ in the target status.
 */
function markTask(array $args, string $status): void
{
    ensureMaxArgs($args, 1, "Usage: mark-{$status} <id>");
    $id = parseId($args[0] ?? null);

    $tasks = loadTasks();
    $index = findTaskIndexOrFail($tasks, $id);

    // Edge case: already in that status. Not an error, just tell the user
    // and DON'T touch updatedAt, because nothing actually changed.
    if ($tasks[$index]['status'] === $status) {
        echo "Task {$id} is already marked as {$status}." . PHP_EOL;
        return;
    }

    $tasks[$index]['status'] = $status;
    $tasks[$index]['updatedAt'] = now();

    saveTasks($tasks);

    echo "Task {$id} marked as {$status}." . PHP_EOL;
}

function listTasks(array $args): void
{
    ensureMaxArgs($args, 1, 'Usage: list [todo|in-progress|done]');
    $status = $args[0] ?? null;   // null = no filter = show all

    if ($status !== null && !in_array($status, VALID_STATUSES, true)) {
        fail("Invalid status \"{$status}\". Use one of: " . implode(', ', VALID_STATUSES));
    }

    $tasks = loadTasks();

    // array_filter keeps only elements where the callback returns true.
    // fn(...) => ... is an "arrow function" (like JS arrow functions),
    // and it can read $status from the outer scope automatically.
    if ($status !== null) {
        $tasks = array_filter($tasks, fn(array $task) => $task['status'] === $status);
    }

    if (empty($tasks)) {
        echo ($status === null ? 'No tasks found.' : "No tasks with status \"{$status}\".") . PHP_EOL;
        return;
    }

    // printf with padding builds an aligned table:
    // %-4s = left-aligned, at least 4 characters wide.
    printf("%-4s  %-12s  %-25s  %s" . PHP_EOL, 'ID', 'STATUS', 'UPDATED', 'DESCRIPTION');
    echo str_repeat('-', 70) . PHP_EOL;

    foreach ($tasks as $task) {
        printf(
            "%-4s  %-12s  %-25s  %s" . PHP_EOL,
            $task['id'],
            $task['status'],
            $task['updatedAt'],
            $task['description']
        );
    }
}

// ============================================================================
// 6. ENTRY POINT (the "router")
// ============================================================================

// Safety check: this script is meant for the terminal, not a browser.
// PHP_SAPI tells you HOW PHP is running: "cli" in the terminal, "apache2handler" etc. on a server.
if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.');
}

// $argv[0] = script name, $argv[1] = command, the rest = command arguments.
$command = $argv[1] ?? null;
$args = array_slice($argv, 2);   // everything AFTER the command

// No command at all, or asking for help -> show usage (and exit 0: it's not an error).
if ($command === null || in_array($command, ['help', '--help', '-h'], true)) {
    printUsage();
    exit(0);
}

// Route the command to its function, like Express: app.post('/add', addTask)
switch ($command) {
    case 'add':
        addTask($args);
        break;

    case 'update':
        updateTask($args);
        break;

    case 'delete':
        deleteTask($args);
        break;

    case 'mark-in-progress':
        markTask($args, 'in-progress');
        break;

    case 'mark-done':
        markTask($args, 'done');
        break;

    case 'list':
        listTasks($args);
        break;

    default:
        fwrite(STDERR, "Error: Unknown command \"{$command}\"." . PHP_EOL . PHP_EOL);
        printUsage();
        exit(1);
}

exit(0);