const path = require("path");
const dotenv = require("dotenv");

dotenv.config({ path: path.resolve(__dirname, "../../../.env") });
dotenv.config({ path: path.resolve(__dirname, ".env") });

const TelegramBot = require(
  "node-telegram-bot-api"
);

const bot = new TelegramBot(
  process.env.BOT_TOKEN,
  {
    polling: true
  }
);

module.exports = bot;