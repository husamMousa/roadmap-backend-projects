# Task Tracker CLI

A simple command-line app to track your tasks, built with **plain PHP** (no frameworks, no external libraries). Tasks are stored in a `tasks.json` file in the directory you run the command from.

Project idea from [roadmap.sh](https://roadmap.sh/projects/task-tracker).

## Requirements

- PHP 8.1 or higher (`php -v` to check)

## Installation

```bash
git clone <your-repo-url>
cd task-tracker
```

On Windows, `task-cli.bat` lets you run `task-cli` directly from the project folder. Otherwise use `php task-cli.php`.

## Usage

```bash
# Add a task
task-cli add "Buy groceries"
# Output: Task added successfully (ID: 1)

# Update a task
task-cli update 1 "Buy groceries and cook dinner"

# Delete a task
task-cli delete 1

# Change status
task-cli mark-in-progress 1
task-cli mark-done 1

# List tasks
task-cli list
task-cli list todo
task-cli list in-progress
task-cli list done

# Help
task-cli help
```

> Wrap descriptions that contain spaces in quotes.

## Task structure

```json
{
    "id": 1,
    "description": "Buy groceries",
    "status": "todo",
    "createdAt": "2026-10-17T14:30:00+03:00",
    "updatedAt": "2026-10-17T14:30:00+03:00"
}
```

`status` is one of `todo`, `in-progress`, `done`.

## Error handling

The app prints a clear message and exits with code `1` for: missing or unknown commands, missing descriptions, non-numeric or non-existent IDs, invalid status filters, unquoted multi-word descriptions, and a corrupted `tasks.json`.