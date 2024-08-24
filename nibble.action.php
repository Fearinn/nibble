<?php

/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Nibble implementation : © Matheus Gomes matheusgomesforwork@gmail.com
 *
 * This code has been produced on the BGA studio platform for use on https://boardgamearena.com.
 * See http://en.doc.boardgamearena.com/Studio for more information.
 * -----
 * 
 * nibble.action.php
 *
 * Nibble main action entry point
 *
 *
 * In this file, you are describing all the methods that can be called from your
 * user interface logic (javascript).
 *       
 * If you define a method "myAction" here, then you can call it from your javascript code with:
 * this.bgaPerformAction("myAction", ...)
 *
 */


class action_nibble extends APP_GameAction
{
  // Constructor: please do not modify
  public function __default()
  {
    if ($this->isArg('notifwindow')) {
      $this->view = "common_notifwindow";
      $this->viewArgs['table'] = $this->getArg("table", AT_posint, true);
    } else {
      $this->view = "nibble_nibble";
      $this->trace("Complete reinitialization of board game");
    }
  }

  public function takeDisc()
  {
    $this->setAjaxMode();

    $row = $this->getArg("row", AT_posint, true);
    $column = $this->getArg("column", AT_posint, true);
    $colorId = $this->getArg("colorId", AT_posint, true);

    $disc = array(
      "row" => $row,
      "column" => $column,
      "colorId" => $colorId
    );

    $this->game->takeDisc($disc);

    $this->ajaxResponse();
  }
}
