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
        1: "lightgreen",
        2: "purple",
        3: "red",
        4: "yellow",
        5: "orange",
        6: "lightblue",
        7: "white",
        8: "gray",
        9: "black",
      };

      this.nibCardsManagers.board = new CardManager(this, {
        cardHeight: 60,
        cardWidth: 60,
        selectedCardClass: "nib_selectedDisc",
        getId: (card) => `disc-${card.row}${card.column}`,
        setupDiv: (card, div) => {
          div.classList.add("nib_disc");
          div.style.backgroundColor = this.nibGlobals.colors[card.colorId];
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
        if (!this.isCurrentPlayerActive()) {
          return;
        }

        const confirmBtn = document.getElementById("nib_confirmBtn");

        if (confirmBtn) {
          confirmBtn.remove();
        }

        const disc = lastChange;

        if (selection.length > 0) {
          this.addActionButton("nib_confirmBtn", _("Confirm selection"), () => {
            this.onTakeDisc(disc);
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

          this.nibStocks.board.addCard(card, {}, { selectable: isSelectable });

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

    // onEnteringState: this method is called each time we are entering into a new game state.
    //                  You can use this method to perform some user interface changes at this moment.
    //
    onEnteringState: function (stateName, args) {
      console.log("Entering state: " + stateName);

      if (stateName === "playerTurn") {
        const legalMoves = args.args.legalMoves;

        this.nibGlobals.legalMoves = legalMoves;

        if (this.isCurrentPlayerActive()) {
          this.nibStocks.board.setSelectionMode("single", legalMoves);
        }
      }
    },

    // onLeavingState: this method is called each time we are leaving a game state.
    //                 You can use this method to perform some user interface changes at this moment.
    //
    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      if (stateName === "playerTurn") {
        if (!this.isCurrentPlayerActive()) {
          this.nibStocks.board.selectionMode("none");
        }
      }
    },

    // onUpdateActionButtons: in this method you can manage "action buttons" that are displayed in the
    //                        action status bar (ie: the HTML links in the status bar).
    //
    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons: " + stateName);

      if (this.isCurrentPlayerActive()) {
        switch (
          stateName
          /*               
                 Example:
 
                 case 'myGameState':
                    
                    // Add 3 action buttons in the action status bar:
                    
                    this.addActionButton( 'button_1_id', _('Button 1 label'), 'onMyMethodToCall1' ); 
                    this.addActionButton( 'button_2_id', _('Button 2 label'), 'onMyMethodToCall2' ); 
                    this.addActionButton( 'button_3_id', _('Button 3 label'), 'onMyMethodToCall3' ); 
                    break;
*/
        ) {
        }
      }
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    /*
        
            Here, you can defines some utility methods that you can use everywhere in your javascript
            script.
        
        */

    ///////////////////////////////////////////////////
    //// Player's action

    onTakeDisc: function (disc) {
      this.bgaPerformAction("takeDisc", disc);
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications

    /*
            setupNotifications:
            
            In this method, you associate each of your game notifications with your local method to handle it.
            
            Note: game notification names correspond to "notifyAllPlayers" and "notifyPlayer" calls in
                  your nibble.game.php file.
        
        */
    setupNotifications: function () {
      console.log("notifications subscriptions setup");

      // TODO: here, associate your game notifications with local methods

      // Example 1: standard notification handling
      // dojo.subscribe( 'cardPlayed', this, "notif_cardPlayed" );

      // Example 2: standard notification handling + tell the user interface to wait
      //            during 3 seconds after calling the method in order to let the players
      //            see what is happening in the game.
      // dojo.subscribe( 'cardPlayed', this, "notif_cardPlayed" );
      // this.notifqueue.setSynchronous( 'cardPlayed', 3000 );
      //
    },

    // TODO: from this point and below, you can write your game notifications handling methods

    /*
        Example:
        
        notif_cardPlayed: function( notif )
        {
            console.log( 'notif_cardPlayed' );
            console.log( notif );
            
            // Note: notif.args contains the arguments specified during you "notifyAllPlayers" / "notifyPlayer" PHP call
            
            // TODO: play the card in the user interface.
        },    
        
        */
  });
});
