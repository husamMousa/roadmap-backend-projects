# Expense Tracker CLI

A command-line app to track your expenses, built with **plain PHP** (no frameworks, no external libraries). Expenses are stored in an `expenses.json` file in the directory you run the command from.

Project idea from [roadmap.sh](https://roadmap.sh/projects/expense-tracker).

## Requirements

- PHP 8.1 or higher (`php -v` to check), with the `mbstring` extension (enabled by default in most installs)

## Installation

```bash
git clone https://github.com/husamMousa/roadmap-backend-projects.git
cd roadmap-backend-projects/expense-tracker
```

Run commands with `php expense-tracker.php <command>`. The examples below write `expense-tracker` as shorthand for `php expense-tracker.php`.

## Usage

```bash
# Add an expense (category defaults to "general", date defaults to today)
expense-tracker add --description "Lunch" --amount 20
# Expense added successfully (ID: 1)
expense-tracker add --description "Taxi" --amount 12.50 --category transport --date 2026-08-06

# Update any of the fields
expense-tracker update --id 1 --amount 25 --category food

# Delete an expense
expense-tracker delete --id 1
# Expense deleted successfully

# List expenses (optionally filtered)
expense-tracker list
expense-tracker list --category food
expense-tracker list --month 8
# ID  Date        Description  Category  Amount
# 1   2026-08-06  Lunch        general   $20
# 2   2026-08-06  Dinner       general   $10

# Summary
expense-tracker summary
# Total expenses: $30
expense-tracker summary --month 8
# Total expenses for August: $20
expense-tracker summary --month 8 --category food

# Monthly budget (warns when you go over it)
expense-tracker budget --month 8 --amount 500
expense-tracker budget --month 8

# Export to CSV (optionally filtered)
expense-tracker export
expense-tracker export --file august.csv --month 8

# Help
expense-tracker help
```

- `--month` is always a month (1-12) of the **current year**.
- Options can be written as `--amount 20` or `--amount=20`.
- Wrap text that contains spaces in quotes.

## Data structure

```json
{
    "expenses": [
        {
            "id": 1,
            "date": "2026-08-06",
            "description": "Lunch",
            "amountCents": 2000,
            "category": "general"
        }
    ],
    "budgets": {
        "2026-08": 50000
    }
}
```

Amounts are stored as whole **cents** (`$20.00` → `2000`) to avoid floating-point rounding errors.

## Error handling

The app prints a clear message and exits with code `1` for: unknown commands or options, missing or repeated options, missing descriptions, negative, zero or non-numeric amounts (or more than 2 decimals), invalid dates and months, non-existent IDs, an `update` with nothing to change, and a corrupted `expenses.json`.
