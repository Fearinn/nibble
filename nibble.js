/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Nibble implementation : © Matheus Gomes matheusgomesforwork@gmail.com
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * nibble.js
 *
 * Nibble user interface script
 *
 * In this file, you are describing the logic of your user interface, in Javascript language.
 *
 */

define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
  `${g_gamethemeurl}modules/bga-cards.js`,
], function (dojo, declare) {
  return declare("bgagame.nibble", ebg.core.gamegui, {
    constructor: function () {
      console.log("nibble constructor");

      this.nibGlobals = {};
      this.nibCardsManagers = {};
      this.nibStocks = {};

      this.nibGlobals.colors = {
        1: "green",
        2: "purple",
        3: "red",
        4: "yellow",
        5: "orange",
        6: "blue",
        7: "white",
        8: "gray",
        9: "black",
      };

      this.nibGlobals.selectedColor = null;

      this.nibCardsManagers.board = new CardManager(this, {
        cardHeight: 60,
        cardWidth: 60,
        selectedCardClass: "nib_selectedDisc",
        getId: (card) => `disc-${card.row}${card.column}`,
        setupDiv: (card, div) => {
          div.classList.add("nib_disc");
          div.style.backgroundColor = this.nibGlobals.colors[card.colorId];
          div.style.gridRow = card.row + 1;
          div.style.gridColumn = card.column + 1;
          div.style.position = "relative";
        },
        setupFrontDiv: (card, div) => {},
        setupBackDiv: (card, div) => {},
      });
    },

    setup: function (gamedatas) {
      console.log("Starting game setup");

      this.nibGlobals.board = gamedatas.board;
      this.nibGlobals.legalMoves = gamedatas.legalMoves;

      const boardElement = document.getElementById("nib_board");

      this.nibStocks.board = new CardStock(
        this.nibCardsManagers.board,
        boardElement,
        {}
      );

      this.nibStocks.board.onSelectionChange = (selection, lastChange) => {
        const confirmBtn = document.getElementById("nib_confirmBtn");
        const itemsCount = selection.length;
        const disc = lastChange;

        if (confirmBtn) {
          confirmBtn.remove();
        }

        if (this.nibGlobals.selectedColor != disc.colorId) {
          this.nibGlobals.selectedColor = disc.colorId;

          if (itemsCount >= 2) {
            this.nibStocks.board.unselectAll(true);
            this.nibStocks.board.selectCard(disc, true);
          }

          return;
        }

        if (itemsCount > 0) {
          this.addActionButton("nib_confirmBtn", _("Confirm selection"), () => {
            this.onTakeDiscs(selection);
          });
        }
      };

      let rowId = 0;
      let columnId = 0;

      this.nibGlobals.board.forEach((row) => {
        row.forEach((colorId) => {
          const card = {
            row: rowId,
            column: columnId,
            colorId: colorId,
          };

          const isSelectable = this.nibGlobals.legalMoves.some((disc) => {
            return disc.row == rowId && disc.column == columnId;
          });

          if (card.colorId) {
            this.nibStocks.board.addCard(
              card,
              {},
              { selectable: isSelectable }
            );
          }

          columnId++;
        });

        rowId++;
        columnId = 0;
      });

      // Setup game notifications to handle (see "setupNotifications" method below)
      this.setupNotifications();

      console.log("Ending game setup");
    },

    ///////////////////////////////////////////////////
    //// Game & client states

    onEnteringState: function (stateName, args) {
      console.log("Entering state: " + stateName);

      if (stateName === "playerTurn") {
        const legalMoves = args.args.legalMoves;

        this.nibGlobals.legalMoves = legalMoves;

        if (this.isCurrentPlayerActive()) {
          this.nibStocks.board.setSelectionMode("multiple", legalMoves);
        }
      }
    },

    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      if (stateName === "playerTurn") {
        this.nibStocks.board.setSelectionMode("none");
      }
    },

    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons: " + stateName);
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    performAction: function (action, args) {
      this.bgaPerformAction(action, args);
    },

    ///////////////////////////////////////////////////
    //// Player's action

    actTakeDiscs: function (discs) {
      this.performAction("actTakeDiscs", { discs: JSON.stringify(discs) });
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications

    setupNotifications: function () {
      console.log("notifications subscriptions setup");

      dojo.subscribe("takeDisc", this, "notif_takeDisc");
    },

    notif_takeDisc: function (notif) {
      const disc = notif.args.disc;

      this.nibStocks.board.removeCard(disc);
    },
  });
});
