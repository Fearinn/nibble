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
    },

    setup: function (gamedatas) {
      console.log("Starting game setup");

      this.nib = {};
      this.nib.info = {};
      this.nib.globals = {};
      this.nib.managers = {};
      this.nib.stocks = {};
      this.nib.selections = {};

      this.nib.info.colors = {
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

      this.nib.version = gamedatas.version;
      this.nib.info.players = gamedatas.players;
      this.nib.info.orderedColors = gamedatas.orderedColors;

      this.nib.globals.board = gamedatas.board;
      this.nib.globals.legalMoves = gamedatas.legalMoves;
      this.nib.globals.collections = gamedatas.collections;

      this.nib.selections.color = null;

      this.nib.managers.discs = new CardManager(this, {
        cardHeight: 60,
        cardWidth: 60,
        selectedCardClass: "nib_selectedDisc",
        getId: (card) => `disc-${card.row}${card.column}`,
        setupDiv: (card, div) => {
          const color = this.nib.info.colors[card.color_id];
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

      const boardElement = document.getElementById("nib_board");

      this.nib.stocks.board = new CardStock(
        this.nib.managers.discs,
        boardElement,
        {}
      );

      this.nib.stocks.board.onSelectionChange = (selection, lastChange) => {
        const confirmationBtn = document.getElementById("nib_confirmationBtn");
        const itemsCount = selection.length;
        const disc = lastChange;

        this.nib.selections.discs = selection;

        if (confirmationBtn) {
          confirmationBtn.remove();
        }

        if (
          this.nib.selections.color &&
          this.nib.selections.color != disc.color_id
        ) {
          if (itemsCount >= 2) {
            this.nib.stocks.board.unselectAll(true);
            this.nib.stocks.board.selectCard(disc, true);

            this.nib.selections.discs = [disc];
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
          this.nib.selections.color = disc.color_id;
        } else {
          this.nib.selections.color = null;
        }
      };

      let rowId = 0;
      let columnId = 0;

      this.nib.globals.board.forEach((row) => {
        row.forEach((color_id) => {
          const card = {
            row: rowId,
            column: columnId,
            color_id: color_id,
          };

          const isSelectable = this.nib.globals.legalMoves.some((disc) => {
            return disc.row == rowId && disc.column == columnId;
          });

          if (card.color_id) {
            this.nib.stocks.board.addCard(
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

      for (const player_id in this.nib.info.players) {
        const player = this.nib.info.players[player_id];

        const collectionsElement = document.getElementById("nib_collections");

        let order = player_id == this.player_id ? 1 : 3;
        if (this.isSpectator) {
          order = collectionsElement.childElementCount + 1;
        }

        const titleOrder = order === 1 ? -1 : 1;

        collectionsElement.innerHTML += `
          <div id="nib_collectionContainer:${player_id}"
          class="nib_collectionContainer" style="order: ${order};">
            <h3 id="nib_collectionTitle" class="nib_collectionTitle" style="color: #${player.color}; order: ${titleOrder};">${player.name}</h3>
            <div id="nib_collection:${player_id}" class="nib_collection"></div>
          </div>
        `;

        if (collectionsElement.childElementCount === 1) {
          collectionsElement.innerHTML += `<div id="nib_separators:${player_id}" class="nib_separators"></div>`;

          const orderedColors = this.nib.info.orderedColors;
          const separatorsElement = document.getElementById(
            `nib_separators:${player_id}`
          );

          orderedColors.forEach((color_id) => {
            const color = this.nib.info.colors[color_id];
            separatorsElement.innerHTML += `<div id="nib_separator:${player_id}-${color_id}" class="nib_separator" style="background-color: ${color}"></div>`;
          });

          orderedColors.forEach((color_id) => {
            const color = this.nib.info.colors[color_id];
            this.addTooltip(
              `nib_separator:${player_id}-${color_id}`,
              color,
              ""
            );
          });
        }
      }

      for (const player_id in this.nib.info.players) {
        this.nib.stocks[player_id] = {};

        const collectionElement = document.getElementById(
          `nib_collection:${player_id}`
        );

        this.nib.stocks[player_id].collection = new SlotStock(
          this.nib.managers.discs,
          collectionElement,
          {
            center: false,
            direction: "row",
            gap: "2px",
            wrap: "nowrap",
            slotsIds: [1, 2, 3, 4, 5, 6, 7, 8, 9],
            mapCardToSlot: (disc) => {
              return Number(disc.color_id);
            },
            sort: (disc, otherDisc) => {
              if (this.player_id == player_id) {
                return -1;
              }

              return 1;
            },
          }
        );

        const collectionContainer = document.getElementById(
          `nib_collectionContainer:${player_id}`
        );

        const collections = this.nib.globals.collections[player_id];
        for (const color_id in this.nib.info.colors) {
          const discs = collections?.[color_id];

          if (discs) {
            this.nib.stocks[player_id].collection.addCards(discs);
          }

          const slotElement = collectionElement.querySelector(
            `[data-slot-id="${color_id}"]`
          );

          slotElement.style.order = this.nib.info.orderedColors.findIndex(
            (id) => {
              return id == color_id;
            }
          );

          if (collectionContainer.style.order == 1) {
            slotElement.style.justifyContent = "flex-end";
          }
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

        this.nib.globals.legalMoves = legalMoves;

        if (this.isCurrentPlayerActive()) {
          this.nib.stocks.board.setSelectionMode("multiple", legalMoves);
        }
      }
    },

    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      if (stateName === "playerTurn") {
        this.nib.stocks.board.setSelectionMode("none");
        this.nib.selections.color = null;
        this.nib.selections.discs = [];
      }
    },

    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons: " + stateName);
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    performAction: function (action, args = {}) {
      args.clientVersion = this.nib.version;
      this.bgaPerformAction(action, args);
    },

    ///////////////////////////////////////////////////
    //// Player's action

    actTakeDiscs: function () {
      const discs = this.nib.selections.discs;
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

      this.nib.stocks[player_id].collection.addCard(disc);

      this.nib.selections.color = null;
    },

    // @Override
    format_string_recursive: function (log, args) {
      try {
        if (log && args && !args.processed) {
          args.processed = true;

          if (args.color_label && args.color_id) {
            const color_id = args.color_id;
            const color = this.nib.info.colors[color_id];
            const backgroundColor = color === "white" ? "black" : "white";

            args.color_label = `<span class="nib_color-log" style="color: ${color}; background-color: ${backgroundColor}">${args.color_label}</span>`
          }
        }
      } catch (e) {
        console.error(log, args, "Exception thrown", e.stack);
      }

      return this.inherited(arguments);
    },
  });
});
