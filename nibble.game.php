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

        $this->initGameStateLabels([]);
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
            foreach ($this->colors_info as $color_id => $color) {
                $this->initStat("player", "$color_id:collected", 0, $player_id);
            }
        }

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

        function placeDiscs(&$board, &$colorCounts, $colors, $row = 0, $col = 0): bool
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
                if (isSafeColor($board, $row, $col, $color) && $colorCounts[$color] < 9) {
                    $board[$row][$col] = $color;
                    $colorCounts[$color]++;

                    if (placeDiscs($board, $colorCounts, $colors, $nextRow, $nextCol)) {
                        return true; // Placement is successful
                    }

                    $board[$row][$col] = null; // Backtrack
                    $colorCounts[$color]--;
                }
            }

            return false; // No valid placement found
        }

        function initializeBoard($size, $numColors): array
        {
            $board = array_fill(0, $size, array_fill(0, $size, null));
            $colors = range(1, $numColors); // Represent colors as numbers (1, 2, 3, ..., 9)
            $colorCounts = array_fill(1, $numColors, 0); // Initialize color counts

            // Attempt to place discs on the board
            if (!placeDiscs($board, $colorCounts, $colors)) {
                throw new BgaVisibleSystemException("Failed to place discs in the board");
            }

            return $board;
        }

        // Usage
        $boardSize = 9;
        $numColors = 9;
        $board = initializeBoard($boardSize, $numColors);
        $this->globals->set("board", $board);

        $colors_ids = array_keys($this->colors_info);
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

    /*
        getAllDatas: 
        
        Gather all informations about current game situation (visible by the current player).
        
        The method is called each time the game interface is displayed to a player, ie:
        _ when the game starts
        _ when a player refreshes the game page (F5)
    */
    protected function getAllDatas()
    {
        $result = [];

        $current_player_id = $this->getCurrentPlayerId();    // !! We must only return informations visible by this player !!

        $sql = "SELECT player_id id, player_score score FROM player";
        $result = [
            "version" => (int) $this->gamestate->table_globals[300],
            "players" => $this->getCollectionFromDb($sql),
            "board" => $this->globals->get("board"),
            "orderedColors" => $this->globals->get("orderedColors"),
            "legalMoves" => $this->calcLegalMoves(),
            "collections" => $this->globals->get("collections"),
            "counts" => $this->getCounts(),
        ];

        return $result;
    }

    public function getGameProgression(): int
    {
        $board = $this->globals->get("board");
        $progression = 1 - $this->piecesCount($board) / 81;
        return round($progression * 100);
    }


    //////////////////////////////////////////////////////////////////////////////
    //////////// Utility functions
    //////////// 

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
            $cached = $this->globals->get("legalMoves");

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

    public function isSafeNeighbor(array $board, int $row, int $col)
    {
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

    public function floodFill($board, $x, $y, &$visited, &$component)
    {
        $stack = [[$x, $y]];

        while (!empty($stack)) {
            list($cx, $cy) = array_pop($stack);

            if (!isset($visited["$cx,$cy"]) && $board[$cx][$cy] !== null) {
                $visited["$cx,$cy"] = true;
                $component[] = [$cx, $cy];

                // Add orthogonal neighbors to the stack
                $neighbors = [
                    [$cx - 1, $cy],
                    [$cx + 1, $cy],
                    [$cx, $cy - 1],
                    [$cx, $cy + 1]
                ];

                foreach ($neighbors as $neighbor) {
                    list($nx, $ny) = $neighbor;
                    if (isset($board[$nx][$ny]) && $board[$nx][$ny] !== null) {
                        $stack[] = $neighbor;
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

        $possible_keys = ["row", "column", "color_id"];
        $possible_positions = range(0, 8);
        $possible_colors = range(1, 9);

        $board = $this->globals->get("board");
        $components = [];
        $visited = [];

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

            $x = (int) $disc["row"];
            $y = (int) $disc["column"];
            $board[$x][$y] = null;

            $components = $this->findConnectedComponents($board);
            $componentsCount = count($components);

            if ($componentsCount !== 1) {
                throw new BgaUserException($this->_("Illegal move: you can't divide the pieces into separate two groups: $componentsCount, $index"));
            }
        }
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
        for ($x = 0; $x < 9; $x++) {
            for ($y = 0; $y < 9; $y++) {
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

        $majorities = [];
        foreach ($collections as $player_id => $colors) {
            $majorities[$player_id] = 0;

            foreach ($colors as $color_id => $discs) {
                $discsCount = count($discs);

                if ($discsCount >= 5) {
                    $majorities[$player_id]++;
                }
            }
        }

        foreach ($majorities as $player_id => $majoritiesCount) {
            if ($majoritiesCount >= 5) {
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

            if ($opponentDiscsCount <= 2) {
                $possibleAdjacent++;
            } else {
                $possibleAdjacent = 0;
            }
        }

        if ($possibleAdjacent >= 3) {
            return true;
        }

        return false;
    }

    public function isGameEnd($player_id): bool
    {
        $winner_id = null;
        $win_condition = null;

        $board = $this->globals->get("board");
        $piecesCount = $this->piecesCount($board);

        $sevenOrMore = [];
        $collection = $this->globals->get("collections")[$player_id];
        $orderedColors = $this->globals->get("orderedColors");

        $sevenOrMore = 0;

        foreach ($orderedColors as $color_id) {
            $discs = [];

            if (array_key_exists($color_id, $collection)) {
                $discs = $collection[$color_id];
            }

            $discsCount = count($discs);

            if ($discsCount === 9) {
                $winner_id = $player_id;
                $win_condition = clienttranslate("9 pieces of one color");
                break;
            }

            if ($discsCount >= 7) {
                $sevenOrMore++;
            } else {
                $sevenOrMore = 0;
            }

            if ($sevenOrMore === 3) {
                $winner_id = $player_id;
                $win_condition = clienttranslate("7 or more pieces of three adjacent colors");
                break;
            }
        }

        $majorityHolder_id = $this->majorityOfMajorities();

        if ($majorityHolder_id !== null) {
            $loser_id = $this->getPlayerAfter($majorityHolder_id);
            $canInstaWin = $this->canInstaWin($loser_id);

            if (!$canInstaWin) {
                $this->notifyAllPlayers(
                    "cantInstaWin",
                    clienttranslate('${player_name} can no longer reach any instantenous win condition'),
                    [
                        "player_id" => $loser_id,
                        "player_name" => $this->getPlayerNameById($loser_id),
                    ]
                );

                $winner_id = $majorityHolder_id;
                $win_condition = clienttranslate("majority of majorities");
            }

            if ($piecesCount === 0) {
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
                    "win_condition" => $win_condition,
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
                throw new BgaVisibleSystemException("You can't take these disc now: $disc_color, $activeColor");
            }

            if (!$activeColor) {
                $this->globals->set("activeColor", $disc_color);
                $activeColor = $disc_color;
            }

            $board[$disc_row][$disc_column] = null;

            $collections = $this->globals->get("collections");
            $collections[$player_id][$disc_color][] = $disc;
            $this->globals->set("collections", $collections);

            $this->notifyAllPlayers(
                "takeDisc",
                clienttranslate('${player_name} takes a ${color_label} piece from position (${row}, ${column})'),
                [
                    "player_id" => $player_id,
                    "player_name" => $this->getPlayerNameById($player_id),
                    "row" => $disc_row + 1,
                    "column" => $disc_column + 1,
                    "disc" => $disc,
                    "color_label" => $this->colors_info[$activeColor]["tr_name"],
                    "i18n" => ["color_label"],
                    "preserve" => ["color_id"],
                    "color_id" => $activeColor,
                ]
            );
        }

        $this->incStat(1, "$disc_color:collected", $player_id);
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

    public function debug_setBoard(): void
    {
        $board = [
            0 => [
                0 => null,
                1 => null,
                2 => 1,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            1 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            2 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            3 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            4 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            5 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            6 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            7 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
            8 => [
                0 => null,
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null,
                7 => null,
                8 => null,
            ],
        ];
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
