<?php
/**
 * Ξερή (Xeri) game rules and scoring helpers.
 */

declare(strict_types=1);

class XeriGame
{
    /** @return string[] */
    public static function createDeck(int $numDecks = 1): array
    {
        $suits = ['S', 'H', 'D', 'C'];
        $figures = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
        $deck = [];
        for ($d = 0; $d < $numDecks; $d++) {
            foreach ($suits as $suit) {
                foreach ($figures as $figure) {
                    $deck[] = $figure . $suit;
                }
            }
        }
        return $deck;
    }

    /** @param string[] $deck */
    public static function shuffleDeck(array &$deck): void
    {
        shuffle($deck);
    }

    public static function getFigure(string $card): string
    {
        return substr($card, 0, -1);
    }

    public static function getSuit(string $card): string
    {
        return substr($card, -1);
    }

    public static function isValidCard(string $card): bool
    {
        if (strlen($card) < 2 || strlen($card) > 3) {
            return false;
        }
        $figures = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
        $suits = ['S', 'H', 'D', 'C'];
        $fig = self::getFigure($card);
        $suit = self::getSuit($card);
        return in_array($fig, $figures, true) && in_array($suit, $suits, true);
    }

    public static function isJack(string $card): bool
    {
        return self::getFigure($card) === 'J';
    }

    public static function cardsMatch(string $card1, string $card2): bool
    {
        return self::getFigure($card1) === self::getFigure($card2);
    }

    public static function canCollectWithMatch(string $playerCard, string $topCard): bool
    {
        return self::cardsMatch($playerCard, $topCard);
    }

    public static function canCollectWithJack(string $playerCard): bool
    {
        return self::isJack($playerCard);
    }

    public static function isXeri(int $tableCountBeforeCollect): bool
    {
        return $tableCountBeforeCollect === 1;
    }

    public static function isXeriWithJack(string $playerCard, int $tableCountBeforeCollect): bool
    {
        return self::isXeri($tableCountBeforeCollect) && self::isJack($playerCard);
    }

    public static function isFaceOrTen(string $card): bool
    {
        $fig = self::getFigure($card);
        if ($fig === 'K' || $fig === 'Q' || $fig === 'J') {
            return true;
        }
        return $fig === '10' && $card !== '10D';
    }

    /**
     * @param string[] $cards
     */
    public static function calculateRoundScore(
        array $cards,
        int $xeriCount,
        int $xeriWithJackCount,
        bool $hasMostCards,
        bool $hasMostCardsTie
    ): int {
        $score = 0;

        if ($hasMostCards && !$hasMostCardsTie) {
            $score += 3;
        }
        if (in_array('2S', $cards, true)) {
            $score += 1;
        }
        if (in_array('10D', $cards, true)) {
            $score += 1;
        }

        foreach ($cards as $card) {
            if (self::isFaceOrTen($card)) {
                $score += 1;
            }
        }

        // Jack-xeri gives 20 points total (not 10 + 20).
        $normalXeri = max(0, $xeriCount - $xeriWithJackCount);
        $score += ($normalXeri * 10) + ($xeriWithJackCount * 20);

        return $score;
    }
}
