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

        $this->initGameStateLabels(array());
    }

    protected function getGameName()
    {
        // Used for translations and stuff. Please do not modify.
        return "nibble";
    }

    protected function setupNewGame($players, $options = array())
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        // Create players
        // Note: if you added some extra field on "player" table in the database (dbmodel.sql), you can initialize it there.
        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = array();
        foreach ($players as $player_id => $player) {
            $color = array_shift($default_colors);
            $values[] = "('" . $player_id . "','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
        }
        $sql .= implode(',', $values);
        $this->DbQuery($sql);
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        /************ Start the game initialization *****/

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
        $result = array();

        $current_player_id = $this->getCurrentPlayerId();    // !! We must only return informations visible by this player !!

        $sql = "SELECT player_id id, player_score score FROM player";
        $result = array(
            "version" => (int) $this->gamestate->table_globals[300],
            "players" => $this->getCollectionFromDb($sql),
            "board" => $this->globals->get("board"),
            "legalMoves" => $this->calcLegalMoves(),
            "collections" => $this->globals->get("collections", []),
        );

        return $result;
    }

    public function getGameProgression()
    {
        // TODO: compute and return the game progression

        return 0;
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

        $legalMoves = array();

        $board = $this->globals->get("board");
        $activeColor = $this->globals->get("activeColor");

        foreach ($board as $rowId => $row) {
            foreach ($row as $columnId => $color) {
                if ($this->isMoveLegal($board, $rowId, $columnId, $activeColor)) {
                    $legalMoves[] = array("row" => $rowId, "column" => $columnId, "color_id" => $color);
                };
            }
        }

        $this->globals->set("legalMoves", $legalMoves);

        return $legalMoves;
    }

    public function isMoveLegal(array $board, int $x, int $y, ?int $activeColor)
    {
        $color = $board[$x][$y];

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
            $this->dump("neighbors", $openNeighbors);
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

        $possible_keys = array("row", "column", "color_id");
        $possible_positions = range(0, 8);
        $possible_colors = range(1, 9);

        foreach ($discs as $disc) {
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
                throw new \BgaVisibleSystemException("Invalid discs input");
            }
        }
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

            $collections[$player_id][] = $disc;
            $this->globals->set("collections", $collections);

            $this->notifyAllPlayers(
                "takeDisc",
                clienttranslate('${player_name} takes a ${color_label} disc from position (${row}, ${column})'),
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

        $this->globals->set("board", $board);
        $this->gamestate->nextState("betweenPlayers");
    }


    //////////////////////////////////////////////////////////////////////////////
    //////////// Game state arguments
    ////////////

    public function argPlayerTurn()
    {
        return array(
            "legalMoves" => $this->globals->get("legalMoves"),
            "activeColor" => $this->globals->get("activeColor"),
        );
    }

    //////////////////////////////////////////////////////////////////////////////
    //////////// Game state actions
    ////////////

    public function stBetweenPlayers()
    {
        $player_id = (int) $this->getActivePlayerId();

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
