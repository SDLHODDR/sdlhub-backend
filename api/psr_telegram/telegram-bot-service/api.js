const express = require("express");

const bot = require(
  "./telegramClient"
);

const app = express();

app.use(express.json());

/*
|--------------------------------------------------------------------------
| Send Individual DM
|--------------------------------------------------------------------------
*/

app.post(
  "/send-dm",
  async (req, res) => {

    try {

      const {
        telegram_chat_id,
        message
      } = req.body;

      if (
        !telegram_chat_id ||
        !message
      ) {
        return res.status(400).json({
          success: false,
          message:
            "telegram_chat_id and message required"
        });
      }

      const response =
        await bot.sendMessage(
          telegram_chat_id,
          message
        );

      return res.json({
        success: true,
        data: response
      });

    } catch (error) {

      console.log(error);

      return res.status(500).json({
        success: false,
        error: error.message
      });

    }

  }
);

/*
|--------------------------------------------------------------------------
| Send Group Message
|--------------------------------------------------------------------------
*/

app.post(
  "/send-group-message",
  async (req, res) => {

    try {

      const {
        telegram_group_id,
        message
      } = req.body;

      if (
        !telegram_group_id ||
        !message
      ) {
        return res.status(400).json({
          success: false,
          message:
            "telegram_group_id and message required"
        });
      }

      const response =
        await bot.sendMessage(
          telegram_group_id,
          message
        );

      return res.json({
        success: true,
        data: response
      });

    } catch (error) {

      console.log(error);

      return res.status(500).json({
        success: false,
        error: error.message
      });

    }

  }
);

const configuredPort = Number.parseInt(process.env.TELEGRAM_PORT, 10);
const port = Number.isInteger(configuredPort) ? configuredPort : 5003;

app.listen(
  port,
  () => {
    console.log(`Telegram API Running on ${port}`);
  }
);