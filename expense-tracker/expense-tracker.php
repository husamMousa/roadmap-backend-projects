<?php

/**
 * Expense Tracker CLI
 * -------------------
 * Track your expenses from the terminal. Data is stored in a JSON file.
 * Plain PHP only: no Composer, no frameworks, no external libraries.
 *
 * Usage:
 *   php expense-tracker.php add --description "Lunch" --amount 20 [--category food] [--date 2026-08-06]
 *   php expense-tracker.php update --id 1 [--description "..."] [--amount 25] [--category food] [--date ...]
 *   php expense-tracker.php delete --id 1
 *   php expense-tracker.php list [--month 8] [--category food]
 *   php expense-tracker.php summary [--month 8] [--category food]
 *   php expense-tracker.php budget --month 8 [--amount 500]
 *   php expense-tracker.php export [--file expenses.csv] [--month 8] [--category food]
 */

// ============================================================================
// 1. CONFIGURATION
// ============================================================================

// Same idea as the task tracker: the data file lives in the folder you run the command FROM.
define('DATA_FILE', getcwd() . DIRECTORY_SEPARATOR . 'expenses.json');

// Expenses added without --category get this one.
const DEFAULT_CATEGORY = 'general';

// One date format everywhere: 2026-08-06. It also sorts correctly as plain text.
const DATE_FORMAT = 'Y-m-d';

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
    Expense Tracker CLI

    Usage:
      expense-tracker add --description "<text>" --amount <number> [--category <name>] [--date YYYY-MM-DD]
      expense-tracker update --id <id> [--description "<text>"] [--amount <number>] [--category <name>] [--date YYYY-MM-DD]
      expense-tracker delete --id <id>
      expense-tracker list [--month <1-12>] [--category <name>]
      expense-tracker summary [--month <1-12>] [--category <name>]
      expense-tracker budget --month <1-12> [--amount <number>]
      expense-tracker export [--file <path.csv>] [--month <1-12>] [--category <name>]

    Notes:
      --month always means a month of the CURRENT year.
      Options can also be written as --amount=20.
      Wrap text that contains spaces in quotes.

    USAGE;
}

// ============================================================================
// 3. STORAGE
// ============================================================================

/**
 * The file holds TWO things, so the top level is an object, not a list:
 *   {
 *     "expenses": [ {...}, {...} ],          <- a list
 *     "budgets":  { "2026-08": 50000 }       <- a map: month => budget in cents
 *   }
 */
function emptyData(): array
{
    return ['expenses' => [], 'budgets' => []];
}

function loadData(): array
{
    // Unlike the task tracker, we don't create the file on read.
    // "list" on a fresh folder just shows nothing; the first "add" creates it.
    if (!file_exists(DATA_FILE)) {
        return emptyData();
    }

    $content = file_get_contents(DATA_FILE);
    if ($content === false) {
        fail('Could not read ' . DATA_FILE);
    }

    if (trim($content) === '') {
        return emptyData();
    }

    $data = json_decode($content, true);

    // Check the SHAPE, not only that it's valid JSON: "[1,2]" is valid JSON but useless to us.
    if (!is_array($data) || !is_array($data['expenses'] ?? null) || !is_array($data['budgets'] ?? [])) {
        fail('expenses.json is corrupted (' . json_last_error_msg() . '). Fix or delete the file.');
    }

    // ??= assigns only if the key is missing or null (PHP 7.4+).
    $data['budgets'] ??= [];

    return $data;
}

