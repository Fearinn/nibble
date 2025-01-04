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
  `${g_gamethemeurl}modules/bga-zoom.js`,
  `${g_gamethemeurl}modules/bga-cards.js`,
], function (dojo, declare) {
  return declare("bgagame.nibble", ebg.core.gamegui, {
    constructor: function () {
      console.log("nibble constructor");
    },

    setup: function (gamedatas) {
      console.log("Starting game setup");

      this.nib = {
        info: {},
        globals: {},
        stocks: {},
        selections: {
          color: null,
        },
        counts: gamedatas.counts,
        managers: {
          counters: {},
        },
        variants: {
          is13Colors: gamedatas.is13Colors,
          isHexagon: gamedatas.isHexagon,
        },
      };

      this.nib.info.boardSize = gamedatas.boardSize;
      this.nib.info.colors = gamedatas.colors_info;
      this.nib.info.darkColors = [1, 2, 3, 6, 8, 9, 13];

      this.nib.info.colorblindHelp = {
        1: "A",
        2: "B",
        3: "C",
        4: "D",
        5: "E",
        6: "F",
        7: "G",
        8: "H",
        9: "I",
        10: "J",
        11: "K",
        12: "L",
        13: "M",
      };

      this.nib.version = gamedatas.version;
      this.nib.info.players = gamedatas.players;
      this.nib.info.orderedColors = gamedatas.orderedColors;

      this.nib.globals.board = gamedatas.board;
      this.nib.globals.legalMoves = gamedatas.legalMoves;
      this.nib.globals.collections = gamedatas.collections;
      this.nib.globals.playersNoInstaWin = gamedatas.playersNoInstaWin;

      this.nib.managers.zoom = new ZoomManager({
        element: document.getElementById("nib_gameArea"),
        localStorageZoomKey: "nibble-zoom",
        zoomControls: {
          color: "black",
        },
        zoomLevels: [0.375, 0.5, 0.75, 1, 1.25, 1.5],
        smooth: true,
        onZoomChange: (zoom) => {
          const winConWarnElement = document.getElementById("nib_winConWarn");

          if (zoom >= 1) {
            winConWarnElement.style.removeProperty("transform");
            winConWarnElement.style.removeProperty("margin");
            return;
          }

          winConWarnElement.style.transform = `scale(${1 / zoom})`;

          let margin = 8;

          if (zoom <= 0.5) {
            margin = 24;
          }

          if (zoom <= 0.375) {
            margin = 44;
          }

          winConWarnElement.style.margin = `${margin}px 0`;
        },
      });

      this.nib.managers.discs = new CardManager(this, {
        cardHeight: 60,
        cardWidth: 60,
        selectedCardClass: "nib_selectedDisc",
        getId: (card) => `disc-row_${card.row}-col_${card.column}`,
        setupDiv: (card, div) => {
          const color_id = card.color_id;
          const color = this.nib.info.colors[color_id];

          div.classList.add("nib_disc");
          div.style.gridRow = card.row + 1;
          div.style.gridColumn = card.column + 1;
          div.style.position = "relative";

          div.style.backgroundColor = color.name;
          this.addTooltip(
            div.id,
            `${_(color.tr_name)} - (${card.column}, ${card.row})`,
            ""
          );

          const colorblindHelp = document.createElement("span");
          colorblindHelp.textContent = this.nib.info.colorblindHelp[color_id];
          colorblindHelp.classList.add("nib_colorblindHelp");
          colorblindHelp.style.color = this.nib.info.darkColors.includes(
            color_id
          )
            ? "white"
            : "black";

          div.appendChild(colorblindHelp);

          if (this.nib.variants.isHexagon) {
            if (card.row % 2 === 0) {
              div.style.left = "50%";
            }
          }
        },
        setupFrontDiv: (card, div) => {},
        setupBackDiv: (card, div) => {},
      });

      this.updateWinConWarn(this.nib.globals.playersNoInstaWin);

      const html = document.querySelector("html");

      if (this.nib.variants.is13Colors) {
        html.classList.add("nib_13colors");
      }

      html.style.setProperty("--boardSize", this.nib.info.boardSize);

      /* BOARD */
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

        const playerPanel = this.getPlayerPanelElement(player_id);
        playerPanel.innerHTML += `
          <div id="nib_counters:${player_id}" class="nib_counters"></div>
        `;

        const countersElement = document.getElementById(
          `nib_counters:${player_id}`
        );

        this.nib.managers.counters[player_id] = {};

        const orderedColors = this.nib.info.orderedColors;
        orderedColors.forEach((color_id) => {
          this.nib.managers.counters[player_id][color_id] = new ebg.counter();

          const color = this.nib.info.colors[color_id];
          const colorblindHelp = this.nib.info.colorblindHelp[color_id];
          const colorblindHelpColor = this.nib.info.darkColors.includes(
            color_id
          )
            ? "white"
            : "black";

          countersElement.innerHTML += `<div id="nib_counter:${player_id}-${color_id}" class="nib_counter">
            <div class="nib_disc-counter nib_disc" style="background-color: ${color.name}">
              <span class="nib_colorblindHelp" style="color: ${colorblindHelpColor}">${colorblindHelp}</span>
            </div>
            <span id="nib_count:${player_id}-${color_id}" class="nib_count">0</span>
          </div>`;
        });

        const counters = this.nib.managers.counters[player_id];
        for (const color_id in counters) {
          const counter = counters[color_id];
          counter.create(`nib_count:${player_id}-${color_id}`);

          const count = this.nib.counts[player_id][color_id] || 0;
          counter.setValue(count);
        }

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
          collectionsElement.innerHTML += `<div id="nib_separators" class="nib_separators"></div>`;

          const orderedColors = this.nib.info.orderedColors;
          const separatorsElement = document.getElementById(`nib_separators`);

          orderedColors.forEach((color_id) => {
            const color = this.nib.info.colors[color_id];
            const colorblindHelp = this.nib.info.colorblindHelp[color_id];
            const colorblindHelpColor = this.nib.info.darkColors.includes(
              color_id
            )
              ? "white"
              : "black";

            separatorsElement.innerHTML += `<div id="nib_separator-${color_id}" class="nib_separator" style="background-color: ${color.name}">
              <span class="nib_colorblindHelp" style="color: ${colorblindHelpColor}">${colorblindHelp}</span>
            </div>`;
          });
        }
      }

      const orderedColors = this.nib.info.orderedColors;
      orderedColors.forEach((color_id) => {
        const color = this.nib.info.colors[color_id];
        this.addTooltip(`nib_separator-${color_id}`, _(color.tr_name), "");
      });

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
            slotsIds: Object.keys(this.nib.info.colors),
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

    updateWinConWarn: function (playersNoInstaWin) {
      const winConWarnElement = document.getElementById("nib_winConWarn");
      warn = _("Both players can still get an instant win");

      if (playersNoInstaWin.length === 2) {
        warn = _(
          "Both players can no longer get an instant win. Go for the majorities!"
        );
        winConWarnElement.style.backgroundColor = "yellow";
      }

      if (playersNoInstaWin.length === 1) {
        if (playersNoInstaWin.includes(this.player_id)) {
          warn = _(
            "You can no longer get an instant win. Go for the majorities!"
          );
          winConWarnElement.style.backgroundColor = "red";
        } else {
          warn = _("Your opponent can no longer get an instant win");
          winConWarnElement.style.backgroundColor = "green";
        }
      }

      winConWarnElement.textContent = warn;
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
      dojo.subscribe("updateWinConWarn", this, "notif_updateWinConWarn");
      this.notifqueue.setSynchronous("takeDisc", 500);
    },

    notif_takeDisc: function (notif) {
      const player_id = notif.args.player_id;
      const disc = notif.args.disc;
      const color_id = notif.args.color_id;

      this.nib.stocks[player_id].collection.addCard(disc);
      this.nib.managers.counters[player_id][color_id].incValue(1);

      this.nib.selections.color = null;
    },

    notif_updateWinConWarn: function (notif) {
      const playersNoInstaWin = notif.args.playersNoInstaWin;
      this.updateWinConWarn(playersNoInstaWin);
    },

    // @Override
    format_string_recursive: function (log, args) {
      try {
        if (log && args && !args.processed) {
          args.processed = true;

          if (args.color_label && args.color_id) {
            const color_id = args.color_id;
            const color = this.nib.info.colors[color_id];
            const backgroundColor = this.nib.info.darkColors.includes(color_id)
              ? "white"
              : "black";

            args.color_label = `<span class="nib_color-log" style="color: ${
              color.name
            }; background-color: ${backgroundColor}">${_(
              args.color_label
            )}</span>`;
          }

          if (args.win_condition) {
            const winCondition = this.format_string_recursive(
              _(args.win_condition.log),
              args.win_condition.args,
            );

            args.win_condition = `<span class="nib_highlight-log">${winCondition}</span>`;
          }
        }
      } catch (e) {
        console.error(log, args, "Exception thrown", e.stack);
      }

      return this.inherited(arguments);
    },
  });
});
