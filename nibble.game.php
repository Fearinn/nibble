<?php

/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Nibble implementation : © Matheus Gomes matheusgomesforwork@gmail.com
 * 
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 * 
 * nibble.game.php
 *
 * This is the main file for your game logic.
 *
 * In this PHP file, you are going to defines the rules of the game.
 *
 */

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');

use Bga\GameFramework\Actions\Types\JsonParam;

class Nibble extends Table
{
    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels(
            [
                "variant" => 100,
                "boardFormat" => 101,
            ]
        );
    }

    protected function getGameName()
    {
        // Used for translations and stuff. Please do not modify.
        return "nibble";
    }

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        // Create players
        // Note: if you added some extra field on "player" table in the database (dbmodel.sql), you can initialize it there.
        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = [];
        foreach ($players as $player_id => $player) {
            $color = array_shift($default_colors);
            $values[] = "('" . $player_id . "','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
        }
        $sql .= implode(',', $values);
        $this->DbQuery($sql);
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        /************ Start the game initialization *****/
        foreach ($players as $player_id => $player) {
            $this->initStat("player", "piecesCollected", 0, $player_id);

            foreach ($this->colorsInfo() as $color_id => $color) {
                $this->initStat("player", "$color_id:collected", 0, $player_id);
            }
        }

        $colorsNumber = $this->colorsNumber();
        $boardSize = $this->boardSize();

        $board = $this->initializeBoard($boardSize, $colorsNumber);
        $this->globals->set("board", $board);

        $colors_ids = array_keys($this->colorsInfo());
        shuffle($colors_ids);
        $this->globals->set("orderedColors", $colors_ids);

        $collections = [];
        foreach ($players as $player_id => $player) {
            $collections[$player_id] = [];
        }

        $this->globals->set("collections", $collections);

        // Activate first player (which is in general a good idea :) )
        $this->activeNextPlayer();

        /************ End of the game initialization *****/
    }

    protected function getAllDatas()
    {
        $result = [];

        $current_player_id = $this->getCurrentPlayerId();    // !! We must only return informations visible by this player !!

        $sql = "SELECT player_id id, player_score score FROM player";
        $result = [
            "version" => (int) $this->gamestate->table_globals[300],
            "is13Colors" => $this->is13Colors(),
            "isHexagon" => $this->isHexagon(),
            "boardSize" => $this->boardSize(),
            "colors_info" => $this->colorsInfo(),
            "players" => $this->getCollectionFromDb($sql),
            "board" => $this->globals->get("board"),
            "orderedColors" => $this->globals->get("orderedColors"),
            "legalMoves" => $this->calcLegalMoves(true),
            "collections" => $this->globals->get("collections"),
            "counts" => $this->getCounts(),
            "playersNoInstaWin" => $this->globals->get("playersNoInstaWin", []),
            "majorityOwner" => $this->majorityOfMajorities(),
        ];

        return $result;
    }

    public function getGameProgression(): int
    {
        $board = $this->globals->get("board");
        $progression = 1 - $this->piecesCount($board) / $this->totalPieces();
        return round($progression * 100);
    }

    //////////////////////////////////////////////////////////////////////////////
    //////////// Utility functions
    //////////// 

    /* SETUP */

    function isSafeColor($board, $row, $col, $color): bool
    {
        // Check the orthogonal neighbors (up, down, left, right)
        $rows = count($board);
        $cols = count($board[0]);

        if ($row > 0 && $board[$row - 1][$col] === $color) {
            return false;
        } // Up

        if ($row < $rows - 1 && $board[$row + 1][$col] === $color) {
            return false;
        } // Down

        if ($col > 0 && $board[$row][$col - 1] === $color) {
            return false;
        } // Left

        if ($col < $cols - 1 && $board[$row][$col + 1] === $color) {
            return false;
        } // Right

        return true;
    }

    function placeDiscs(array &$board, array &$colorCounts, array $colors, int $row = 0, int $col = 0): bool
    {
        $rows = count($board);
        $cols = count($board[0]);

        if ($row == $rows) {
            return true;
        } // All rows are processed

        // Move to the next row when the end of a column is reached
        $nextRow = $col == $cols - 1 ? $row + 1 : $row;
        $nextCol = $col == $cols - 1 ? 0 : $col + 1;

        // Shuffle colors to introduce randomness
        shuffle($colors);

        foreach ($colors as $color) {
            if ($this->isSafeColor($board, $row, $col, $color) && $colorCounts[$color] < count($colors)) {
                $board[$row][$col] = $color;
                $colorCounts[$color]++;

                if ($this->placeDiscs($board, $colorCounts, $colors, $nextRow, $nextCol)) {
                    return true; // Placement is successful
                }

                $board[$row][$col] = null; // Backtrack
                $colorCounts[$color]--;
            }
        }

        return false; // No valid placement found
    }

    function initializeBoard(int $size, int $colorsNumber): array
    {
        if ($this->isHexagon()) {
            return $this->hex_initializeBoard($size, $colorsNumber);
        }

        $board = array_fill(0, $size, array_fill(0, $size, null));
        $colors = range(1, $colorsNumber); // Represent colors as numbers (1, 2, 3, ..., 9)
        $colorCounts = array_fill(1, $colorsNumber, 0); // Initialize color counts

        // Attempt to place discs on the board
        if (!$this->placeDiscs($board, $colorCounts, $colors)) {
            throw new BgaVisibleSystemException("Failed to place discs in the board");
        }

        return $board;
    }

    private function hex_isSafeColor(array $board, int $row, int $col, int $color): bool
    {
        $directions = $this->directions($row);

        foreach ($directions as [$dRow, $dCol]) {
            $nRow = $row + $dRow;
            $nCol = $col + $dCol;

            if (isset($board[$nRow][$nCol]) && $board[$nRow][$nCol] === $color) {
                return false; // Adjacent disc has the same color
            }
        }

        return true;
    }

    public function hex_placeDiscs(array &$board, array &$colorCounts, array $colors, array $mask, int $row = 0, int $col = 0): bool
    {
        $rows = count($board);
        $cols = count($board[0]);

        if ($row == $rows) {
            return true;
        }

        $nextRow = $col == $cols - 1 ? $row + 1 : $row;
        $nextCol = $col == $cols - 1 ? 0 : $col + 1;

        if (!$mask[$row][$col]) {
            return $this->hex_placeDiscs($board, $colorCounts, $colors, $mask, $nextRow, $nextCol); // Skip invalid cell
        }

        shuffle($colors);

        foreach ($colors as $color) {
            if ($this->hex_isSafeColor($board, $row, $col, $color) && $colorCounts[$color] < count($mask)) {
                $board[$row][$col] = $color;
                $colorCounts[$color]++;

                if ($this->hex_placeDiscs($board, $colorCounts, $colors, $mask, $nextRow, $nextCol)) {
                    return true;
                }

                $board[$row][$col] = null;
                $colorCounts[$color]--;
            }
        }

        return false;
    }

    private function hex_generateHexagonMask(int $size): array
    {
        $mask = array_fill(0, $size, array_fill(0, $size, false));
        $mid = floor($size / 2); // Center row index

        for ($row = 0; $row < $size; $row++) {
            // Calculate the number of pieces in this row
            $rowOffset = abs($row - $mid);
            $pieces = $size - $rowOffset; // Decreases by 1 as you move away from the center

            if ($pieces < 8) {
                continue; // Skip rows that are outside the hexagon
            }

            // Determine start and end columns for the current row
            $startCol = floor(($size - $pieces) / 2);
            $endCol = $startCol + $pieces - 1;

            for ($col = $startCol; $col <= $endCol; $col++) {
                $mask[$row][$col] = true;
            }
        }

        return $mask;
    }

    public function directions(int $row): array
    {
        if ($this->isHexagon()) {
            if ($row % 2 !== 0) {
                return [
                    [0, -1],
                    [-1, -1],
                    [-1, 0],
                    [0, 1],
                    [1, 0],
                    [1, -1],
                ];
            }

            return [
                [0, -1],
                [-1, 0],
                [-1, 1],
                [0, 1],
                [1, 1],
                [1, 0],

            ];
        }

        return [
            [0, -1],
            [0, 1],
            [-1, 0],
            [1, 0],
        ];
    }

    public function hex_initializeBoard(): array
    {
        $size = $this->boardSize();
        $colorsNumber = $this->colorsNumber();

        $board = array_fill(0, $size, array_fill(0, $size, null));
        $colors = range(1, $colorsNumber);
        $colorCounts = array_fill(1, $colorsNumber, 0);

        $mask = $this->hex_generateHexagonMask($size);

        if (!$this->hex_placeDiscs($board, $colorCounts, $colors, $mask)) {
            throw new Exception("Failed to place discs on the board");
        }

        return $board;
    }

    public function is13Colors(): bool
    {
        $variant = (int) $this->getGameStateValue("variant");
        return $variant === 2 || $variant === 3;
    }

    public function isHexagon(): bool
    {
        return (int) $this->getGameStateValue("variant") === 3;
    }

    public function totalPieces(): int
    {
        return $this->colorsNumber() ** 2;
    }

    public function colorsInfo(): array
    {
        if ($this->is13Colors()) {
            return $this->colors13_info;
        }

        return $this->colors_info;
    }

    public function colorsNumber(): int
    {
        return count($this->colorsInfo());
    }

    public function boardSize(): int
    {
        if ($this->isHexagon()) {
            return 15;
        }

        return $this->colorsNumber();
    }

    public function adjacentPieces(): int
    {
        if ($this->is13Colors()) {
            return 10;
        }

        return 7;
    }

    public function adjacentColors(): int
    {
        return 3;
    }

    public function checkVersion(int $clientVersion): void
    {
        $serverVersion = (int) $this->gamestate->table_globals[300];
        if ($clientVersion != $serverVersion) {
            throw new \BgaVisibleSystemException($this->_("A new version of this game is now available. Please reload the page (F5)."));
        }
    }

    public function calcLegalMoves(bool $useCached = false): array
    {
        if ($useCached) {
            $cached = $this->globals->get("legalMoves", null);

            if ($cached !== null) {
                return $cached;
            }
        }

        $legalMoves = [];

        $board = $this->globals->get("board");
        $activeColor = $this->globals->get("activeColor");

        foreach ($board as $rowId => $row) {
            foreach ($row as $columnId => $color) {
                if ($this->isMoveLegal($board, $rowId, $columnId, $activeColor)) {
                    $legalMoves[] = ["row" => $rowId, "column" => $columnId, "color_id" => $color];
                };
            }
        }

        $this->globals->set("legalMoves", $legalMoves);
        return $legalMoves;
    }

    public function isMoveLegal(array $board, int $x, int $y, ?int $activeColor): bool
    {
        $color = $board[$x][$y];

        $piecesCount = (int) $this->piecesCount($board);
        if ($piecesCount === 1) {
            return true;
        }

        if ($activeColor && $color != $activeColor) {
            return false;
        }

        if (!$this->isSafeNeighbor($board, $x, $y)) {
            return false;
        }

        // Copy the board
        $tempBoard = $board;
        // Remove the disc
        $tempBoard[$x][$y] = null;

        // Check connectivity
        $components = $this->findConnectedComponents($tempBoard);

        // If more than one component is found, the move is illegal
        return count($components) === 1;
    }

    public function isSafeNeighbor(array $board, int $row, int $col): bool
    {
        if ($this->isHexagon()) {
            return $this->hex_isSafeNeighbor($board, $row, $col);
        }

        // Check the orthogonal neighbors (up, down, left, right)
        $rows = count($board);
        $cols = count($board[0]);

        $openNeighbors = 4;

        if ($row > 0 && $board[$row - 1][$col] !== null) {
            $openNeighbors--;
        } // Up

        if ($row < $rows - 1 && $board[$row + 1][$col] !== null) {
            $openNeighbors--;
        } // Down

        if ($col > 0 && $board[$row][$col - 1] !== null) {
            $openNeighbors--;
        } // Left

        if ($col < $cols - 1 && $board[$row][$col + 1] !== null) {
            $openNeighbors--;
        } // Right

        if ($row == 0 && $col == 0) {
            return true;
        }

        return $openNeighbors >= 2;
    }

    public function findConnectedComponents($board)
    {
        $visited = [];
        $components = [];

        foreach ($board as $x => $row) {
            foreach ($row as $y => $disc) {
                if ($disc !== null && !isset($visited["$x,$y"])) {
                    // Start a new component
                    $component = [];
                    $this->floodFill($board, $x, $y, $visited, $component);
                    $components[] = $component;
                }
            }
        }

        return $components;
    }

    public function floodFill($board, $x, $y, &$visited, &$component): void
    {
        $stack = [[$x, $y]];

        while (!empty($stack)) {
            list($cx, $cy) = array_pop($stack);

            if (!isset($visited["$cx,$cy"]) && $board[$cx][$cy] !== null) {
                $visited["$cx,$cy"] = true;
                $component[] = [$cx, $cy];

                // Add orthogonal neighbors to the stack
                $directions = $this->directions($cx);

                foreach ($directions as [$dx, $dy]) {
                    $nx = $cx + $dx;
                    $ny = $cy + $dy;

                    if (isset($board[$nx][$ny]) && $board[$nx][$ny] !== null) {
                        $stack[] = [$nx, $ny];
                    }
                }
            }
        }
    }

    public function validateDiscsInput($discs): void
    {
        if (!is_array($discs)) {
            throw new BgaVisibleSystemException("Invalid discs input");
        }

        $colorsNumber = $this->colorsNumber();
        $boardSize = $this->boardSize();

        $possible_keys = ["row", "column", "color_id", "location"];
        $possible_positions = range(0, $boardSize - 1);
        $possible_colors = range(1, $colorsNumber);

        $board = $this->globals->get("board");
        $components = [];

        $currentColor = null;
        foreach ($discs as $index => $disc) {
            foreach ($disc as $key => $value) {
                if (!in_array($key, $possible_keys)) {
                    throw new BgaVisibleSystemException("Invalid discs input");
                }
            }

            if (
                !in_array($disc["row"], $possible_positions) ||
                !in_array($disc["column"], $possible_positions) ||
                !in_array($disc["color_id"], $possible_colors)
            ) {
                throw new BgaVisibleSystemException("Invalid discs input");
            }

            $discColor = (int) $disc["color_id"];
            if ($currentColor === null) {
                $currentColor = $discColor;
            }

            if ($currentColor !== $discColor) {
                throw new BgaVisibleSystemException("Invalid discs input");
            }

            if (count($discs) === 1) {
                return;
            }

            if ($this->isHexagon()) {
                return;
            }

            $x = (int) $disc["row"];
            $y = (int) $disc["column"];
            $board[$x][$y] = null;

            $components = $this->findConnectedComponents($board);
            $componentsCount = count($components);

            if ($componentsCount !== 1) {
                throw new BgaUserException($this->_("Illegal move: you can't divide the pieces into separate two groups"));
            }
        }
    }

    /* HEX CHECKS */

    public function hex_isSafeNeighbor(array $board, int $row, int $col, $shift = 0): bool
    {
        if ($shift >= 6) {
            return false;
        }

        $openNeighbors = 0;
        $directions = $this->directions($row);

        for ($i = 0; $i < 6; $i++) {
            $direction = $directions[($shift + $i) % 6];
            [$x, $y] = $direction;

            $nx = $row + $x;
            $ny = $col + $y;

            if (!isset($board[$nx][$ny])) {
                $openNeighbors++;

                if ($openNeighbors === 3) {
                    return true;
                }

                continue;
            }

            $openNeighbors = 0;
        }

        return $this->hex_isSafeNeighbor($board, $row, $col, $shift + 1);
    }

    public function getCounts(?int $player_id = null): array
    {
        $counts = [];
        $collections = $this->globals->get("collections");

        if ($player_id) {
            $collection = $collections[$player_id];

            foreach ($collection as $color_id => $discs) {
                $counts[$color_id] = count($discs);
            }

            return $counts;
        }

        $players = $this->loadPlayersBasicInfos();
        foreach ($players as $player_id => $player) {
            $counts[$player_id] = [];
            $collection = $collections[$player_id];

            foreach ($collection as $color_id => $discs) {
                $counts[$player_id][$color_id] = count($discs);
            }
        }

        return $counts;
    }

    public function piecesCount(array $board): int
    {
        $piecesCount = 0;
        $colorsNumber = $this->colorsNumber();
        for ($x = 0; $x < $colorsNumber; $x++) {
            for ($y = 0; $y < $colorsNumber; $y++) {
                if ($board[$x][$y] !== null) {
                    $piecesCount++;
                }
            }
        }

        return $piecesCount;
    }

    public function majorityOfMajorities(): int | null
    {
        $winner_id = null;
        $majorities = [];
        $collections = $this->globals->get("collections");
        $majority = ceil($this->colorsNumber() / 2);

        $majorities = [];
        foreach ($collections as $player_id => $colors) {
            $majorities[$player_id] = 0;

            foreach ($colors as $color_id => $discs) {
                $discsCount = count($discs);

                if ($discsCount >= $majority) {
                    $majorities[$player_id]++;
                }
            }
        }

        foreach ($majorities as $player_id => $majoritiesCount) {
            if ($majoritiesCount >= $majority) {
                $winner_id = $player_id;
            }
        }

        return $winner_id;
    }

    public function canInstaWin(int $player_id): bool
    {
        $orderedColors = $this->globals->get("orderedColors");
        $collections = $this->globals->get("collections");

        $opponent_id = $this->getPlayerAfter($player_id);
        $opponentCollection = $collections[$opponent_id];

        $possibleAdjacent = 0;
        foreach ($orderedColors as $color_id) {
            $opponentDiscs = [];
            if (array_key_exists($color_id, $opponentCollection)) {
                $opponentDiscs = $opponentCollection[$color_id];
            }
            $opponentDiscsCount = count($opponentDiscs);

            if ($opponentDiscsCount === 0) {
                return true;
            }

            if ($opponentDiscsCount <= $this->colorsNumber() - $this->adjacentPieces()) {
                $possibleAdjacent++;

                if ($possibleAdjacent >= $this->adjacentColors()) {
                    return true;
                }
            } else {
                $possibleAdjacent = 0;
            }
        }

        return false;
    }

    public function getPlayersInstaWin(): array
    {
        $players = $this->loadPlayersBasicInfos();

        $playersInstaWin = [];
        foreach ($players as $player_id => $player) {
            $playersInstaWin[$player_id] = $this->canInstaWin($player_id);
        }

        return $playersInstaWin;
    }

    public function updateWinConWarn(): void
    {
        $playersNoInstaWin = $this->globals->get("playersNoInstaWin", []);
        $updated = false;

        $players = $this->loadPlayersBasicInfos();
        foreach ($players as $player_id => $player) {
            $canInstaWin = $this->canInstaWin($player_id);

            if ($canInstaWin || in_array($player_id, $playersNoInstaWin)) {
                continue;
            }

            $playersNoInstaWin[] = $player_id;
            $updated = true;
        }

        if ($updated) {
            $this->notifyAllPlayers(
                "updateWinConWarn",
                "",
                [
                    "playersNoInstaWin" => $playersNoInstaWin,
                    "majorityOwner" => $this->majorityOfMajorities(),
                ]
            );
        }

        $this->globals->set("playersNoInstaWin", $playersNoInstaWin);
    }

    public function isGameEnd($player_id): bool
    {
        $winner_id = null;
        $win_condition = null;

        $board = $this->globals->get("board");
        $piecesCount = $this->piecesCount($board);

        $collection = $this->globals->get("collections")[$player_id];
        $orderedColors = $this->globals->get("orderedColors");

        $adjacentColors = 0;

        foreach ($orderedColors as $color_id) {
            $discs = [];

            if (array_key_exists($color_id, $collection)) {
                $discs = $collection[$color_id];
            }

            $discsCount = count($discs);
            $colorsNumber = $this->colorsNumber();

            if ($discsCount === $colorsNumber) {
                $winner_id = $player_id;
                $win_condition = clienttranslate("all pieces of one color");
                break;
            }

            if ($discsCount >= $this->adjacentPieces()) {
                $adjacentColors++;
            } else {
                $adjacentColors = 0;
            }

            if ($adjacentColors === $this->adjacentColors()) {
                $winner_id = $player_id;
                $win_condition = clienttranslate('${pieces_nbr} pieces of ${colors_nbr} adjacent colors');
                break;
            }
        }

        $majorityHolder_id = $this->majorityOfMajorities();

        if ($majorityHolder_id !== null) {
            $loser_id = $this->getPlayerAfter($majorityHolder_id);
            $canInstaWin = $this->canInstaWin($loser_id);

            if (!$canInstaWin || $piecesCount === 0) {
                $winner_id = $majorityHolder_id;
                $win_condition = clienttranslate("majority of majorities");
            }
        }

        if ($winner_id && $win_condition) {
            $this->notifyAllPlayers(
                "announceWinner",
                clienttranslate('${player_name} wins the game by ${win_condition}'),
                [
                    "player_id" => $winner_id,
                    "player_name" => $this->getPlayerNameById($winner_id),
                    "win_condition" => [
                        "log" => $win_condition,
                        "args" => [
                            "pieces_nbr" => $this->adjacentPieces(),
                            "colors_nbr" => $this->adjacentColors(),
                        ],
                    ],
                    "i18n" => ["win_condition"],
                ]
            );

            $this->DbQuery("UPDATE player SET player_score=1 WHERE player_id=$winner_id");
            return true;
        }

        return false;
    }

    //////////////////////////////////////////////////////////////////////////////
    //////////// Player actions
    //////////// 

    public function actTakeDiscs(int $clientVersion, #[JsonParam(alphanum: false)] array $discs): void
    {
        $this->checkVersion($clientVersion);

        $this->validateDiscsInput($discs);

        $player_id = $this->getActivePlayerId();

        $board = $this->globals->get("board");
        $legalMoves = $this->globals->get("legalMoves");
        $activeColor = $this->globals->get("activeColor");

        foreach ($discs as $disc) {
            $disc_row = (int) $disc["row"];
            $disc_column = (int) $disc["column"];
            $disc_color = (int) $disc["color_id"];

            if (
                !in_array($disc, $legalMoves)
            ) {
                throw new BgaVisibleSystemException("You can't take this disc now: $disc_color, $activeColor");
            }

            if (!$activeColor) {
                $this->globals->set("activeColor", $disc_color);
                $activeColor = $disc_color;
            }

            $board[$disc_row][$disc_column] = null;

            $collections = $this->globals->get("collections");
            $collections[$player_id][$disc_color][] = $disc;
            $this->globals->set("collections", $collections);

            $log_column = $disc_column + 1;
            $log_row = $disc_row + 1;

            if ($this->isHexagon()) {
                if ($disc_row % 2 === 0) {
                    $log_column += 0.5;
                }
            }

            $this->notifyAllPlayers(
                "takeDisc",
                clienttranslate('${player_name} takes a ${color_label} piece (${column}, ${row})'),
                [
                    "player_id" => $player_id,
                    "player_name" => $this->getPlayerNameById($player_id),
                    "row" => $log_row,
                    "column" => $log_column,
                    "disc" => $disc,
                    "color_label" => $this->colorsInfo()[$activeColor]["tr_name"],
                    "i18n" => ["color_label"],
                    "preserve" => ["color_id"],
                    "color_id" => $activeColor,
                ]
            );

            $this->incStat(1, "piecesCollected", $player_id);
            $this->incStat(1, "$disc_color:collected", $player_id);
        }

        $this->globals->set("board", $board);
        $this->gamestate->nextState("betweenPlayers");
    }

    //////////////////////////////////////////////////////////////////////////////
    //////////// Game state actions and arguments
    ////////////

    public function stPlayerTurn(): void {}

    public function argPlayerTurn(): array
    {
        return [
            "legalMoves" => $this->globals->get("legalMoves"),
            "activeColor" => $this->globals->get("activeColor"),
        ];
    }

    public function stBetweenPlayers(): void
    {
        $player_id = (int) $this->getActivePlayerId();

        $this->updateWinConWarn();

        if ($this->isGameEnd($player_id)) {
            $this->gamestate->nextState("gameEnd");
            return;
        }

        $this->globals->set("activeColor", null);

        $this->calcLegalMoves();

        $this->giveExtraTime($player_id);
        $this->activeNextPlayer();

        $this->gamestate->nextState("nextPlayer");
    }

    //////////////////////////////////////////////////////////////////////////////
    //////////// Zombie
    ////////////

    protected function zombieTurn(array $state, int $active_player): void
    {
        $statename = $state['name'];

        if ($state['type'] === "activeplayer") {
            switch ($statename) {
                default:
                    $this->gamestate->nextState("zombiePass");
                    break;
            }

            return;
        }

        if ($state['type'] === "multipleactiveplayer") {
            // Make sure player is in a non blocking status for role turn
            $this->gamestate->setPlayerNonMultiactive($active_player, '');

            return;
        }

        throw new feException("Zombie mode not supported at this game state: " . $statename);
    }

    public function debug_winConWarn(): void
    {
        $playersNoInstaWin = [2392035];

        $this->notifyAllPlayers(
            "updateWinConWarn",
            "",
            [
                "playersNoInstaWin" => $playersNoInstaWin,
                "majorityOwner" => 2392035,
            ]
        );
    }

    public function debug_announceWinner(int $player_id): void
    {
        $winner_id = $player_id;
        $win_condition = clienttranslate('${pieces_nbr} pieces of ${colors_nbr} adjacent colors');

        $this->notifyAllPlayers(
            "announceWinner",
            clienttranslate('${player_name} wins the game by ${win_condition}'),
            [
                "player_id" => $winner_id,
                "player_name" => $this->getPlayerNameById($winner_id),
                "win_condition" => [
                    "log" => $win_condition,
                    "args" => [
                        "pieces_nbr" => $this->adjacentPieces(),
                        "colors_nbr" => $this->adjacentColors(),
                    ],
                ],
                "i18n" => ["win_condition"],
            ]
        );
    }

    public function debug_canInstaWin(int $player_id): void
    {
        $this->canInstaWin($player_id);
    }

    public function debug_isSafeNeighbor(): void
    {
        $board = $this->globals->get("board");

        $result = $this->isSafeNeighbor($board, 7, 0);
        throw new BgaUserException(json_encode($result));
    }

    public function debug_setBoard(): void
    {
        $size = $this->boardSize();
        $board = array_fill(0, $size, array_fill(0, $size, null));
        $board[0][3] = 10;
        $board[0][4] = 12;
        $board[1][2] = 1;
        $board[1][3] = 2;
        $board[1][5] = 8;
        $board[2][2] = 3;
        $board[2][3] = 4;
        $board[2][4] = 3;
        $board[2][5] = 5;

        $this->globals->set("board", $board);
    }

    ///////////////////////////////////////////////////////////////////////////////////:
    ////////// DB upgrade
    //////////

    /*
        upgradeTableDb:
        
        You don't have to care about this until your game has been published on BGA.
        Once your game is on BGA, this method is called everytime the system detects a game running with your old
        Database scheme.
        In this case, if you change your Database scheme, you just have to apply the needed changes in order to
        update the game database and allow the game to continue to run with your new version.
    
    */

    function upgradeTableDb($from_version)
    {
        // $from_version is the current version of this game database, in numerical form.
        // For example, if the game was running with a release of your game named "140430-1345",
        // $from_version is equal to 1404301345

        // Example:
        //        if( $from_version <= 1404301345 )
        //        {
        //            // ! important ! Use DBPREFIX_<table_name> for all tables
        //
        //            $sql = "ALTER TABLE DBPREFIX_xxxxxxx ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //        }
        //        if( $from_version <= 1405061421 )
        //        {
        //            // ! important ! Use DBPREFIX_<table_name> for all tables
        //
        //            $sql = "CREATE TABLE DBPREFIX_xxxxxxx ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //        }
        //        // Please add your future database scheme changes here
        //
        //


    }
}