function saveData(array $data): void
{
    $json = json_encode(
        [
            // array_values: re-number keys after unset() so JSON stays a list [...].
            'expenses' => array_values($data['expenses']),
            // An EMPTY PHP array is ambiguous: json_encode writes it as [] (a list).
            // Budgets are a key => value map, so (object) forces {} even when empty.
            'budgets'  => (object) $data['budgets'],
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (file_put_contents(DATA_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        fail('Could not write to ' . DATA_FILE);
    }
}

// ============================================================================
// 4. PARSING COMMAND-LINE OPTIONS
// ============================================================================

/**
 * Turn ['--description', 'Lunch', '--amount=20'] into ['description' => 'Lunch', 'amount' => '20'].
 *
 * Why not PHP's built-in getopt()?
 *   getopt() STOPS at the first argument that isn't an option. In
 *   "expense-tracker.php add --amount 20" the first thing it sees is "add",
 *   so it stops right there and returns an empty array. Writing our own loop
 *   is short and lets us give clear error messages.
 *
 * $allowed = which options this command accepts. Anything else is a typo
 * (e.g. --ammount) and we'd rather say so than silently ignore it.
 */
function parseOptions(array $args, array $allowed): array
{
    $options = [];

    // A "for" (not foreach) loop, because we sometimes jump 2 steps: name AND value.
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if (!str_starts_with($arg, '--')) {
            fail("Unexpected argument \"{$arg}\". Options look like --name value (wrap text with spaces in quotes).");
        }

        $name = substr($arg, 2);   // "--amount" -> "amount"

        if (str_contains($name, '=')) {
            // Style 1: --amount=20. explode with limit 2 splits on the FIRST "=" only,
            // so --description="a=b" keeps "a=b" intact.
            [$name, $value] = explode('=', $name, 2);
        } else {
            // Style 2: --amount 20. The value is the NEXT argument.
            $value = $args[$i + 1] ?? null;

            // "--amount --category food": the user forgot the value.
            // A single dash is fine though: "--amount -5" reaches parseAmount, which explains the real problem.
            if ($value === null || str_starts_with($value, '--')) {
                fail("Option --{$name} needs a value.");
            }

            $i++;   // skip the value, we've consumed it
        }

        if (!in_array($name, $allowed, true)) {
            $list = $allowed ? '--' . implode(', --', $allowed) : 'none';
            fail("Unknown option --{$name}. Allowed here: {$list}");
        }

        if (array_key_exists($name, $options)) {
            fail("Option --{$name} was given more than once.");
        }

        $options[$name] = $value;
    }

    return $options;
}

// ============================================================================
// 5. VALIDATION (everything from $argv is a STRING, so we check and convert)
// ============================================================================

function parseId(?string $raw): int
{
    if ($raw === null) {
        fail('Please provide an expense ID with --id.');
    }

    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        fail("ID must be a positive whole number, got \"{$raw}\".");
    }

    return $id;
}

function parseDescription(?string $raw): string
{
    $description = trim($raw ?? '');
    if ($description === '') {
        fail('Please provide a description with --description.');
    }

    return $description;
}

/**
 * Money is stored as an INTEGER number of cents: $20.50 -> 2050.
 *
 * Why? Floats can't hold most decimal fractions exactly:
 *   0.1 + 0.2 == 0.3          -> false
 *   (int) (19.99 * 100)       -> 1998, not 1999!  (19.99 is really 19.98999999...)
 * Integers are exact, and adding cents can never drift. Banks do the same.
 *
 * So we never let the amount become a float: we read the digits from the TEXT.
 */
function parseAmount(?string $raw): int
{
    $raw = trim($raw ?? '');
    if ($raw === '') {
        fail('Please provide an amount with --amount, e.g. --amount 20 or --amount 4.50');
    }

    // Regex breakdown:  ^(\d{1,9})          whole part: 1 to 9 digits
    //                   (?:\.(\d{1,2}))?    optional: a dot + 1 or 2 digits
    //                   $                   nothing else allowed
    // No minus sign is allowed, so negative amounts fail here.
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $raw, $match)) {
        fail("Amount must be a positive number with at most 2 decimals, got \"{$raw}\".");
    }

    // "4.5" -> whole "4", fraction "5" -> padded to "50" -> 4 * 100 + 50 = 450 cents.
    // If there's no fraction, $match[2] doesn't exist, so ?? '' gives "00".
    $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');

    if ($cents === 0) {
        fail('Amount must be greater than zero.');
    }

    return $cents;
}

function parseMonth(?string $raw): int
{
    $month = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    if ($month === false) {
        fail("Month must be a number from 1 to 12, got \"{$raw}\".");
    }

    return $month;
}

function parseCategory(string $raw): string
{
    // Lowercase so "Food", "FOOD" and "food" are the same category when filtering.
    // mb_ = multibyte-safe version, works with Arabic and other non-English text.
    $category = mb_strtolower(trim($raw));
    if ($category === '') {
        fail('Category cannot be empty.');
    }

    return $category;
}

function parseDate(string $raw): string
{
    // The "!" resets the fields we didn't give (time) to zero instead of "now".
    $date = DateTime::createFromFormat('!' . DATE_FORMAT, $raw);

    // createFromFormat is forgiving: "2026-02-30" silently becomes March 2.
    // Formatting it back and comparing catches that: "2026-03-02" !== "2026-02-30".
    if ($date === false || $date->format(DATE_FORMAT) !== $raw) {
        fail("Date must be a real date in YYYY-MM-DD format, got \"{$raw}\".");
    }

    return $raw;
}

