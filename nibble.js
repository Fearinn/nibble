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

      this.nib_globals = {};
      this.nib_managers = {};
      this.nib_stocks = {};
      this.nib_selections = {};

      this.nib_globals.colors = {
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

      this.nib_selections.color = null;

      this.nib_managers.discs = new CardManager(this, {
        cardHeight: 60,
        cardWidth: 60,
        selectedCardClass: "nib_selectedDisc",
        getId: (card) => `disc-${card.row}${card.column}`,
        setupDiv: (card, div) => {
          const color = this.nib_globals.colors[card.color_id];
          div.classList.add("nib_disc");
          div.style.backgroundColor = color;
          div.style.gridRow = card.row + 1;
          div.style.gridColumn = card.column + 1;
          div.style.position = "relative";

          this.addTooltip(div.id, _(color), "");

          const colorblindHelp = document.createElement("span");
          colorblindHelp.textContent = card.color_id;
          colorblindHelp.classList.add("nib_colorblindHelp");
          div.appendChild(colorblindHelp);
        },
        setupFrontDiv: (card, div) => {},
        setupBackDiv: (card, div) => {},
      });
    },

    setup: function (gamedatas) {
      console.log("Starting game setup");

      this.nib_globals.players = gamedatas.players;

      this.nib_globals.board = gamedatas.board;
      this.nib_globals.legalMoves = gamedatas.legalMoves;
      this.nib_globals.collections = gamedatas.collections;

      const boardElement = document.getElementById("nib_board");

      this.nib_stocks.board = new CardStock(
        this.nib_managers.discs,
        boardElement,
        {}
      );

      this.nib_stocks.board.onSelectionChange = (selection, lastChange) => {
        const confirmationBtn = document.getElementById("nib_confirmationBtn");
        const itemsCount = selection.length;
        const disc = lastChange;

        this.nib_selections.discs = selection;

        if (confirmationBtn) {
          confirmationBtn.remove();
        }

        if (
          this.nib_selections.color &&
          this.nib_selections.color != disc.color_id
        ) {
          if (itemsCount >= 2) {
            this.nib_stocks.board.unselectAll(true);
            this.nib_stocks.board.selectCard(disc, true);

            this.nib_selections.color = disc.color_id;
            this.nib_selections.discs = [disc];
          }
        }

        if (itemsCount > 0) {
          this.addActionButton(
            "nib_confirmationBtn",
            _("Confirm selection"),
            () => {
              this.actTakeDiscs();
            }
          );
        }
      };

      let rowId = 0;
      let columnId = 0;

      this.nib_globals.board.forEach((row) => {
        row.forEach((color_id) => {
          const card = {
            row: rowId,
            column: columnId,
            color_id: color_id,
          };

          const isSelectable = this.nib_globals.legalMoves.some((disc) => {
            return disc.row == rowId && disc.column == columnId;
          });

          if (card.color_id) {
            this.nib_stocks.board.addCard(
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

      for (const player_id in this.nib_globals.players) {
        const player = this.nib_globals.players[player_id];

        const order = player_id == this.player_id ? -1 : 1;
        document.getElementById("nib_collections").innerHTML += `
          <div id="nib_collectionContainer:${player_id}"
          class="nib_collectionContainer whiteblock" style='order: ${order}'>
            <h3 id="nib_collectionTitle" class="nib_collectionTitle" style="color: #${player.color}">${player.name}</h3>
            <div id="nib_collection:${player_id}" class="nib_collection"></div>
          </div>
        `;
      }

      for (const player_id in this.nib_globals.players) {
        this.nib_stocks[player_id] = {};

        this.nib_stocks[player_id].collection = new SlotStock(
          this.nib_managers.discs,
          document.getElementById(`nib_collection:${player_id}`),
          {
            center: false,
            direction: "column",
            slotsIds: [1, 2, 3, 4, 5, 6, 7, 8, 9],
            mapCardToSlot: (disc) => {
              return Number(disc.color_id);
            },
          }
        );

        const collections = this.nib_globals.collections[player_id];
        for (const disc_id in collections) {
          const disc = collections[disc_id];
          this.nib_stocks[player_id].collection.addCard(disc);
        }
      }

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

        this.nib_globals.legalMoves = legalMoves;

        if (this.isCurrentPlayerActive()) {
          this.nib_stocks.board.setSelectionMode("multiple", legalMoves);
        }
      }
    },

    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      if (stateName === "playerTurn") {
        this.nib_stocks.board.setSelectionMode("none");
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

    actTakeDiscs: function () {
      const discs = this.nib_selections.discs;
      this.performAction("actTakeDiscs", { discs: JSON.stringify(discs) });
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications

    setupNotifications: function () {
      console.log("notifications subscriptions setup");

      dojo.subscribe("takeDisc", this, "notif_takeDisc");
    },

    notif_takeDisc: function (notif) {
      const player_id = notif.args.player_id;
      const disc = notif.args.disc;

      this.nib_stocks[player_id].collection.addCard(disc);

      this.nib_selections.color = null;
    },
  });
});
