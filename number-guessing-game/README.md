# Number Guessing Game

A command-line number guessing game built with **plain PHP** (no frameworks, no external libraries). The computer picks a number between 1 and 100 and you have a limited number of chances to guess it.

Project idea from [roadmap.sh](https://roadmap.sh/projects/number-guessing-game).

## Features

- Three difficulty levels: Easy (10 chances), Medium (5 chances), Hard (3 chances)
- "Greater" / "less" feedback after every wrong guess
- Play as many rounds as you like
- A timer for each round
- Up to 3 hints per round (type `hint`)
- High scores per difficulty (fewest attempts, ties broken by time), saved in `highscores.json` in the directory you run the game from

## Requirements

- PHP 8.1 or higher (`php -v` to check)

## Installation

```bash
git clone https://github.com/husamMousa/roadmap-backend-projects.git
cd roadmap-backend-projects/number-guessing-game
```

## Usage

```bash
# Play
php number-guessing-game.php

# Show the high scores
php number-guessing-game.php scores

# Delete the high scores
php number-guessing-game.php reset-scores

# Help
php number-guessing-game.php help
```

While playing:

- Type a whole number from 1 to 100 to guess.
- Type `hint` for a clue. Hints and invalid input don't use up a chance.
- Type `quit` to leave at any time.

## Sample game

```
Welcome to the Number Guessing Game!
I'm thinking of a number between 1 and 100.
Guess it before you run out of chances. After each wrong guess I'll tell you
whether the number is greater or less than your guess.
Type "hint" for a clue (3 per round) or "quit" to leave.

Please select the difficulty level:
1. Easy (10 chances)
2. Medium (5 chances)
3. Hard (3 chances)

Enter your choice: 2

Great! You have selected the Medium difficulty level.
You have 5 chances to guess the correct number.
Let's start the game!

Enter your guess: 50
Incorrect! The number is less than 50.
4 chances left.
Enter your guess: 25
Incorrect! The number is greater than 25.
3 chances left.
Enter your guess: hint
Hint: The number is even.
Enter your guess: 36
Incorrect! The number is less than 36.
2 chances left.
Enter your guess: 30
Congratulations! You guessed the correct number in 4 attempts.
Time taken: 14.2s
New high score for Medium!

Do you want to play again? (y/n): n

High scores (fewest attempts):
  Easy    -
  Medium  4 attempts in 14.2s (2026-10-18)
  Hard    -
Thanks for playing!
```

## Hints

Each `hint` reveals the next clue, from vague to precise:

1. Whether the number is odd or even
2. Which group of ten it is in (e.g. between 21 and 30)
3. The sum of its digits

## High scores file

```json
{
    "Medium": {
        "attempts": 4,
        "seconds": 14.2,
        "date": "2026-10-18"
    }
}
```