// ============================================================================
// 6. SMALL REUSABLE HELPERS
// ============================================================================

/**
 * 2050 -> "$20.50", 2000 -> "$20" (whole amounts look like the project's examples).
 * Dividing by 100 here is fine: it's only for DISPLAY, the stored value stays exact.
 */
function formatMoney(int $cents): string
{
    $decimals = $cents % 100 === 0 ? 0 : 2;
    return '$' . number_format($cents / 100, $decimals);
}

/**
 * Month number (of the current year) -> "2026-08".
 * Expense dates start with the same 7 characters, so filtering is a simple prefix check,
 * and budgets use it as their key.
 */
function monthKey(int $month): string
{
    return sprintf('%s-%02d', date('Y'), $month);   // %02d = pad to 2 digits: 8 -> "08"
}

/**
 * "2026-08" -> "August 2026"
 */
function monthLabel(string $monthKey): string
{
    return DateTime::createFromFormat('!Y-m', $monthKey)->format('F Y');
}

function nextId(array $expenses): int
{
    // Highest ID + 1, never count() + 1 (that reuses IDs after a delete).
    return empty($expenses) ? 1 : max(array_column($expenses, 'id')) + 1;
}

function findExpenseIndexOrFail(array $expenses, int $id): int
{
    foreach ($expenses as $index => $expense) {
        if ($expense['id'] === $id) {
            return $index;
        }
    }

    fail("Expense with ID {$id} not found.");
}

/**
 * Keep only the expenses that match the filters. null = "don't filter by this".
 * list, summary and export all share this, so filtering behaves the same everywhere.
 */
function filterExpenses(array $expenses, ?int $month, ?string $category): array
{
    $prefix = $month === null ? null : monthKey($month);

    return array_filter($expenses, function (array $expense) use ($prefix, $category): bool {
        if ($prefix !== null && !str_starts_with($expense['date'], $prefix)) {
            return false;
        }

        if ($category !== null && $expense['category'] !== $category) {
            return false;
        }

        return true;
    });
}

function sumAmounts(array $expenses): int
{
    // array_column pulls one field from every row: [2000, 1000] -> array_sum -> 3000
    return array_sum(array_column($expenses, 'amountCents'));
}

/**
 * Sort by date, then by ID for expenses on the same day.
 * <=> is the "spaceship" operator: returns -1, 0 or 1, exactly what usort wants.
 * ?: means "if the date comparison is 0 (same date), compare IDs instead".
 */
function sortExpenses(array $expenses): array
{
    usort($expenses, fn(array $a, array $b) => ($a['date'] <=> $b['date']) ?: ($a['id'] <=> $b['id']));
    return $expenses;
}

/**
 * Print a warning if this month's total is over its budget (if it has one).
 */
function warnIfOverBudget(array $data, string $monthKey): void
{
    $budget = $data['budgets'][$monthKey] ?? null;
    if ($budget === null) {
        return;
    }

    $spent = sumAmounts(array_filter(
        $data['expenses'],
        fn(array $expense) => str_starts_with($expense['date'], $monthKey)
    ));

    if ($spent > $budget) {
        echo 'Warning: You exceeded your budget for ' . monthLabel($monthKey)
            . ': spent ' . formatMoney($spent) . ' of ' . formatMoney($budget)
            . ' (over by ' . formatMoney($spent - $budget) . ').' . PHP_EOL;
    }
}

/**
 * Pad text to a width, counting CHARACTERS, not bytes.
 * printf("%-10s") counts bytes, and "غداء" is 4 characters but 8 bytes in UTF-8,
 * so Arabic descriptions would break the table's alignment.
 */
function padRight(string $text, int $width): string
{
    return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
}

/**
 * Print rows as an aligned table. Each column is as wide as its longest value.
 */
function printTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ([$headers, ...$rows] as $row) {           // ... unpacks $rows into this array
        foreach ($row as $column => $cell) {
            $widths[$column] = max($widths[$column] ?? 0, mb_strlen((string) $cell));
        }
    }

    foreach ([$headers, ...$rows] as $row) {
        $cells = [];
        foreach ($row as $column => $cell) {
            $cells[] = padRight((string) $cell, $widths[$column]);
        }
        echo rtrim(implode('  ', $cells)) . PHP_EOL;   // rtrim: no trailing spaces on the last column
    }
}

