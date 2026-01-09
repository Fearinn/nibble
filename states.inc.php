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
    )
);
