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
 * states.inc.php
 *
 * Nibble game states description
 *
 */

$machinestates = array(

    // The initial state. Please do not modify.
    1 => array(
        "name" => "gameSetup",
        "description" => "",
        "type" => "manager",
        "action" => "stGameSetup",
        "transitions" => array("" => 2)
    ),

    2 => array(
        "name" => "playerTurn",
        "description" => clienttranslate('${actplayer} must take one or more pieces'),
        "descriptionmyturn" => clienttranslate('${you} must take one or more pieces'),
        "type" => "activeplayer",
        "args" => "argPlayerTurn",
        "possibleactions" => array("actTakeDiscs"),
        "transitions" => array("betweenPlayers" => 3, "zombiePass" => 3)
    ),

    3 => array(
        "name" => "betweenPlayers",
        "description" => "",
        "type" => "game",
        "action" => "stBetweenPlayers",
        "transitions" => array("nextPlayer" => 2, "gameEnd" => 99),
        "updateGameProgression" => true,
    ),

    // Final state.
    // Please do not modify (and do not overload action/args methods).
    99 => array(
        "name" => "gameEnd",
        "description" => clienttranslate("End of game"),
        "type" => "manager",
        "action" => "stGameEnd",
        "args" => "argGameEnd"
    )

);