// ============================================================================
// 7. COMMANDS
// ============================================================================

function addExpense(array $args): void
{
    $options = parseOptions($args, ['description', 'amount', 'category', 'date']);

    // Validate EVERYTHING before touching the file, so bad input never half-saves.
    $description = parseDescription($options['description'] ?? null);
    $amount = parseAmount($options['amount'] ?? null);
    $category = parseCategory($options['category'] ?? DEFAULT_CATEGORY);
    $date = isset($options['date']) ? parseDate($options['date']) : date(DATE_FORMAT);

    $data = loadData();

    $expense = [
        'id'          => nextId($data['expenses']),
        'date'        => $date,
        'description' => $description,
        'amountCents' => $amount,
        'category'    => $category,
    ];

    $data['expenses'][] = $expense;
    saveData($data);

    echo "Expense added successfully (ID: {$expense['id']})" . PHP_EOL;
    warnIfOverBudget($data, substr($date, 0, 7));
}

function updateExpense(array $args): void
{
    $options = parseOptions($args, ['id', 'description', 'amount', 'category', 'date']);
    $id = parseId($options['id'] ?? null);

    // Only the fields the user actually passed get changed.
    // array_filter drops the nulls, leaving only what was given.
    $changes = array_filter([
        'description' => isset($options['description']) ? parseDescription($options['description']) : null,
        'amountCents' => isset($options['amount']) ? parseAmount($options['amount']) : null,
        'category'    => isset($options['category']) ? parseCategory($options['category']) : null,
        'date'        => isset($options['date']) ? parseDate($options['date']) : null,
    ], fn($value) => $value !== null);

    if (empty($changes)) {
        fail('Nothing to update. Pass at least one of --description, --amount, --category, --date.');
    }

    $data = loadData();
    $index = findExpenseIndexOrFail($data['expenses'], $id);

    // array_merge with text keys: later arrays overwrite earlier ones, and the key order stays the same.
    $data['expenses'][$index] = array_merge($data['expenses'][$index], $changes);
    saveData($data);

    echo "Expense updated successfully (ID: {$id})" . PHP_EOL;
    warnIfOverBudget($data, substr($data['expenses'][$index]['date'], 0, 7));
}

function deleteExpense(array $args): void
{
    $options = parseOptions($args, ['id']);
    $id = parseId($options['id'] ?? null);

    $data = loadData();
    $index = findExpenseIndexOrFail($data['expenses'], $id);

    unset($data['expenses'][$index]);   // leaves a gap in the keys; saveData's array_values fixes it
    saveData($data);

    echo 'Expense deleted successfully' . PHP_EOL;
}

function listExpenses(array $args): void
{
    $options = parseOptions($args, ['month', 'category']);
    $month = isset($options['month']) ? parseMonth($options['month']) : null;
    $category = isset($options['category']) ? parseCategory($options['category']) : null;

    $expenses = sortExpenses(filterExpenses(loadData()['expenses'], $month, $category));

    if (empty($expenses)) {
        echo 'No expenses found.' . PHP_EOL;
        return;
    }

    // array_map turns every expense into a display row (amount formatted as money).
    $rows = array_map(fn(array $expense) => [
        $expense['id'],
        $expense['date'],
        $expense['description'],
        $expense['category'],
        formatMoney($expense['amountCents']),
    ], $expenses);

    printTable(['ID', 'Date', 'Description', 'Category', 'Amount'], $rows);
}

function showSummary(array $args): void
{
    $options = parseOptions($args, ['month', 'category']);
    $month = isset($options['month']) ? parseMonth($options['month']) : null;
    $category = isset($options['category']) ? parseCategory($options['category']) : null;

    $data = loadData();
    $total = sumAmounts(filterExpenses($data['expenses'], $month, $category));

    // Build the label from the filters: "Total expenses for August (food): $20"
    $label = 'Total expenses';
    if ($month !== null) {
        // mktime(hour, minute, second, month, day) -> a timestamp; 'F' = full month name
        $label .= ' for ' . date('F', mktime(0, 0, 0, $month, 1));
    }
    if ($category !== null) {
        $label .= " ({$category})";
    }

    echo "{$label}: " . formatMoney($total) . PHP_EOL;

    // For a whole month (no category filter), also show how it compares to the budget.
    if ($month !== null && $category === null) {
        $key = monthKey($month);
        $budget = $data['budgets'][$key] ?? null;

        if ($budget !== null && $total <= $budget) {
            echo 'Budget: ' . formatMoney($budget) . ' (remaining: ' . formatMoney($budget - $total) . ')' . PHP_EOL;
        }
        warnIfOverBudget($data, $key);
    }
}

