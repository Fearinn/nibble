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

  public function validateDiscs($discs): bool
  {
    if (!is_array($discs)) {
      return false;
    }

    $possible_keys = array("row", "column", "colorId");
    $possible_positions = range(0, 8);
    $possible_colors = range(1, 9);

    foreach ($discs as $disc) {
      foreach ($disc as $key => $value) {
        if (!in_array($key, $possible_keys)) {
          return false;
        }
      }

      if (
        !in_array($disc["row"], $possible_positions) ||
        !in_array($disc["column"], $possible_positions) ||
        !in_array($disc["colorId"], $possible_colors)
      ) {
        return false;
      }
    }

    return true;
  }

  public function takeDiscs()
  {
    $this->setAjaxMode();

    $discs = $this->getArg("discs", AT_json, true);

    if (!$this->validateDiscs($discs)) {
      throw new BgaSystemException("Bad value for: discs", true, true, FEX_bad_input_argument);
    }

    $this->game->takeDiscs($discs);

    $this->ajaxResponse();
  }
}