/**
 * budget --month 8 --amount 500   -> set the budget for August of this year
 * budget --month 8                -> show it
 */
function manageBudget(array $args): void
{
    $options = parseOptions($args, ['month', 'amount']);

    if (!isset($options['month'])) {
        fail('Please provide a month with --month (1-12).');
    }
    $key = monthKey(parseMonth($options['month']));

    $data = loadData();

    if (isset($options['amount'])) {
        $data['budgets'][$key] = parseAmount($options['amount']);
        saveData($data);

        echo 'Budget for ' . monthLabel($key) . ' set to ' . formatMoney($data['budgets'][$key]) . PHP_EOL;
        warnIfOverBudget($data, $key);   // you might already be over it
        return;
    }

    $budget = $data['budgets'][$key] ?? null;
    if ($budget === null) {
        echo 'No budget set for ' . monthLabel($key) . '.' . PHP_EOL;
        return;
    }

    $spent = sumAmounts(filterExpenses($data['expenses'], (int) substr($key, 5), null));
    echo 'Budget for ' . monthLabel($key) . ': ' . formatMoney($budget)
        . ' (spent: ' . formatMoney($spent) . ')' . PHP_EOL;
    warnIfOverBudget($data, $key);
}

function exportCsv(array $args): void
{
    $options = parseOptions($args, ['file', 'month', 'category']);
    $month = isset($options['month']) ? parseMonth($options['month']) : null;
    $category = isset($options['category']) ? parseCategory($options['category']) : null;
    $path = $options['file'] ?? 'expenses.csv';

    if (!str_ends_with(strtolower($path), '.csv')) {
        fail('Export file name must end with .csv');
    }

    $expenses = sortExpenses(filterExpenses(loadData()['expenses'], $month, $category));

    if (empty($expenses)) {
        echo 'No expenses to export.' . PHP_EOL;
        return;
    }

    // fopen gives a "handle" (like a cursor into the file). 'w' = write, overwrite if it exists.
    // The @ hides PHP's own warning; we show a clearer error ourselves.
    $handle = @fopen($path, 'w');
    if ($handle === false) {
        fail("Could not open \"{$path}\" for writing.");
    }

    // BOM (Byte Order Mark): 3 invisible bytes that tell Excel "this file is UTF-8".
    // Without it, Excel shows Arabic text as gibberish.
    fwrite($handle, "\xEF\xBB\xBF");

    // fputcsv handles the CSV rules for us: commas and quotes inside a description
    // get wrapped and escaped correctly. Last argument '' = standard (RFC 4180) escaping.
    fputcsv($handle, ['ID', 'Date', 'Description', 'Category', 'Amount'], ',', '"', '');
    foreach ($expenses as $expense) {
        fputcsv($handle, [
            $expense['id'],
            $expense['date'],
            $expense['description'],
            $expense['category'],
            // Plain number for spreadsheets: 2 decimals, "." separator, no thousands comma.
            number_format($expense['amountCents'] / 100, 2, '.', ''),
        ], ',', '"', '');
    }

    fclose($handle);

    echo 'Exported ' . count($expenses) . " expense(s) to {$path}" . PHP_EOL;
}

// ============================================================================
// 8. ENTRY POINT (the "router")
// ============================================================================

if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.');
}

$command = $argv[1] ?? null;
$args = array_slice($argv, 2);

if ($command === null || in_array($command, ['help', '--help', '-h'], true)) {
    printUsage();
    exit(0);
}

switch ($command) {
    case 'add':
        addExpense($args);
        break;

    case 'update':
        updateExpense($args);
        break;

    case 'delete':
        deleteExpense($args);
        break;

    case 'list':
        listExpenses($args);
        break;

    case 'summary':
        showSummary($args);
        break;

    case 'budget':
        manageBudget($args);
        break;

    case 'export':
        exportCsv($args);
        break;

    default:
        fwrite(STDERR, "Error: Unknown command \"{$command}\"." . PHP_EOL . PHP_EOL);
        printUsage();
        exit(1);
}

exit(0);
